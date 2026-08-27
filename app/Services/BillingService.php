<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Mail\SubscriptionInvoiceMail;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The billing side of a subscription: invoice history, the branded PDF, the
 * card on file, the spend report, and cancelling.
 *
 * App\Services\SubscriptionService owns getting a company onto a plan; this
 * owns everything that happens afterwards. Both talk to Stripe over its REST
 * API rather than the SDK, and both treat Stripe as the source of truth —
 * every local row is written from a payload Stripe gave us.
 */
class BillingService
{
    private const STRIPE_BASE_URL = 'https://api.stripe.com/v1';

    /** How many invoices to pull from Stripe in one refresh. */
    private const STRIPE_INVOICE_PAGE = 100;

    /**
     * How long a Stripe refresh is skipped for after one succeeds. Invoices
     * arrive by webhook, so the pull is a safety net rather than the mechanism
     * — re-running it on every page of a paginated list buys nothing.
     */
    private const REFRESH_THROTTLE_SECONDS = 120;

    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Overview
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the billing screen needs in one call: the plan, where it
     * stands, the card it is charged to, what is next, and this period's usage.
     */
    public function overview(Company $company): array
    {
        $subscription = $this->subscriptionService->currentSubscription($company)
            ?? $company->subscriptions()->latest('id')->first();

        return [
            'subscription' => $subscription,
            'load_usage' => $this->subscriptionService->loadUsage($company, $subscription),
            'payment_method' => $this->paymentMethod($company),
            'upcoming_invoice' => $this->upcomingInvoice($company),
            'billing_email' => $this->billingEmail($company),
            'has_billing_account' => (bool) $company->stripe_customer_id,

            // Drives the cancel/resume controls: a subscription already set to
            // end cannot be cancelled again, only resumed.
            'can_cancel' => (bool) ($subscription?->grantsAccess() && ! $subscription->cancel_at_period_end),
            'can_resume' => (bool) ($subscription?->grantsAccess() && $subscription->cancel_at_period_end),
            'allow_immediate_cancel' => (bool) config('billing.cancellation.allow_immediate'),
            'cancellation_reasons' => collect(config('billing.cancellation.reasons'))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice history
    |--------------------------------------------------------------------------
    */

    /**
     * The company's invoices, newest first, read from the local mirror.
     *
     * Stripe is polled first — throttled, and only ever as a top-up — so an
     * invoice raised while a webhook was failing still shows up.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<SubscriptionInvoice>
     */
    public function invoices(Company $company, int $perPage = 12, bool $refresh = true)
    {
        if ($refresh) {
            $this->refreshInvoices($company);
        }

        return $company->invoices()
            ->visible()
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** One invoice of this company's, by our uuid or Stripe's id. */
    public function findInvoice(Company $company, string $reference): SubscriptionInvoice
    {
        $invoice = $company->invoices()
            ->where(function ($query) use ($reference) {
                $query->where('uuid', $reference)
                    ->orWhere('stripe_invoice_id', $reference);
            })
            ->first();

        if (! $invoice) {
            // Could be an invoice Stripe has that we have not mirrored yet —
            // worth one look before telling the customer it does not exist.
            $this->refreshInvoices($company, force: true);

            $invoice = $company->invoices()
                ->where(function ($query) use ($reference) {
                    $query->where('uuid', $reference)
                        ->orWhere('stripe_invoice_id', $reference);
                })
                ->first();
        }

        if (! $invoice) {
            throw new BillingException('That invoice could not be found.', 404);
        }

        return $invoice;
    }

    /**
     * Pull the customer's invoices from Stripe and mirror them locally.
     *
     * Failures are logged, not thrown: the mirror is already populated by
     * webhooks, and a Stripe outage should degrade the invoice list to
     * "possibly a few minutes stale" rather than break the billing page.
     */
    public function refreshInvoices(Company $company, bool $force = false): void
    {
        if (! $company->stripe_customer_id || ! config('services.stripe.secret')) {
            return;
        }

        $throttleKey = 'billing:invoice-refresh:'.$company->id;

        if (! $force && Cache::get($throttleKey)) {
            return;
        }

        try {
            $response = $this->stripe()->get(self::STRIPE_BASE_URL.'/invoices', [
                'customer' => $company->stripe_customer_id,
                'limit' => self::STRIPE_INVOICE_PAGE,
            ]);

            if ($response->failed()) {
                Log::warning('Stripe invoice list failed', [
                    'company' => $company->uuid,
                    'body' => $response->body(),
                ]);

                return;
            }

            foreach ($response->json('data', []) as $payload) {
                $this->syncInvoiceFromStripe($payload, $company);
            }

            Cache::put($throttleKey, true, self::REFRESH_THROTTLE_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('Stripe invoice refresh failed', [
                'company' => $company->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mirror one Stripe invoice payload onto the local row.
     *
     * Returns null when the payload cannot be traced back to a company —
     * logged rather than thrown, so a stray webhook is not retried forever.
     */
    public function syncInvoiceFromStripe(array $payload, ?Company $company = null): ?SubscriptionInvoice
    {
        $stripeId = $payload['id'] ?? null;

        if (! $stripeId) {
            return null;
        }

        $company ??= $this->resolveCompanyFor($payload);

        if (! $company) {
            Log::warning('Stripe invoice for an unknown company', [
                'invoice' => $stripeId,
                'customer' => $payload['customer'] ?? null,
            ]);

            return null;
        }

        $lines = $this->normaliseLines($payload);

        // Stripe moved the subscription reference under `parent` in its 2025
        // API versions; older versions still report it on the invoice itself.
        $stripeSubscriptionId = $payload['subscription']
            ?? $payload['parent']['subscription_details']['subscription']
            ?? null;

        $subscription = $stripeSubscriptionId
            ? Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            : null;

        $invoice = SubscriptionInvoice::firstOrNew(['stripe_invoice_id' => $stripeId]);

        $invoice->fill([
            'uuid' => $invoice->uuid ?? (string) Str::uuid(),
            'company_id' => $company->id,
            'subscription_id' => $subscription?->id ?? $invoice->subscription_id,

            'stripe_payment_intent_id' => $this->paymentIntentIdFrom($payload) ?? $invoice->stripe_payment_intent_id,
            'stripe_charge_id' => $this->chargeIdFrom($payload) ?? $invoice->stripe_charge_id,

            'number' => $payload['number'] ?? $invoice->number,
            'status' => $payload['status'] ?? SubscriptionInvoice::STATUS_DRAFT,

            // The plan the lines were priced on, so history survives a plan
            // rename or an upgrade.
            'plan' => $this->planFromLines($lines)
                ?? $subscription?->plan
                ?? $invoice->plan,

            'currency' => $payload['currency'] ?? config('subscriptions.currency'),

            'subtotal_cents' => (int) ($payload['subtotal'] ?? 0),
            'tax_cents' => (int) ($payload['tax'] ?? $payload['total_taxes'][0]['amount'] ?? 0),
            'discount_cents' => (int) abs($payload['total_discount_amounts'][0]['amount'] ?? 0),
            'total_cents' => (int) ($payload['total'] ?? 0),
            'amount_paid_cents' => (int) ($payload['amount_paid'] ?? 0),
            'amount_due_cents' => (int) ($payload['amount_due'] ?? 0),

            'collection_method' => $payload['collection_method'] ?? null,
            'description' => $payload['description'] ?? null,

            'hosted_invoice_url' => $payload['hosted_invoice_url'] ?? $invoice->hosted_invoice_url,
            'invoice_pdf_url' => $payload['invoice_pdf'] ?? $invoice->invoice_pdf_url,

            'period_starts_at' => $this->timestamp($payload['period_start'] ?? null)
                ?? ($lines[0]['period_starts_at'] ?? null),
            'period_ends_at' => $this->timestamp($payload['period_end'] ?? null)
                ?? ($lines[0]['period_ends_at'] ?? null),

            'issued_at' => $this->timestamp($payload['created'] ?? null) ?? $invoice->issued_at ?? now(),
            'due_at' => $this->timestamp($payload['due_date'] ?? null),
            'paid_at' => $this->timestamp(
                $payload['status_transitions']['paid_at'] ?? null
            ) ?? $invoice->paid_at,

            'attempt_count' => (int) ($payload['attempt_count'] ?? 0),

            'lines' => $lines,
        ])->save();

        return $invoice;
    }

    /**
     * The card an invoice was actually settled with, for the PDF's payment
     * line. Resolved on demand and cached onto the row, because reading it
     * costs a second Stripe call per invoice and only the PDF needs it.
     */
    public function resolveInvoiceCard(SubscriptionInvoice $invoice): ?array
    {
        if ($invoice->card_last4) {
            return ['brand' => $invoice->card_brand, 'last4' => $invoice->card_last4];
        }

        if (! $invoice->isPaid() || ! config('services.stripe.secret')) {
            return null;
        }

        $card = null;

        try {
            if ($invoice->stripe_charge_id) {
                $response = $this->stripe()->get(self::STRIPE_BASE_URL.'/charges/'.$invoice->stripe_charge_id);

                if ($response->successful()) {
                    $card = $response->json('payment_method_details.card');
                }
            }

            if (! $card && $invoice->stripe_payment_intent_id) {
                $response = $this->stripe()->get(
                    self::STRIPE_BASE_URL.'/payment_intents/'.$invoice->stripe_payment_intent_id,
                    ['expand' => ['payment_method', 'latest_charge']]
                );

                if ($response->successful()) {
                    $card = $response->json('payment_method.card')
                        ?? $response->json('latest_charge.payment_method_details.card');

                    // Worth keeping: it saves the lookup above next time.
                    if ($chargeId = $response->json('latest_charge.id')) {
                        $invoice->forceFill(['stripe_charge_id' => $chargeId]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe card lookup for an invoice failed', [
                'invoice' => $invoice->stripe_invoice_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $card) {
            $invoice->save();

            return null;
        }

        $invoice->forceFill([
            'card_brand' => $card['brand'] ?? null,
            'card_last4' => $card['last4'] ?? null,
        ])->save();

        return ['brand' => $invoice->card_brand, 'last4' => $invoice->card_last4];
    }

    /*
    |--------------------------------------------------------------------------
    | What is coming next
    |--------------------------------------------------------------------------
    */

    /**
     * A preview of the next charge. Informational only, so every failure path
     * answers null rather than throwing — no subscription, no card, a Stripe
     * hiccup, or an account whose API version has retired the endpoint.
     */
    public function upcomingInvoice(Company $company): ?array
    {
        if (! $company->stripe_customer_id || ! config('services.stripe.secret')) {
            return null;
        }

        $subscription = $this->subscriptionService->currentSubscription($company);

        // Nothing is coming if it has been set to end.
        if (! $subscription || $subscription->cancel_at_period_end) {
            return null;
        }

        try {
            $response = $this->stripe()->get(self::STRIPE_BASE_URL.'/invoices/upcoming', [
                'customer' => $company->stripe_customer_id,
            ]);

            // Stripe's 2025 API versions replaced /invoices/upcoming with
            // /invoices/create_preview. Which one answers depends on the
            // account's pinned version, so both are tried.
            if ($response->status() === 404) {
                $response = $this->stripe()->post(self::STRIPE_BASE_URL.'/invoices/create_preview', [
                    'customer' => $company->stripe_customer_id,
                ]);
            }

            if ($response->failed()) {
                return null;
            }

            $payload = $response->json();

            return [
                'total' => round(((int) ($payload['total'] ?? 0)) / 100, 2),
                'subtotal' => round(((int) ($payload['subtotal'] ?? 0)) / 100, 2),
                'tax' => round(((int) ($payload['tax'] ?? $payload['total_taxes'][0]['amount'] ?? 0)) / 100, 2),
                'currency' => $payload['currency'] ?? $subscription->currency,
                'charges_at' => $this->timestamp(
                    $payload['next_payment_attempt'] ?? $payload['period_end'] ?? null
                ) ?? $subscription->current_period_ends_at,
                'lines' => $this->normaliseLines($payload),
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe upcoming invoice preview failed', [
                'company' => $company->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The card the subscription is charged to. Null when there is none yet, or
     * when Stripe cannot be reached — the billing page renders either way.
     */
    public function paymentMethod(Company $company): ?array
    {
        if (! $company->stripe_customer_id || ! config('services.stripe.secret')) {
            return null;
        }

        try {
            $customer = $this->stripe()->get(
                self::STRIPE_BASE_URL.'/customers/'.$company->stripe_customer_id,
                ['expand' => ['invoice_settings.default_payment_method']]
            );

            if ($customer->successful()) {
                $method = $customer->json('invoice_settings.default_payment_method');

                if ($card = $this->cardFromPaymentMethod($method)) {
                    return $card;
                }
            }

            // No explicit default: fall back to the newest card on the
            // customer, which is what Stripe will charge anyway.
            $methods = $this->stripe()->get(
                self::STRIPE_BASE_URL.'/customers/'.$company->stripe_customer_id.'/payment_methods',
                ['type' => 'card', 'limit' => 1]
            );

            if ($methods->successful()) {
                return $this->cardFromPaymentMethod($methods->json('data.0'));
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe payment method lookup failed', [
                'company' => $company->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Billing report
    |--------------------------------------------------------------------------
    */

    /**
     * The spend report: what has been billed, what has been paid, and how it
     * has trended. Built from the local mirror, so it stays available even
     * when Stripe does not answer.
     *
     * @param  int  $months  How far back the monthly series runs.
     */
    public function report(Company $company, int $months = 12): array
    {
        $this->refreshInvoices($company);

        $invoices = $company->invoices()->visible()->get();

        $paid = $invoices->where('status', SubscriptionInvoice::STATUS_PAID);
        $open = $invoices->where('status', SubscriptionInvoice::STATUS_OPEN);

        $subscription = $this->subscriptionService->currentSubscription($company);

        return [
            // Nothing invoiced yet means there is no invoice to read a
            // currency off, which is the normal state of a trialling account.
            'currency' => $invoices->first()?->currency
                ?? $subscription?->currency
                ?? config('subscriptions.currency'),

            'totals' => [
                'paid' => $this->dollars($paid->sum('amount_paid_cents')),
                'outstanding' => $this->dollars($open->sum('amount_due_cents')),
                'invoice_count' => $invoices->count(),
                'paid_count' => $paid->count(),
                'open_count' => $open->count(),
                'overdue_count' => $open->filter(fn ($invoice) => $invoice->isOverdue())->count(),

                // A steadier read than "last invoice" when a period included a
                // proration or a credit.
                'average_per_invoice' => $paid->count() > 0
                    ? $this->dollars((int) round($paid->sum('amount_paid_cents') / $paid->count()))
                    : 0.0,
            ],

            'first_invoice_at' => $invoices->min('issued_at'),
            'last_payment_at' => $paid->max('paid_at'),

            'monthly' => $this->monthlySeries($invoices, $months),

            // The same numbers the overview shows, so a report opened on its
            // own still says what plan it is a report of.
            'plan' => $subscription ? [
                'key' => $subscription->plan,
                'name' => $subscription->planName(),
                'status' => $subscription->status,
                'amount' => $subscription->amount(),
                'interval' => $subscription->interval,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            ] : null,

            'load_usage' => $this->subscriptionService->loadUsage($company, $subscription),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelling
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel the subscription — at the end of the period already paid for by
     * default, or there and then when the customer asks for that.
     *
     * The reason is passed to Stripe as cancellation feedback so it lands in
     * Stripe's own churn reporting rather than only in our logs.
     */
    public function cancel(
        Company $company,
        bool $immediately = false,
        ?string $reason = null,
        ?string $comment = null
    ): Subscription {
        $this->assertStripeConfigured();

        $subscription = $this->subscriptionService->currentSubscription($company);

        if (! $subscription || ! $subscription->stripe_subscription_id) {
            throw new BillingException('You do not have an active subscription to cancel.', 409);
        }

        if ($immediately && ! config('billing.cancellation.allow_immediate')) {
            throw new BillingException(
                'Subscriptions end at the close of the period you have already paid for.',
                422
            );
        }

        if (! $immediately && $subscription->cancel_at_period_end) {
            throw new BillingException('This subscription is already set to end.', 409);
        }

        $payload = array_filter([
            'cancellation_details[feedback]' => $this->stripeFeedback($reason),
            'cancellation_details[comment]' => $comment,
        ], fn ($value) => $value !== null && $value !== '');

        $url = self::STRIPE_BASE_URL.'/subscriptions/'.$subscription->stripe_subscription_id;

        $response = $immediately
            ? $this->stripe()->delete($url, $payload)
            : $this->stripe()->post($url, $payload + ['cancel_at_period_end' => 'true']);

        if ($response->failed()) {
            Log::error('Stripe subscription cancel failed', [
                'company' => $company->uuid,
                'subscription' => $subscription->stripe_subscription_id,
                'immediately' => $immediately,
                'body' => $response->body(),
            ]);

            throw BillingException::stripeFailed('We could not cancel the subscription. Please try again.');
        }

        Log::info('Subscription cancelled', [
            'company' => $company->uuid,
            'plan' => $subscription->plan,
            'immediately' => $immediately,
            'reason' => $reason,
        ]);

        // Stripe's response is the authoritative new state — mirror it rather
        // than assuming what the cancellation did.
        return $this->subscriptionService->syncFromStripe($response->json()) ?? $subscription->refresh();
    }

    /**
     * Undo a pending cancellation. Only possible while the period the customer
     * paid for is still running — after that Stripe has closed the
     * subscription and a new checkout is the only way back.
     */
    public function resume(Company $company): Subscription
    {
        $this->assertStripeConfigured();

        $subscription = $this->subscriptionService->currentSubscription($company);

        if (! $subscription || ! $subscription->stripe_subscription_id) {
            throw new BillingException(
                'This subscription has already ended. Choose a plan to start again.',
                409
            );
        }

        if (! $subscription->cancel_at_period_end) {
            throw new BillingException('This subscription is not scheduled to end.', 409);
        }

        $response = $this->stripe()->post(
            self::STRIPE_BASE_URL.'/subscriptions/'.$subscription->stripe_subscription_id,
            ['cancel_at_period_end' => 'false']
        );

        if ($response->failed()) {
            Log::error('Stripe subscription resume failed', [
                'company' => $company->uuid,
                'subscription' => $subscription->stripe_subscription_id,
                'body' => $response->body(),
            ]);

            throw BillingException::stripeFailed('We could not restart the subscription. Please try again.');
        }

        return $this->subscriptionService->syncFromStripe($response->json()) ?? $subscription->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Emailing an invoice
    |--------------------------------------------------------------------------
    */

    /**
     * Email an invoice out. Two copies can go, and by default both do: our own
     * branded PDF from this application, and Stripe's own record from theirs
     * (see config/billing.php for why both).
     *
     * @return array{branded: bool, stripe: bool, recipient: string}
     */
    public function emailInvoice(SubscriptionInvoice $invoice, ?string $recipient = null): array
    {
        $company = $invoice->company;
        $recipient = $recipient ?: $this->billingEmail($company);

        if (! $recipient) {
            throw new BillingException(
                'There is no billing email on this account to send the invoice to.',
                422
            );
        }

        $branded = $this->sendBrandedInvoiceEmail($invoice, $recipient);
        $stripe = $this->askStripeToEmail($invoice, $recipient);

        if (! $branded && ! $stripe) {
            throw BillingException::stripeFailed('We could not send the invoice. Please try again.');
        }

        return [
            'branded' => $branded,
            'stripe' => $stripe,
            'recipient' => $recipient,
        ];
    }

    /**
     * Send our own branded copy. `$once` is how the webhook path avoids
     * mailing the same invoice twice when Stripe replays an event.
     */
    public function sendBrandedInvoiceEmail(
        SubscriptionInvoice $invoice,
        ?string $recipient = null,
        bool $once = false
    ): bool {
        if ($once && $invoice->emailed_at) {
            return false;
        }

        $recipient = $recipient ?: $this->billingEmail($invoice->company);

        if (! $recipient) {
            return false;
        }

        try {
            $pdf = app(InvoicePdfService::class)->render($invoice);

            Mail::to($recipient)->send(new SubscriptionInvoiceMail($invoice, $pdf));

            $invoice->forceFill(['emailed_at' => now()])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('Branded invoice email failed', [
                'invoice' => $invoice->stripe_invoice_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Have Stripe send its own copy.
     *
     * Which call does that depends on how the invoice is collected. One billed
     * to terms is emailed outright. A subscription charged to a card on file
     * cannot be "sent" — Stripe emails a receipt for the charge instead, which
     * is triggered by writing the address onto the payment.
     */
    public function askStripeToEmail(SubscriptionInvoice $invoice, string $recipient): bool
    {
        if (! config('billing.ask_stripe_to_email') || ! config('services.stripe.secret')) {
            return false;
        }

        try {
            if ($invoice->collection_method === 'send_invoice'
                && $invoice->status === SubscriptionInvoice::STATUS_OPEN) {

                $response = $this->stripe()->post(
                    self::STRIPE_BASE_URL.'/invoices/'.$invoice->stripe_invoice_id.'/send_invoice'
                );

                if ($response->successful()) {
                    return true;
                }

                Log::warning('Stripe send_invoice failed', [
                    'invoice' => $invoice->stripe_invoice_id,
                    'body' => $response->body(),
                ]);

                return false;
            }

            // A receipt for the charge that settled it.
            if ($invoice->stripe_charge_id) {
                $response = $this->stripe()->post(
                    self::STRIPE_BASE_URL.'/charges/'.$invoice->stripe_charge_id,
                    ['receipt_email' => $recipient]
                );

                if ($response->successful()) {
                    return true;
                }
            }

            if ($invoice->stripe_payment_intent_id) {
                $response = $this->stripe()->post(
                    self::STRIPE_BASE_URL.'/payment_intents/'.$invoice->stripe_payment_intent_id,
                    ['receipt_email' => $recipient]
                );

                if ($response->successful()) {
                    return true;
                }

                Log::warning('Stripe receipt email request failed', [
                    'invoice' => $invoice->stripe_invoice_id,
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe email request failed', [
                'invoice' => $invoice->stripe_invoice_id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Where an invoice for this company should go. The company's own billing
     * address if it has one, otherwise the account that created it.
     */
    public function billingEmail(Company $company): ?string
    {
        if ($company->company_email) {
            return $company->company_email;
        }

        return User::where('company_id', $company->id)
            ->orderBy('id')
            ->value('email');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Flatten a Stripe invoice's line items into the shape the PDF and the API
     * both read. Amounts stay in cents, as Stripe reports them.
     */
    private function normaliseLines(array $payload): array
    {
        return collect($payload['lines']['data'] ?? [])
            ->map(function (array $line) {
                $price = $line['price'] ?? $line['pricing']['price_details'] ?? [];

                return [
                    'description' => $line['description']
                        ?? $line['plan']['nickname']
                        ?? 'Subscription',
                    'quantity' => (int) ($line['quantity'] ?? 1),
                    'unit_amount_cents' => isset($price['unit_amount'])
                        ? (int) $price['unit_amount']
                        : (int) ($line['amount'] ?? 0),
                    'amount_cents' => (int) ($line['amount'] ?? 0),
                    'proration' => (bool) ($line['proration'] ?? false),
                    'price_id' => $price['id'] ?? $price['price'] ?? null,
                    'period_starts_at' => $this->timestamp($line['period']['start'] ?? null),
                    'period_ends_at' => $this->timestamp($line['period']['end'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /** Which of our plans these lines were priced on, if any. */
    private function planFromLines(array $lines): ?string
    {
        foreach ($lines as $line) {
            if ($plan = $this->subscriptionService->planForPriceId($line['price_id'] ?? null)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Stripe's payment intent reference. Its 2025 API versions moved it under
     * `payments`; older versions report it on the invoice.
     */
    private function paymentIntentIdFrom(array $payload): ?string
    {
        $intent = $payload['payment_intent']
            ?? $payload['payments']['data'][0]['payment']['payment_intent']
            ?? null;

        return is_array($intent) ? ($intent['id'] ?? null) : $intent;
    }

    private function chargeIdFrom(array $payload): ?string
    {
        $charge = $payload['charge']
            ?? $payload['payments']['data'][0]['payment']['charge']
            ?? null;

        return is_array($charge) ? ($charge['id'] ?? null) : $charge;
    }

    private function cardFromPaymentMethod($method): ?array
    {
        if (! is_array($method) || empty($method['card'])) {
            return null;
        }

        $card = $method['card'];

        return [
            'id' => $method['id'] ?? null,
            'brand' => $card['brand'] ?? null,
            'last4' => $card['last4'] ?? null,
            'exp_month' => isset($card['exp_month']) ? (int) $card['exp_month'] : null,
            'exp_year' => isset($card['exp_year']) ? (int) $card['exp_year'] : null,

            // A card that expires this month is not yet a failure, but it is
            // the thing most likely to break the next charge.
            'expires_soon' => isset($card['exp_month'], $card['exp_year'])
                && Carbon::createFromDate((int) $card['exp_year'], (int) $card['exp_month'], 1)
                    ->endOfMonth()
                    ->lessThan(now()->addDays(45)),
        ];
    }

    /**
     * Spend per calendar month, oldest first, with empty months included so a
     * chart does not close the gaps and imply billing that did not happen.
     *
     * @param  Collection<int, SubscriptionInvoice>  $invoices
     */
    private function monthlySeries(Collection $invoices, int $months): array
    {
        $byMonth = $invoices
            ->filter(fn ($invoice) => $invoice->issued_at !== null)
            ->groupBy(fn ($invoice) => $invoice->issued_at->format('Y-m'));

        $series = [];
        $cursor = now()->startOfMonth()->subMonths(max(0, $months - 1));

        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');
            $monthly = $byMonth->get($key, collect());

            $series[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'paid' => $this->dollars($monthly->sum('amount_paid_cents')),
                'billed' => $this->dollars($monthly->sum('total_cents')),
                'invoice_count' => $monthly->count(),
            ];

            $cursor = $cursor->copy()->addMonth();
        }

        return $series;
    }

    /** Only Stripe's own feedback values are accepted; anything else is dropped. */
    private function stripeFeedback(?string $reason): ?string
    {
        if (! $reason) {
            return null;
        }

        return array_key_exists($reason, config('billing.cancellation.reasons', []))
            ? $reason
            : null;
    }

    private function resolveCompanyFor(array $payload): ?Company
    {
        $uuid = $payload['metadata']['company_uuid'] ?? null;

        if ($uuid && $company = Company::where('uuid', $uuid)->first()) {
            return $company;
        }

        $customerId = $payload['customer'] ?? null;

        if (is_array($customerId)) {
            $customerId = $customerId['id'] ?? null;
        }

        return $customerId
            ? Company::where('stripe_customer_id', $customerId)->first()
            : null;
    }

    private function dollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function timestamp(?int $unix): ?Carbon
    {
        return $unix ? Carbon::createFromTimestamp($unix) : null;
    }

    private function assertStripeConfigured(): void
    {
        if (! config('services.stripe.secret')) {
            Log::error('Stripe is not configured; cannot handle billing.');

            throw BillingException::stripeUnavailable();
        }
    }

    private function stripe(): PendingRequest
    {
        return Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->acceptJson();
    }
}
