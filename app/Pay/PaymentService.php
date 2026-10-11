<?php

namespace App\Pay;

use App\Models\Company;
use App\Models\User;
use App\Pay\Models\Customer;
use App\Pay\Models\Dispute;
use App\Pay\Models\Event;
use App\Pay\Models\Payment;
use App\Pay\Models\PayoutAccount;
use App\Pay\Stripe\Gateway;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every DT Pay state change goes through here.
 *
 * Money only moves out (a transfer to the carrier, a refund to the payer)
 * from a funded payment, under a row lock, with the status flipped to a
 * transient 'releasing' / 'refunding' before Stripe is called and an
 * idempotency key on the call. Two clicks, two tabs or a retried request can
 * therefore never move the same money twice.
 */
class PaymentService
{
    public function __construct(private Gateway $stripe)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Creating a payment
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{source: string, carrier_dot: string, carrier_name?: ?string, carrier_mc?: ?string, amount: float|string, load_number?: ?string, origin?: ?string, destination?: ?string, pickup_date?: ?string, delivery_date?: ?string, memo?: ?string, shipment_id?: ?int, payer_name?: ?string, payer_company?: ?string, payer_email?: ?string, payer_phone?: ?string}  $data
     */
    public function create(array $data, ?User $user, ?UploadedFile $rateConfirmation = null): Payment
    {
        $guest = $data['source'] === Payment::SOURCE_GUEST;
        $quote = Fees::quote(Fees::dollarsToCents($data['amount']), 'us_bank_account', $guest);

        return DB::transaction(function () use ($data, $user, $guest, $quote, $rateConfirmation) {
            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'reference' => 'DTP-'.Str::upper(Str::random(8)),   // replaced below
                'company_id' => $guest ? null : $user?->company_id,
                'created_by' => $user?->id,
                'source' => $data['source'],
                'shipment_id' => $data['shipment_id'] ?? null,
                'guest_token' => $guest ? Str::random(48) : null,
                'payer_name' => $data['payer_name'] ?? null,
                'payer_company' => $data['payer_company'] ?? null,
                'payer_email' => $data['payer_email'] ?? null,
                'payer_phone' => $data['payer_phone'] ?? null,
                'carrier_dot' => (string) $data['carrier_dot'],
                'carrier_name' => $data['carrier_name'] ?? null,
                'carrier_mc' => $data['carrier_mc'] ?? null,
                'load_number' => $data['load_number'] ?? null,
                'origin' => $data['origin'] ?? null,
                'destination' => $data['destination'] ?? null,
                'pickup_date' => $data['pickup_date'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'memo' => $data['memo'] ?? null,
                'amount_cents' => $quote['amount_cents'],
                'platform_fee_cents' => $quote['platform_fee_cents'],
                'card_fee_cents' => 0,
                'total_cents' => $quote['total_cents'],
                'platform_fee_pct' => $quote['platform_fee_pct'],
                'currency' => config('dtpay.currency'),
                'livemode' => $this->stripe->livemode(),
                'status' => Payment::DRAFT,
            ]);

            $payment->reference = sprintf('DTP-%06d', $payment->id);

            if ($rateConfirmation) {
                $payment->rate_confirmation_path = $this->store($rateConfirmation, $payment, 'rate-confirmation');
            }

            $payment->save();

            $this->log($payment, 'created', 'Payment created', $this->money($payment->amount_cents).' to '.($payment->carrier_name ?: 'DOT '.$payment->carrier_dot), $guest ? 'guest' : 'broker', $user?->id);

            return $payment;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Paying (brokers, with a saved method)
    |--------------------------------------------------------------------------
    */

    /** The company's Stripe customer in the current mode, created on first use. */
    public function customerFor(Company $company, ?User $user = null): string
    {
        $livemode = $this->stripe->livemode();

        $existing = Customer::where('company_id', $company->id)->where('livemode', $livemode)->value('stripe_customer_id');

        if ($existing) {
            return $existing;
        }

        $id = $this->stripe->createCustomer(
            (string) ($company->company_name ?? 'Company '.$company->id),
            $user?->email,
            ['company_id' => (string) $company->id, 'source' => 'dtpay'],
        );

        Customer::create(['company_id' => $company->id, 'livemode' => $livemode, 'stripe_customer_id' => $id]);

        return $id;
    }

    /**
     * Charge a saved card or bank account. Returns the payment plus, when a
     * card needs 3-D Secure, the client secret the browser must finish it
     * with.
     *
     * @return array{payment: Payment, client_secret: ?string, error: ?string}
     */
    public function pay(Payment $payment, User $user, string $paymentMethodId, ?string $ip, ?string $userAgent): array
    {
        if (! $payment->payable()) {
            throw new PaymentRuleViolation('This payment has already been paid or closed.');
        }

        $customer = $this->customerFor($user->company, $user);
        $method = $this->stripe->retrievePaymentMethod($paymentMethodId);

        if (($method['customer'] ?? null) !== $customer) {
            throw new PaymentRuleViolation('That payment method does not belong to your company.');
        }

        $quote = Fees::quote($payment->amount_cents, $method['type']);

        $payment->fill([
            'method_type' => $method['type'],
            'stripe_payment_method_id' => $method['id'],
            'method_label' => $method['label'],
            'platform_fee_cents' => $quote['platform_fee_cents'],
            'card_fee_cents' => $quote['card_fee_cents'],
            'total_cents' => $quote['total_cents'],
            'failure_reason' => null,
        ])->save();

        $params = [
            'amount' => $payment->total_cents,
            'currency' => $payment->currency,
            'customer' => $customer,
            'payment_method' => $method['id'],
            'payment_method_types' => [$method['type']],
            'confirm' => true,
            'description' => "DT Pay {$payment->reference} — load ".($payment->load_number ?: '—'),
            'metadata' => ['dtpay_payment' => $payment->uuid, 'reference' => $payment->reference],
            'transfer_group' => $payment->reference,
        ];

        if ($method['type'] === 'us_bank_account') {
            // ACH needs the payer's authorisation recorded on the debit.
            $params['mandate_data'] = ['customer_acceptance' => [
                'type' => 'online',
                'online' => ['ip_address' => $ip ?: '0.0.0.0', 'user_agent' => $userAgent ?: 'DollarTraq'],
            ]];
        } else {
            $params['return_url'] = config('dtpay.frontend_url').'/dt-pay/transactions/view/'.$payment->uuid;
        }

        $attempt = $payment->events()->where('type', 'pay_attempt')->count() + 1;

        $this->log($payment, 'pay_attempt', 'Payment submitted', $method['label'].' · '.$this->money($payment->total_cents), 'broker', $user->id);

        $intent = $this->stripe->createPaymentIntent($params, "dtpay-pay-{$payment->uuid}-{$attempt}");

        $this->applyIntent($payment, $intent, 'broker', $user->id);

        return [
            'payment' => $payment->fresh(),
            'client_secret' => $intent['status'] === 'requires_action' ? $intent['client_secret'] : null,
            'error' => $intent['error'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Paying (Guest Pay, through the Payment Element)
    |--------------------------------------------------------------------------
    */

    /** @return array{client_secret: string, quote: array} */
    public function guestIntent(Payment $payment, string $methodType): array
    {
        if (! $payment->payable()) {
            throw new PaymentRuleViolation('This payment has already been paid or closed.');
        }

        $quote = Fees::quote($payment->amount_cents, $methodType, true);

        $payment->fill([
            'method_type' => $methodType,
            'platform_fee_cents' => $quote['platform_fee_cents'],
            'card_fee_cents' => $quote['card_fee_cents'],
            'total_cents' => $quote['total_cents'],
        ])->save();

        // Same method and total -> Stripe hands back the same intent.
        $intent = $this->stripe->createPaymentIntent([
            'amount' => $payment->total_cents,
            'currency' => $payment->currency,
            'payment_method_types' => [$methodType],
            'receipt_email' => $payment->payer_email,
            'description' => "DT Pay {$payment->reference} (guest)",
            'metadata' => ['dtpay_payment' => $payment->uuid, 'reference' => $payment->reference],
            'transfer_group' => $payment->reference,
        ], "dtpay-guest-{$payment->uuid}-{$methodType}-{$payment->total_cents}");

        $payment->forceFill(['stripe_payment_intent_id' => $intent['id']])->save();

        return ['client_secret' => (string) $intent['client_secret'], 'quote' => $quote];
    }

    /*
    |--------------------------------------------------------------------------
    | Following Stripe
    |--------------------------------------------------------------------------
    */

    /**
     * Move a payment to match its PaymentIntent. Called after paying, from
     * webhooks, and on a manual status check. Never moves a payment that
     * has already been released or refunded.
     */
    public function applyIntent(Payment $payment, array $intent, string $actorType = 'stripe', ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payment, $intent, $actorType, $actorId) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (in_array($payment->status, [Payment::RELEASING, Payment::RELEASED, Payment::REFUNDING, Payment::REFUNDED, Payment::CANCELLED], true)) {
                return $payment;
            }

            $payment->stripe_payment_intent_id = $intent['id'] ?: $payment->stripe_payment_intent_id;
            $payment->stripe_charge_id = $intent['charge_id'] ?? $payment->stripe_charge_id;

            $before = $payment->status;

            switch ($intent['status']) {
                case 'succeeded':
                    $payment->status = Payment::FUNDED;
                    $payment->funded_at ??= now();
                    $payment->failure_reason = null;
                    if ($before !== Payment::FUNDED) {
                        $this->log($payment, 'funded', 'Funds received and held', $this->money($payment->total_cents).' secured · ready to release', $actorType, $actorId);
                    }
                    break;

                case 'processing':
                    $payment->status = Payment::PROCESSING;
                    if ($before !== Payment::PROCESSING) {
                        $this->log($payment, 'processing', $payment->method_type === 'us_bank_account' ? 'Bank debit initiated' : 'Payment processing', $payment->method_type === 'us_bank_account' ? 'ACH clears in 1–2 business days' : null, $actorType, $actorId);
                    }
                    break;

                case 'requires_action':
                    $payment->status = Payment::PROCESSING;
                    if ($before !== Payment::PROCESSING) {
                        $this->log($payment, 'requires_action', 'Waiting for card authentication', null, $actorType, $actorId);
                    }
                    break;

                case 'requires_payment_method':
                case 'canceled':
                    if ($before !== Payment::DRAFT) {
                        $payment->status = Payment::FAILED;
                        $payment->failure_reason = $intent['error'] ?? 'The payment did not go through.';
                        $this->log($payment, 'failed', 'Payment failed', $payment->failure_reason, $actorType, $actorId);
                    }
                    break;
            }

            $payment->save();

            return $payment;
        });
    }

    /** Ask Stripe for the current state (the "refresh status" button). */
    public function sync(Payment $payment): Payment
    {
        if (! $payment->stripe_payment_intent_id) {
            return $payment;
        }

        return $this->applyIntent($payment, $this->stripe->retrievePaymentIntent($payment->stripe_payment_intent_id));
    }

    /*
    |--------------------------------------------------------------------------
    | Release, refund, hold, cancel
    |--------------------------------------------------------------------------
    */

    public function release(Payment $payment, string $actorType, ?int $actorId): Payment
    {
        $account = PayoutAccount::where('carrier_dot', $payment->carrier_dot)->where('livemode', $payment->livemode)->first();

        // The carrier may have finished Stripe's setup since we last looked;
        // ask Stripe rather than turn the release away on a stale flag.
        if ($account && ! $account->ready()) {
            try {
                $account = $this->refreshPayoutAccount($account);
            } catch (Throwable $e) {
                Log::warning("[DTPay] could not refresh payout account {$account->stripe_account_id}: ".$e->getMessage());
            }
        }

        $payment = DB::transaction(function () use ($payment, $account) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status !== Payment::FUNDED) {
                throw new PaymentRuleViolation(match ($locked->status) {
                    Payment::RELEASED, Payment::RELEASING => 'This payment has already been released.',
                    Payment::PROCESSING => 'The funds have not cleared yet. Release is possible once they do.',
                    Payment::REFUNDED, Payment::REFUNDING => 'This payment was refunded.',
                    default => 'Only a funded payment can be released.',
                });
            }

            if ($locked->on_hold) {
                throw new PaymentRuleViolation('This payment is on hold. Take it off hold to release it.');
            }

            if ($locked->openDispute()) {
                throw new PaymentRuleViolation('This payment has an open dispute. Resolve it before releasing.');
            }

            if (! $account || ! $account->ready()) {
                throw new PaymentRuleViolation('The carrier has not finished setting up payouts yet. Send them the payout setup link.');
            }

            $locked->status = Payment::RELEASING;
            $locked->save();

            return $locked;
        });

        try {
            // The carrier receives the load amount in full - never the fees.
            $transferId = $this->stripe->createTransfer(
                $payment->amount_cents,
                $payment->currency,
                $account->stripe_account_id,
                $payment->stripe_charge_id,
                ['dtpay_payment' => $payment->uuid, 'reference' => $payment->reference],
                "dtpay-release-{$payment->uuid}",
            );
        } catch (Throwable $e) {
            $payment->forceFill(['status' => Payment::FUNDED])->save();
            $this->log($payment, 'release_failed', 'Release failed', $e->getMessage(), $actorType, $actorId);
            Log::error("[DTPay] release {$payment->reference} failed: ".$e->getMessage());

            throw new PaymentRuleViolation('Stripe could not send the transfer: '.$e->getMessage());
        }

        $payment->forceFill([
            'status' => Payment::RELEASED,
            'stripe_transfer_id' => $transferId,
            'released_at' => now(),
            'released_by' => $actorType === 'broker' ? $actorId : null,
        ])->save();

        $this->log($payment, 'released', 'Released to carrier', $this->money($payment->amount_cents).' sent to '.($payment->carrier_name ?: 'DOT '.$payment->carrier_dot), $actorType, $actorId, ['transfer' => $transferId]);

        return $payment;
    }

