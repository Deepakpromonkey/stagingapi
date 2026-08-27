<?php

namespace App\Http\Controllers\Api\V1\Subscription;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe's side of the monthly subscription.
 *
 * Everything that decides whether a company is actually paying arrives here —
 * the browser returning from Checkout is a convenience, not a source of truth.
 * Responses are deliberately 200 for anything we simply cannot act on, so
 * Stripe stops retrying an event that will never succeed.
 */
class StripeWebhookController extends Controller
{
    /** How far out of date a signature timestamp may be, in seconds. */
    private const SIGNATURE_TOLERANCE = 300;

    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected BillingService $billingService
    ) {}

    public function handle(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            Log::error('Stripe webhook secret is not configured; refusing the event.');

            return response()->json(['error' => 'Webhooks are not configured.'], 503);
        }

        if (! $this->signatureIsValid(
            $request->getContent(),
            $request->header('Stripe-Signature'),
            $secret
        )) {
            Log::warning('Stripe webhook with an invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = $request->json()->all();
        $type = $event['type'] ?? null;
        $object = $event['data']['object'] ?? [];

        try {
            match ($type) {
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded' => $this->handleCheckoutCompleted($object),

                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted' => $this->subscriptionService->syncFromStripe($object),

                // The subscription status moves with it, but syncing off the
                // invoice too closes the gap when the two events race.
                'invoice.payment_failed',
                'invoice.paid',
                'invoice.payment_succeeded' => $this->handleInvoice($object),

                // No subscription state rides on these, but the invoice
                // history should still reflect them.
                'invoice.created',
                'invoice.finalized',
                'invoice.updated',
                'invoice.voided',
                'invoice.marked_uncollectible' => $this->billingService->syncInvoiceFromStripe($object),

                default => Log::debug('Unhandled Stripe webhook', ['type' => $type]),
            };
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handling failed', [
                'type' => $type,
                'event' => $event['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            // A 500 makes Stripe retry, which is what we want for a transient
            // failure on our side.
            return response()->json(['error' => 'Handling failed.'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    private function handleCheckoutCompleted(array $session): void
    {
        if (($session['mode'] ?? null) !== 'subscription') {
            return;
        }

        $subscriptionId = $session['subscription'] ?? null;

        if (! $subscriptionId) {
            Log::warning('Checkout session completed with no subscription', [
                'session' => $session['id'] ?? null,
            ]);

            return;
        }

        $this->subscriptionService->syncSubscriptionById($subscriptionId);
    }

    /**
     * A payment on a subscription invoice, taken or failed.
     *
     * The subscription is synced first so the invoice row can be attached to
     * it, then the invoice itself is mirrored, then — for a payment that
     * actually landed — our branded copy is emailed.
     */
    private function handleInvoice(array $invoice): void
    {
        $subscriptionId = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        if ($subscriptionId) {
            $this->subscriptionService->syncSubscriptionById($subscriptionId);
        }

        $record = $this->billingService->syncInvoiceFromStripe($invoice);

        if (! $record || ! $record->isPaid()) {
            return;
        }

        if (! config('billing.send_branded_email')) {
            return;
        }

        /*
        | `once: true` is what makes this safe to receive twice. Stripe sends
        | both invoice.paid and invoice.payment_succeeded for the same payment,
        | and will replay either one after a failed delivery — without the
        | guard the customer gets the same invoice mailed three or four times.
        */
        $this->billingService->sendBrandedInvoiceEmail($record, once: true);
    }

    /**
     * Verify Stripe's `Stripe-Signature` header by hand — the SDK is not
     * installed, and the scheme is a plain HMAC over "{timestamp}.{body}".
     */
    private function signatureIsValid(string $payload, ?string $header, string $secret): bool
    {
        if (! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't') {
                $timestamp = $value;
            }

            if ($key === 'v1' && $value) {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || ! $signatures) {
            return false;
        }

        // Rejects a captured request being replayed later.
        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
