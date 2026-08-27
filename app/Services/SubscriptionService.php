<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Monthly plan billing, offered straight after signup.
 *
 * Stripe is talked to over its REST API rather than the SDK, the same way the
 * carrier payout flow does it, so no extra dependency is needed. Stripe stays
 * the source of truth: local subscription rows are only ever written from a
 * payload Stripe gave us.
 */
class SubscriptionService
{
    private const STRIPE_BASE_URL = 'https://api.stripe.com/v1';

    /**
     * The pricing table, in the order it should be rendered. Safe to expose
     * publicly — price IDs stay server side.
     */
    public function plans(?Company $company = null): array
    {
        // A returning customer has already had their trial, so the pricing
        // table should not keep advertising one to them.
        $trialAvailable = $company === null || $this->isEligibleForTrial($company);

        return collect(config('subscriptions.plans'))
            ->map(fn (array $plan, string $key) => [
                'key' => $key,
                'name' => $plan['name'],
                'description' => $plan['description'] ?? null,
                'amount' => $plan['amount'],
                'currency' => config('subscriptions.currency'),
                'interval' => config('subscriptions.interval'),
                'contact_sales' => (bool) ($plan['contact_sales'] ?? false),

                // Loads allowed per billing period; null is unlimited.
                'load_limit' => $plan['load_limit'] ?? null,

                // Free days on this plan — 14 on Standard, none elsewhere.
                // Zero once the company has already used its one trial.
                'trial_days' => $trialAvailable ? (int) ($plan['trial_days'] ?? 0) : 0,

                'features' => $plan['features'] ?? [],

                // A plan we cannot actually bill — a missing price ID — must
                // not be offered as a buy button.
                'available' => ($plan['contact_sales'] ?? false)
                    ? true
                    : ! empty($plan['price_id']),
            ])
            ->values()
            ->all();
    }

    public function planConfig(string $plan): ?array
    {
        return config('subscriptions.plans.'.$plan);
    }

    /**
     * Open a Stripe Checkout session for a monthly subscription and hand back
     * the URL the frontend should send the browser to.
     */
    public function startCheckout(Company $company, User $user, string $plan): array
    {
        $config = $this->planConfig($plan);

        if (! $config) {
            throw new BillingException('That plan does not exist.', 404);
        }

        if ($config['contact_sales'] ?? false) {
            throw new BillingException(
                'Enterprise is priced individually. Send us an enquiry and our team will be in touch.',
                422,
                ['plan' => ['contact_sales']]
            );
        }

        if (empty($config['price_id'])) {
            Log::error('Subscription plan has no Stripe price configured', ['plan' => $plan]);

            throw BillingException::stripeUnavailable();
        }

        $this->assertStripeConfigured();

        $existing = $company->subscriptions()->grantingAccess()->latest('id')->first();

        if ($existing && $existing->plan === $plan && $existing->isActive()) {
            throw new BillingException('You are already subscribed to this plan.', 409);
        }

        $customerId = $this->resolveCustomer($company, $user);

        $payload = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items[0][price]' => $config['price_id'],
            'line_items[0][quantity]' => 1,
            'client_reference_id' => $company->uuid,
            'allow_promotion_codes' => 'true',
            'success_url' => $this->frontendUrl(config('subscriptions.success_path'))
                .'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->frontendUrl(config('subscriptions.cancel_path')).'?checkout=cancelled',
            'metadata[company_uuid]' => $company->uuid,
            'metadata[plan]' => $plan,

            // Copied onto the subscription itself as well, because the
            // webhooks that matter later carry the subscription, not the
            // Checkout session.
            'subscription_data[metadata][company_uuid]' => $company->uuid,
            'subscription_data[metadata][plan]' => $plan,
        ];

        // The free trial belongs to the plan, not the product: Standard carries
        // 14 days, Pro and Enterprise are billed from day one.
        $trialDays = (int) ($config['trial_days'] ?? 0);