    public function refund(Payment $payment, string $actorType, ?int $actorId, ?string $reason = null): Payment
    {
        $payment = DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status !== Payment::FUNDED) {
                throw new PaymentRuleViolation(match ($locked->status) {
                    Payment::RELEASED, Payment::RELEASING => 'This payment was already released to the carrier and cannot be refunded here.',
                    Payment::REFUNDED, Payment::REFUNDING => 'This payment has already been refunded.',
                    Payment::PROCESSING => 'The funds have not cleared yet. Refund is possible once they do.',
                    default => 'Only a funded payment can be refunded.',
                });
            }

            $locked->status = Payment::REFUNDING;
            $locked->save();

            return $locked;
        });

        try {
            $refund = $this->stripe->createRefund(
                $payment->stripe_payment_intent_id,
                ['dtpay_payment' => $payment->uuid, 'reference' => $payment->reference],
                "dtpay-refund-{$payment->uuid}",
            );
        } catch (Throwable $e) {
            $payment->forceFill(['status' => Payment::FUNDED])->save();
            $this->log($payment, 'refund_failed', 'Refund failed', $e->getMessage(), $actorType, $actorId);

            throw new PaymentRuleViolation('Stripe could not refund the payment: '.$e->getMessage());
        }

        $payment->forceFill([
            'status' => Payment::REFUNDED,
            'stripe_refund_id' => $refund['id'],
            'refunded_at' => now(),
            'refunded_by' => $actorType === 'broker' ? $actorId : null,
        ])->save();

        $this->log($payment, 'refunded', 'Refunded to payer', $this->money($payment->total_cents).($reason ? ' · '.$reason : ''), $actorType, $actorId, ['refund' => $refund['id']]);

        return $payment;
    }

    public function setHold(Payment $payment, bool $hold, string $actorType, ?int $actorId, ?string $note = null): Payment
    {
        if (! in_array($payment->status, [Payment::PROCESSING, Payment::FUNDED], true)) {
            throw new PaymentRuleViolation('Only a payment that is processing or funded can be put on hold.');
        }

        if ($payment->on_hold === $hold) {
            return $payment;
        }

        $payment->forceFill(['on_hold' => $hold])->save();

        $this->log($payment, $hold ? 'held' : 'unheld', $hold ? 'Put on hold' : 'Taken off hold', $note, $actorType, $actorId);

        return $payment;
    }

    public function cancel(Payment $payment, string $actorType, ?int $actorId): Payment
    {
        if (! $payment->payable()) {
            throw new PaymentRuleViolation('Only an unpaid payment can be cancelled. A funded payment is refunded instead.');
        }

        $payment->forceFill(['status' => Payment::CANCELLED])->save();

        $this->log($payment, 'cancelled', 'Payment cancelled', null, $actorType, $actorId);

        return $payment;
    }

    /*
    |--------------------------------------------------------------------------
    | Documents and disputes
    |--------------------------------------------------------------------------
    */

    public function attachPod(Payment $payment, UploadedFile $file, string $actorType, ?int $actorId): Payment
    {
        if (in_array($payment->status, [Payment::CANCELLED, Payment::REFUNDED], true)) {
            throw new PaymentRuleViolation('This payment is closed.');
        }

        $payment->forceFill(['pod_path' => $this->store($file, $payment, 'pod')])->save();

        $this->log($payment, 'pod', 'Proof of delivery attached', $file->getClientOriginalName(), $actorType, $actorId);

        return $payment;
    }

    /** @param  list<UploadedFile>  $evidence */
    public function openDispute(Payment $payment, string $reason, string $details, array $evidence, string $actorType, ?int $actorId): Dispute
    {
        if (! in_array($payment->status, [Payment::PROCESSING, Payment::FUNDED], true)) {
            throw new PaymentRuleViolation('A dispute can be raised while the payment is held, before it is released.');
        }

        if ($payment->openDispute()) {
            throw new PaymentRuleViolation('This payment already has an open dispute.');
        }

        $dispute = Dispute::create([
            'payment_id' => $payment->id,
            'raised_by_type' => $actorType,
            'raised_by' => $actorId,
            'reason' => $reason,
            'details' => $details,
            'evidence_paths' => array_map(fn (UploadedFile $f) => $this->store($f, $payment, 'evidence'), $evidence),
            'status' => Dispute::OPEN,
        ]);

        $this->log($payment, 'dispute_opened', 'Dispute opened', Dispute::REASONS[$reason] ?? $reason, $actorType, $actorId, ['dispute' => $dispute->id]);

        return $dispute;
    }

    public function resolveDispute(Payment $payment, Dispute $dispute, string $resolution, string $actorType, ?int $actorId): Dispute
    {
        if ($dispute->payment_id !== $payment->id || $dispute->status !== Dispute::OPEN) {
            throw new PaymentRuleViolation('That dispute is not open on this payment.');
        }

        $dispute->forceFill([
            'status' => Dispute::RESOLVED,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolved_by' => $actorType === 'broker' ? $actorId : null,
        ])->save();

        $this->log($payment, 'dispute_resolved', 'Dispute resolved', $resolution, $actorType, $actorId, ['dispute' => $dispute->id]);

        return $dispute;
    }

    /*
    |--------------------------------------------------------------------------
    | Carrier payouts
    |--------------------------------------------------------------------------
    */

    public function payoutAccount(string $carrierDot): ?PayoutAccount
    {
        return PayoutAccount::where('carrier_dot', $carrierDot)->where('livemode', $this->stripe->livemode())->first();
    }

    /** A hosted Stripe link the carrier opens to set up (or finish) payouts. */
    public function payoutLink(string $carrierDot, ?string $email, ?string $businessName): string
    {
        $account = $this->payoutAccount($carrierDot);

        if (! $account) {
            $account = PayoutAccount::create([
                'carrier_dot' => $carrierDot,
                'livemode' => $this->stripe->livemode(),
                'stripe_account_id' => $this->stripe->createRecipientAccount($carrierDot, $email, $businessName),
            ]);
        }

        $return = config('dtpay.payout_return_url');

        return $this->stripe->createAccountLink($account->stripe_account_id, $return.'?refresh=1&dot='.$carrierDot, $return.'?dot='.$carrierDot);
    }

    /** Re-read an account's capabilities from Stripe. */
    public function refreshPayoutAccount(PayoutAccount $account): PayoutAccount
    {
        $account->fill($this->stripe->retrieveAccount($account->stripe_account_id))->save();

        return $account;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function log(Payment $payment, string $type, string $label, ?string $detail, string $actorType, ?int $actorId = null, ?array $data = null): void
    {
        Event::create([
            'payment_id' => $payment->id,
            'type' => $type,
            'label' => $label,
            'detail' => $detail,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'data' => $data,
        ]);
    }

    public function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    private function store(UploadedFile $file, Payment $payment, string $kind): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');

        return $file->storeAs("dtpay/{$payment->uuid}", $kind.'-'.Str::ulid().'.'.$ext, config('dtpay.disk'));
    }
}
