<?php

namespace App\Http\Controllers\Api\V1\Subscription;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Subscription\CheckoutRequest;
use App\Http\Requests\Subscription\EnterpriseInquiryRequest;
use App\Http\Requests\Subscription\SyncCheckoutRequest;
use App\Http\Resources\SubscriptionResource;
use App\Mail\EnterpriseInquiryMail;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Plan selection and monthly billing, picked up straight after signup.
 */
class SubscriptionController extends BaseController
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * The pricing table. Public, because the signup screen shows it before the
     * new account has finished authenticating.
     */
    public function plans()
    {
        // Trial length is per plan — read `trial_days` off each entry rather
        // than assuming one number covers the table.
        return $this->success([
            'plans' => $this->subscriptionService->plans(),
            'currency' => config('subscriptions.currency'),
            'interval' => config('subscriptions.interval'),
        ]);
    }

    /** Where the signed-in company's billing currently stands. */
    public function show()
    {
        $company = auth()->user()->company;

        $subscription = $this->subscriptionService->currentSubscription($company);

        return $this->success([
            'has_subscription' => (bool) $subscription,
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,

            // What is left of this period's load allowance.
            'load_usage' => $this->subscriptionService->loadUsage($company, $subscription),

            // Scoped to this company, so a returning customer is not offered a
            // trial they have already used.
            'trial_available' => $this->subscriptionService->isEligibleForTrial($company),
            'plans' => $this->subscriptionService->plans($company),
        ]);
    }

    /**
     * Open Stripe Checkout for a monthly plan. The response carries the URL
     * the browser should be sent to.
     */
    public function checkout(CheckoutRequest $request)
    {
        $user = $request->user();

        $result = $this->subscriptionService->startCheckout(
            $user->company,
            $user,
            $request->validated()['plan']
        );

        return $this->success([
            'checkout_url' => $result['checkout_url'],
            'session_id' => $result['session_id'],
            'subscription' => new SubscriptionResource($result['subscription']),
        ], 'Redirect to Stripe to finish your subscription.');
    }

    /**
     * Called by the success screen on the way back from Stripe. The webhook is
     * what makes the subscription durable; this only spares the customer a
     * wait when it lands a second or two later.
     */
    public function syncCheckout(SyncCheckoutRequest $request)
    {
        $subscription = $this->subscriptionService->syncCheckoutSession(
            $request->user()->company,
            $request->validated()['session_id']
        );

        if (! $subscription) {
            return $this->success([
                'subscription' => null,
            ], 'Your payment is still processing. We will activate the account as soon as Stripe confirms it.');
        }

        return $this->success([
            'subscription' => new SubscriptionResource($subscription),
        ], 'Subscription active.');
    }

    /** Stripe's hosted portal — card changes, invoices, cancellation. */
    public function billingPortal()
    {
        $url = $this->subscriptionService->billingPortalUrl(
            auth()->user()->company
        );

        return $this->success(['url' => $url], 'Opening the billing portal.');
    }

    /**
     * Enterprise has no self-serve price, so the button raises an enquiry with
     * sales instead of opening Checkout.
     */
    public function enterpriseInquiry(EnterpriseInquiryRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        $company = $user->company;

        $details = [
            'contact_name' => $data['contact_name'] ?? trim($user->first_name.' '.$user->last_name),
            'contact_email' => $data['contact_email'] ?? $user->email,
            'contact_phone' => $data['contact_phone'] ?? $user->phone,
            'monthly_loads' => $data['monthly_loads'] ?? null,
            'message' => $data['message'] ?? null,
        ];

        $salesEmail = config('subscriptions.sales_email');

        try {
            Mail::to($salesEmail)->send(new EnterpriseInquiryMail($company, $details));
        } catch (\Throwable $e) {
            // The enquiry itself still needs to reach someone, so it is logged
            // either way rather than failing back to the customer.
            Log::error('Enterprise enquiry email failed', [
                'company' => $company->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Enterprise plan enquiry', [
            'company' => $company->uuid,
            'company_name' => $company->company_name,
            'business_type' => $company->business_type,
            'dot_number' => $company->dot_number,
        ] + $details);

        return $this->success(
            null,
            'Thanks — our team will contact you about Enterprise pricing shortly.'
        );
    }
}
