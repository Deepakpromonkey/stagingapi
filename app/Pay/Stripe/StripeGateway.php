<?php

namespace App\Pay\Stripe;

use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeGateway implements Gateway
{
    private ?QuietStripe $client = null;   // a StripeClient, notice-tolerant

    public function livemode(): bool
    {
        return str_starts_with((string) config('dtpay.stripe.secret'), 'sk_live_')
            || str_starts_with((string) config('dtpay.stripe.secret'), 'rk_live_');
    }

    public function publishableKey(): ?string
    {
        return config('dtpay.stripe.key');
    }

    public function createCustomer(string $name, ?string $email, array $metadata): string
    {
        return $this->stripe()->customers->create(array_filter([
            'name' => $name,
            'email' => $email,
            'metadata' => $metadata,
        ]))->id;
    }

    public function listPaymentMethods(string $customerId): array
    {
        $methods = [];

        foreach (['card', 'us_bank_account'] as $type) {
            foreach ($this->stripe()->paymentMethods->all(['customer' => $customerId, 'type' => $type, 'limit' => 50])->data as $pm) {
                $methods[] = $this->describe($pm);
            }
        }

        return $methods;
    }

    public function retrievePaymentMethod(string $paymentMethodId): array
    {
        $pm = $this->stripe()->paymentMethods->retrieve($paymentMethodId);

        return $this->describe($pm) + ['customer' => $pm->customer];
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->stripe()->paymentMethods->detach($paymentMethodId);
    }

    public function createSetupIntent(string $customerId, array $methodTypes): string
    {
        $params = [
            'customer' => $customerId,
            'payment_method_types' => $methodTypes,
            'usage' => 'off_session',
        ];

        if (in_array('us_bank_account', $methodTypes, true)) {
            // Instant verification through Financial Connections, falling back
            // to micro-deposits for banks it cannot reach.
            $params['payment_method_options'] = [
                'us_bank_account' => ['verification_method' => 'automatic'],
            ];
        }

        return $this->stripe()->setupIntents->create($params)->client_secret;
    }

    public function createPaymentIntent(array $params, string $idempotencyKey): array
    {
        try {
            $pi = $this->stripe()->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey]);
        } catch (\Stripe\Exception\CardException $e) {
            // A decline still creates (and returns) the PaymentIntent.
            $pi = $e->getError()?->payment_intent;

            if (! $pi) {
                return ['id' => '', 'status' => 'requires_payment_method', 'client_secret' => null, 'charge_id' => null, 'error' => $e->getMessage()];
            }

            return $this->intent($pi) + ['error' => $e->getMessage()];
        }

        return $this->intent($pi);
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->intent($this->stripe()->paymentIntents->retrieve($paymentIntentId));
    }

    public function createTransfer(int $amountCents, string $currency, string $destination, ?string $sourceCharge, array $metadata, string $idempotencyKey): string
    {
        return $this->stripe()->transfers->create(array_filter([
            'amount' => $amountCents,
            'currency' => $currency,
            'destination' => $destination,
            // Tie the transfer to the charge that funded it, so it draws on
            // that money even before it settles into the available balance.
            'source_transaction' => $sourceCharge,
            'metadata' => $metadata,
        ]), ['idempotency_key' => $idempotencyKey])->id;
    }

    public function createRefund(string $paymentIntentId, array $metadata, string $idempotencyKey): array
    {
        $refund = $this->stripe()->refunds->create([
            'payment_intent' => $paymentIntentId,
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey]);

        return ['id' => $refund->id, 'status' => $refund->status];
    }

    /*
     * Carriers are Accounts v2 recipients: they only receive transfers from
     * DollarTraq (separate charges and transfers), onboard on Stripe's hosted
     * pages and see payouts in the Express dashboard. Stripe no longer allows
     * Accounts v1 creation for new Connect integrations.
     */
    public function createRecipientAccount(string $carrierDot, ?string $email, ?string $businessName): string
    {
        return $this->stripe()->v2->core->accounts->create(array_filter([
            'contact_email' => $email,
            'display_name' => $businessName ?: 'Carrier DOT '.$carrierDot,
            'dashboard' => 'express',
            'identity' => ['country' => 'us', 'entity_type' => 'company'],
            'configuration' => [
                'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => ['stripe_transfers' => ['requested' => true]],
                    ],
                ],
            ],
            // An Express dashboard requires the platform to hold these.
            'defaults' => ['responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application']],
            'metadata' => ['carrier_dot' => $carrierDot, 'source' => 'dtpay'],
        ]))->id;
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->stripe()->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'configurations' => ['recipient'],
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                ],
            ],
        ])->url;
    }

    public function retrieveAccount(string $accountId): array
    {
        $account = $this->stripe()->v2->core->accounts->retrieve($accountId, ['include' => ['configuration.recipient', 'requirements']])->toArray();

        $transfers = $account['configuration']['recipient']['capabilities']['stripe_balance']['stripe_transfers']['status'] ?? null;
        $due = $account['requirements']['entries'] ?? [];

        return [
            'details_submitted' => $due === [] || $transfers === 'active',
            'payouts_enabled' => $transfers === 'active',
            'transfers_active' => $transfers === 'active',
        ];
    }

    public function webhookEvent(string $payload, string $signature): array
    {
        $secret = config('dtpay.stripe.webhook_secret');

        if (! $secret) {
            throw new RuntimeException('DT Pay webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $signature, $secret)->toArray();
    }

    private function stripe(): QuietStripe
    {
        $secret = config('dtpay.stripe.secret');

        if (! $secret) {
            throw new PaymentsUnavailable('DT Pay is not configured.');
        }

        if ($this->livemode() && ! config('dtpay.allow_live')) {
            // A live key moves real money; it has to be switched on on purpose.
            throw new PaymentsUnavailable('DT Pay refuses a live Stripe key unless DTPAY_ALLOW_LIVE=true.');
        }

        return $this->client ??= new QuietStripe(new StripeClient($secret));
    }

    private function describe($pm): array
    {
        if ($pm->type === 'card') {
            $brand = strtoupper((string) ($pm->card->display_brand ?? $pm->card->brand));

            return [
                'id' => $pm->id,
                'type' => 'card',
                'label' => trim("{$brand} ending {$pm->card->last4}"),
                'brand' => $brand,
                'last4' => $pm->card->last4,
                'exp' => sprintf('%02d/%02d', $pm->card->exp_month, $pm->card->exp_year % 100),
                'bank' => null,
            ];
        }

        return [
            'id' => $pm->id,
            'type' => 'us_bank_account',
            'label' => trim(($pm->us_bank_account->bank_name ?? 'Bank').' ending '.$pm->us_bank_account->last4),
            'brand' => null,
            'last4' => $pm->us_bank_account->last4,
            'exp' => null,
            'bank' => $pm->us_bank_account->bank_name ?? null,
        ];
    }

    private function intent($pi): array
    {
        $charge = $pi->latest_charge ?? null;

        return [
            'id' => $pi->id,
            'status' => $pi->status,
            'client_secret' => $pi->client_secret,
            'charge_id' => is_string($charge) ? $charge : ($charge->id ?? null),
            'error' => $pi->last_payment_error->message ?? null,
        ];
    }
}