        if ($trialDays > 0 && $this->isEligibleForTrial($company)) {
            $payload['subscription_data[trial_period_days]'] = $trialDays;
        }

        $response = $this->stripe()->post(self::STRIPE_BASE_URL.'/checkout/sessions', $payload);

        if ($response->failed()) {
            Log::error('Stripe Checkout session create failed', [
                'company' => $company->uuid,
                'plan' => $plan,
                'body' => $response->body(),
            ]);

            throw BillingException::stripeFailed('We could not open the payment page. Please try again.');
        }

        // A placeholder so the account reads as "payment pending" between
        // leaving for Stripe and the webhook landing.
        $subscription = Subscription::firstOrNew([
            'company_id' => $company->id,
            'plan' => $plan,
            'stripe_subscription_id' => null,
        ]);

        $subscription->fill([
            'uuid' => $subscription->uuid ?? (string) Str::uuid(),
            'status' => Subscription::STATUS_INCOMPLETE,
            'stripe_price_id' => $config['price_id'],
            'stripe_checkout_session_id' => $response->json('id'),
            'amount_cents' => $config['amount'] !== null ? (int) round($config['amount'] * 100) : null,
            'currency' => config('subscriptions.currency'),
            'interval' => config('subscriptions.interval'),
        ])->save();

