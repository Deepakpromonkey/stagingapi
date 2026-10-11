<?php

namespace Tests\Feature\DtPay;

use App\Pay\Stripe\Gateway;
use RuntimeException;

/**
 * Stripe, in memory. Records every money-moving call so tests can assert
 * exactly what would have been charged, transferred and refunded.
 */
class FakeGateway implements Gateway
{
    public bool $live = false;

    /** pm id => [type, customer] */
    public array $methods = [];

    /** Status the next PaymentIntent comes back with, per method type. */
    public array $nextStatus = ['card' => 'succeeded', 'us_bank_account' => 'processing'];

    public array $intents = [];
    public array $transfers = [];
    public array $refunds = [];
    public array $accounts = [];
    public bool $failTransfers = false;
    public int $customers = 0;

    /** idempotency key => result, as Stripe does. */
    private array $idempotent = [];

    public function livemode(): bool
    {
        return $this->live;
    }

    public function publishableKey(): ?string
    {
        return 'pk_test_fake';
    }

    public function createCustomer(string $name, ?string $email, array $metadata): string
    {
        return 'cus_fake_'.(++$this->customers);
    }

    public function addMethod(string $customer, string $type = 'card'): string
    {
        $id = 'pm_fake_'.count($this->methods).'_'.$type;
        $this->methods[$id] = ['type' => $type, 'customer' => $customer];

        return $id;
    }

    public function listPaymentMethods(string $customerId): array
    {
        $out = [];

        foreach ($this->methods as $id => $m) {
            if ($m['customer'] === $customerId) {
                $out[] = ['id' => $id, 'type' => $m['type'], 'label' => $m['type'] === 'card' ? 'VISA ···· 4242' : 'Test Bank ···· 6789', 'brand' => null, 'last4' => '4242', 'exp' => null, 'bank' => null];
            }
        }

        return $out;
    }

    public function retrievePaymentMethod(string $paymentMethodId): array
    {
        $m = $this->methods[$paymentMethodId] ?? throw new RuntimeException('No such payment method');

        return ['id' => $paymentMethodId, 'type' => $m['type'], 'label' => $m['type'] === 'card' ? 'VISA ···· 4242' : 'Test Bank ···· 6789', 'customer' => $m['customer']];
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        unset($this->methods[$paymentMethodId]);
    }

    public function createSetupIntent(string $customerId, array $methodTypes): string
    {
        return 'seti_fake_secret';
    }

    public function createPaymentIntent(array $params, string $idempotencyKey): array
    {
        if (isset($this->idempotent[$idempotencyKey])) {
            return $this->idempotent[$idempotencyKey];
        }

        $type = $params['payment_method_types'][0] ?? 'card';
        $id = 'pi_fake_'.(count($this->intents) + 1);
        $status = isset($params['confirm']) ? $this->nextStatus[$type] : 'requires_payment_method';

        $this->intents[$id] = $params + ['status' => $status];

        return $this->idempotent[$idempotencyKey] = [
            'id' => $id,
            'status' => $status,
            'client_secret' => $id.'_secret',
            'charge_id' => in_array($status, ['succeeded', 'processing'], true) ? 'ch_'.$id : null,
            'error' => $status === 'requires_payment_method' && isset($params['confirm']) ? 'Your card was declined.' : null,
        ];
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        $pi = $this->intents[$paymentIntentId];

        return ['id' => $paymentIntentId, 'status' => $pi['status'], 'client_secret' => null, 'charge_id' => 'ch_'.$paymentIntentId, 'error' => null];
    }

    public function settle(string $paymentIntentId, string $status = 'succeeded'): void
    {
        $this->intents[$paymentIntentId]['status'] = $status;
    }

    public function createTransfer(int $amountCents, string $currency, string $destination, ?string $sourceCharge, array $metadata, string $idempotencyKey): string
    {
        if ($this->failTransfers) {
            throw new RuntimeException('Insufficient funds in Stripe account.');
        }

        if (isset($this->idempotent[$idempotencyKey])) {
            return $this->idempotent[$idempotencyKey];
        }

        $this->transfers[] = compact('amountCents', 'currency', 'destination', 'sourceCharge', 'metadata');

        return $this->idempotent[$idempotencyKey] = 'tr_fake_'.count($this->transfers);
    }

    public function createRefund(string $paymentIntentId, array $metadata, string $idempotencyKey): array
    {
        if (isset($this->idempotent[$idempotencyKey])) {
            return $this->idempotent[$idempotencyKey];
        }

        $this->refunds[] = $paymentIntentId;

        return $this->idempotent[$idempotencyKey] = ['id' => 're_fake_'.count($this->refunds), 'status' => 'succeeded'];
    }

    public function createRecipientAccount(string $carrierDot, ?string $email, ?string $businessName): string
    {
        $id = 'acct_fake_'.$carrierDot;
        $this->accounts[$id] = ['details_submitted' => false, 'payouts_enabled' => false, 'transfers_active' => false];

        return $id;
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return 'https://connect.stripe.test/setup/'.$accountId;
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->accounts[$accountId] ?? ['details_submitted' => false, 'payouts_enabled' => false, 'transfers_active' => false];
    }

    public function webhookEvent(string $payload, string $signature): array
    {
        if ($signature !== 'valid') {
            throw new RuntimeException('Bad signature');
        }

        return json_decode($payload, true);
    }
}
