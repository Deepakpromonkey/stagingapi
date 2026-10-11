<?php

namespace App\Pay\Http;

use App\Http\Controllers\Controller;
use App\Pay\Models\Payment;
use App\Pay\Models\PayoutAccount;
use App\Pay\PaymentService;
use App\Pay\Stripe\Gateway;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe tells DT Pay what happened after the fact: an ACH debit cleared or
 * bounced, a card was authenticated, a carrier finished payout setup, a card
 * payment was charged back. Each event is handled once.
 */
class WebhookController extends Controller
{
    public function __construct(private PaymentService $payments, private Gateway $stripe)
    {
    }

    public function handle(Request $request)
    {
        try {
            $event = $this->stripe->webhookEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (Throwable $e) {
            Log::warning('[DTPay] rejected webhook: '.$e->getMessage());

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        try {
            DB::table('dtpay_webhook_events')->insert(['id' => $event['id'], 'type' => $event['type'], 'created_at' => now()]);
        } catch (QueryException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $object = $event['data']['object'] ?? [];

        match (true) {
            str_starts_with($event['type'], 'payment_intent.') => $this->paymentIntent($object),
            $event['type'] === 'account.updated' => $this->account($object),
            $event['type'] === 'charge.dispute.created' => $this->chargeback($object),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function paymentIntent(array $pi): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $pi['id'] ?? '')->first()
            ?? Payment::where('uuid', $pi['metadata']['dtpay_payment'] ?? '')->first();

        if (! $payment) {
            return;
        }

        $charge = $pi['latest_charge'] ?? null;

        $this->payments->applyIntent($payment, [
            'id' => $pi['id'],
            'status' => $pi['status'],
            'client_secret' => null,
            'charge_id' => is_array($charge) ? ($charge['id'] ?? null) : $charge,
            'error' => $pi['last_payment_error']['message'] ?? null,
        ]);
    }

    private function account(array $account): void
    {
        PayoutAccount::where('stripe_account_id', $account['id'] ?? '')->update([
            'details_submitted' => (bool) ($account['details_submitted'] ?? false),
            'payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
            'transfers_active' => ($account['capabilities']['transfers'] ?? null) === 'active',
        ]);
    }

    /** A card chargeback: hold the money and say why. */
    private function chargeback(array $dispute): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $dispute['payment_intent'] ?? '')->first();

        if (! $payment) {
            return;
        }

        if (in_array($payment->status, [Payment::PROCESSING, Payment::FUNDED], true)) {
            $payment->forceFill(['on_hold' => true])->save();
        }

        $this->payments->log($payment, 'chargeback', 'Card payment disputed with the bank', 'Reason: '.str_replace('_', ' ', $dispute['reason'] ?? 'unknown').'. The payment is on hold.', 'stripe');
    }
}