        return [
            'checkout_url' => $response->json('url'),
            'session_id' => $response->json('id'),
            'subscription' => $subscription,
        ];
    }

    /**
     * Reconcile after the customer returns from Checkout. Webhooks are the
     * durable path, but they can lag by a few seconds and the success screen
     * should not have to guess.
     */
    public function syncCheckoutSession(Company $company, string $sessionId): ?Subscription
    {
        $this->assertStripeConfigured();

        $session = $this->stripe()->get(self::STRIPE_BASE_URL.'/checkout/sessions/'.$sessionId);

        if ($session->failed()) {
            Log::error('Stripe Checkout session retrieve failed', [
                'company' => $company->uuid,
                'session' => $sessionId,
                'body' => $session->body(),
            ]);

            throw BillingException::stripeFailed('We could not confirm the payment. Please try again.');
        }

        // Guard against one company polling another company's session.
        if ($session->json('client_reference_id') !== $company->uuid) {
            throw new BillingException('That checkout session does not belong to your company.', 403);
        }

        $subscriptionId = $session->json('subscription');

        if (! $subscriptionId) {
            return null;
        }

        return $this->syncSubscriptionById($subscriptionId);
    }

    public function syncSubscriptionById(string $stripeSubscriptionId): ?Subscription
    {
        $this->assertStripeConfigured();

        $response = $this->stripe()->get(self::STRIPE_BASE_URL.'/subscriptions/'.$stripeSubscriptionId);

        if ($response->failed()) {
            Log::error('Stripe subscription retrieve failed', [
                'subscription' => $stripeSubscriptionId,
                'body' => $response->body(),
            ]);

            throw BillingException::stripeFailed('We could not read the subscription. Please try again.');
        }

        return $this->syncFromStripe($response->json());
    }

    /**
     * Mirror a Stripe subscription payload onto the local row. Returns null
     * when the payload cannot be traced back to a company — logged rather than
     * thrown, so a stray webhook does not make Stripe retry forever.
     */
    public function syncFromStripe(array $payload): ?Subscription
    {
        $stripeId = $payload['id'] ?? null;

        if (! $stripeId) {
            return null;
        }

        $company = $this->resolveCompanyFor($payload);

        if (! $company) {
            Log::warning('Stripe subscription for an unknown company', [
                'subscription' => $stripeId,
                'customer' => $payload['customer'] ?? null,
            ]);

            return null;
        }

        $item = $payload['items']['data'][0] ?? [];
        $price = $item['price'] ?? [];
        $priceId = $price['id'] ?? null;

        $plan = $this->planForPriceId($priceId)
            ?? ($payload['metadata']['plan'] ?? null)
            ?? $company->subscriptions()
                ->where('stripe_subscription_id', $stripeId)
                ->value('plan');

        if (! $plan) {
            Log::warning('Stripe subscription on an unrecognised price', [
                'subscription' => $stripeId,
                'price' => $priceId,
            ]);

            return null;
        }

        // Stripe moved the period fields onto the subscription item; older API
        // versions still report them on the subscription itself.
        $periodStart = $payload['current_period_start'] ?? $item['current_period_start'] ?? null;
        $periodEnd = $payload['current_period_end'] ?? $item['current_period_end'] ?? null;

        $subscription = Subscription::firstOrNew(['stripe_subscription_id' => $stripeId]);

        $subscription->fill([
            'uuid' => $subscription->uuid ?? (string) Str::uuid(),
            'company_id' => $company->id,
            'plan' => $plan,
            'status' => $payload['status'] ?? Subscription::STATUS_INCOMPLETE,
            'stripe_price_id' => $priceId,
            'amount_cents' => $price['unit_amount'] ?? null,
            'currency' => $price['currency'] ?? config('subscriptions.currency'),
            'interval' => $price['recurring']['interval'] ?? config('subscriptions.interval'),
            'trial_ends_at' => $this->timestamp($payload['trial_end'] ?? null),
            'current_period_starts_at' => $this->timestamp($periodStart),
            'current_period_ends_at' => $this->timestamp($periodEnd),
            'cancel_at_period_end' => (bool) ($payload['cancel_at_period_end'] ?? false),
            'canceled_at' => $this->timestamp($payload['canceled_at'] ?? null),
        ])->save();

        // Fold away the placeholder row written when Checkout opened, now that
        // the real subscription exists.
        $company->subscriptions()
            ->whereNull('stripe_subscription_id')
            ->where('plan', $plan)
            ->where('id', '!=', $subscription->id)
            ->delete();

        return $subscription;
    }

    /**
     * A trial is given once per company, on the first subscription that
     * actually reaches Stripe.
     *
     * Placeholder rows are deliberately excluded: opening Checkout and
     * abandoning it writes one, and counting that would quietly cost the
     * customer their trial for a payment they never made.
     */
    public function isEligibleForTrial(Company $company): bool
    {
        return ! $company->subscriptions()
            ->whereNotNull('stripe_subscription_id')
            ->exists();
    }

    /**
     * The company's current subscription, whatever its state.
     */
    public function currentSubscription(Company $company): ?Subscription
    {
        return $company->subscriptions()->grantingAccess()->latest('id')->first();
    }

    /**
     * How many loads the company has booked against this billing period's
     * allowance. Always answers, subscription or not, so the dashboard can
     * render the same widget either way.
     *
     * @return array{
     *     limit: ?int, used: int, remaining: ?int, unlimited: bool,
     *     period_starts_at: ?Carbon,
     *     period_ends_at: ?Carbon,
     *     plan: ?string
     * }
     */
    public function loadUsage(Company $company, ?Subscription $subscription = null): array
    {
        $subscription ??= $this->currentSubscription($company);

        // No plan: loads are counted over the calendar month so the number
        // still means something, but nothing is capped.
        $periodStart = $subscription
            ? $subscription->periodStartsAt()
            : now()->startOfMonth();

        $used = $company->shipments()
            ->where('created_at', '>=', $periodStart)
            ->count();

        $limit = $subscription?->loadLimit();

        return [
            'plan' => $subscription?->plan,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'unlimited' => $subscription ? $subscription->hasUnlimitedLoads() : true,
            'period_starts_at' => $subscription ? $periodStart : null,
            'period_ends_at' => $subscription?->current_period_ends_at,
        ];
    }

    /**
     * Gate on the plan's monthly load allowance. Called before a load is
     * created — Standard buys 100 a month, Pro 250, Enterprise as sold.
     */
    public function assertCanCreateLoad(Company $company): void
    {
        $subscription = $this->currentSubscription($company);

        if (! $subscription) {
            // Accounts that predate billing keep working until this is
            // switched on deliberately.
            if (! config('subscriptions.require_subscription_for_loads')) {
                return;
            }

            throw new BillingException(
                'Choose a plan to start booking loads.',
                402,
                ['reason' => ['no_subscription']]
            );
        }

        $usage = $this->loadUsage($company, $subscription);

        if ($usage['limit'] === null || $usage['used'] < $usage['limit']) {
            return;
        }

        throw new BillingException(
            sprintf(
                'You have used all %d loads included in the %s plan this billing period. Upgrade to add more.',
                $usage['limit'],
                $subscription->planName()
            ),
            402,
            ['load_quota' => [
                'plan' => $subscription->plan,
                'limit' => $usage['limit'],
                'used' => $usage['used'],
                'period_ends_at' => optional($usage['period_ends_at'])->toIso8601String(),
            ]]
        );
    }

    /**
     * A link into Stripe's hosted billing portal — where the customer updates
     * their card, downloads invoices, or cancels.
     */
    public function billingPortalUrl(Company $company): string
    {
        $this->assertStripeConfigured();

        if (! $company->stripe_customer_id) {
            throw new BillingException('You do not have a billing account yet. Choose a plan first.', 409);
        }

        $response = $this->stripe()->post(self::STRIPE_BASE_URL.'/billing_portal/sessions', [
            'customer' => $company->stripe_customer_id,
            'return_url' => $this->frontendUrl(config('subscriptions.portal_return_path')),
        ]);

        if ($response->failed()) {
            Log::error('Stripe billing portal session failed', [
                'company' => $company->uuid,
                'body' => $response->body(),
            ]);

            throw BillingException::stripeFailed('We could not open the billing portal. Please try again.');
        }

        return $response->json('url');
    }

    /** The Stripe customer for this company, created on first use. */
    private function resolveCustomer(Company $company, User $user): string
    {
        if ($company->stripe_customer_id) {
            return $company->stripe_customer_id;
        }

        $response = $this->stripe()->post(self::STRIPE_BASE_URL.'/customers', array_filter([
            'name' => $company->company_name,
            'email' => $company->company_email ?: $user->email,
            'phone' => $company->company_phone,
            'metadata[company_uuid]' => $company->uuid,
            'metadata[business_type]' => $company->business_type,
            'metadata[dot_number]' => $company->dot_number,
        ], fn ($value) => $value !== null && $value !== ''));

        if ($response->failed()) {
            Log::error('Stripe customer create failed', [
                'company' => $company->uuid,
                'body' => $response->body(),
            ]);

            throw BillingException::stripeFailed('We could not set up your billing account. Please try again.');
        }

        $customerId = $response->json('id');

        $company->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
    }

    private function resolveCompanyFor(array $payload): ?Company
    {
        $uuid = $payload['metadata']['company_uuid'] ?? null;

        if ($uuid && $company = Company::where('uuid', $uuid)->first()) {
            return $company;
        }

        $customerId = $payload['customer'] ?? null;

        return $customerId
            ? Company::where('stripe_customer_id', $customerId)->first()
            : null;
    }

    /** Which configured plan a Stripe price belongs to. Public because
     * App\Services\BillingService resolves an invoice's plan the same way.
     */
    public function planForPriceId(?string $priceId): ?string
    {
        if (! $priceId) {
            return null;
        }

        foreach (config('subscriptions.plans') as $key => $plan) {
            if (! empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                return $key;
            }
        }

        return null;
    }

    private function timestamp(?int $unix): ?Carbon
    {
        return $unix ? Carbon::createFromTimestamp($unix) : null;
    }

    private function assertStripeConfigured(): void
    {
        if (! config('services.stripe.secret')) {
            Log::error('Stripe is not configured; cannot handle subscriptions.');

            throw BillingException::stripeUnavailable();
        }
    }

    private function stripe(): PendingRequest
    {
        return Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->acceptJson();
    }

    private function frontendUrl(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
