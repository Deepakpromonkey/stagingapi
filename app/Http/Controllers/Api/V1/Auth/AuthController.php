<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\BillingException;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyLoginOtpRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\AuthService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;

class AuthController extends BaseController
{
    protected $authService;

    protected SubscriptionService $subscriptionService;

    public function __construct(AuthService $authService, SubscriptionService $subscriptionService)
    {
        $this->authService = $authService;
        $this->subscriptionService = $subscriptionService;
    }

    public function signup(SignupRequest $request)
    {
        $validated = $request->validated();

        $data = $this->authService->register($validated);

        return $this->success([
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),

            // The plan picked on the signup form, if any. Always present so
            // the client knows where to send the user next.
            'subscription' => $this->startSubscription(
                $data['user'],
                $validated['plan'] ?? null
            ),
        ], 'Registration Successful', 201);
    }

    /**
     * Open Checkout for the plan chosen at signup.
     *
     * Billing is deliberately not allowed to fail the registration: the
     * account and company already exist by this point, and losing them because
     * Stripe was briefly unreachable would be far worse than sending the user
     * to the pricing page a second time.
     */
    private function startSubscription($user, ?string $plan): array
    {
        if (! $plan) {
            return [
                'plan' => null,
                'checkout_url' => null,
                'contact_sales' => false,
                'message' => 'Choose a plan to activate the account.',
            ];
        }

        // Enterprise is quoted by sales, so there is nothing to check out.
        if ($this->subscriptionService->planConfig($plan)['contact_sales'] ?? false) {
            return [
                'plan' => $plan,
                'checkout_url' => null,
                'contact_sales' => true,
                'message' => 'Our team will contact you about Enterprise pricing.',
            ];
        }

        try {
            $checkout = $this->subscriptionService->startCheckout($user->company, $user, $plan);

            return [
                'plan' => $plan,
                'checkout_url' => $checkout['checkout_url'],
                'session_id' => $checkout['session_id'],
                'contact_sales' => false,
                'message' => 'Redirect to Stripe to finish your subscription.',
            ];
        } catch (BillingException $e) {
            Log::error('Checkout at signup failed', [
                'user' => $user->uuid,
                'plan' => $plan,
                'error' => $e->getMessage(),
            ]);

            return [
                'plan' => $plan,
                'checkout_url' => null,
                'contact_sales' => false,
                'message' => 'Your account is ready, but we could not open the payment page. Please choose a plan from your billing settings.',
            ];
        }
    }

    public function login(LoginRequest $request)
    {
        $data = $this->authService->login(
            $request->validated()
        );

        // OTP Required
        if ($data['requires_otp']) {

            return $this->success([
                'requires_otp' => true,
                'otp_session' => $data['otp_session'],
            ], 'OTP has been sent to your registered email.');
        }

        // Direct Login
        return $this->success([
            'requires_otp' => false,
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),
        ], 'Login Successful');
    }

    public function me()
    {
        $user = auth()->user()->load([
            'company.subscription',
            'roles.permissions',
            'permissions',
        ]);

        return $this->success(
            new AuthUserResource($user)
        );
    }

    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();

        return $this->success(
            null,
            'Logged out successfully.'
        );
    }

    public function verifyLoginOtp(VerifyLoginOtpRequest $request)
    {
        $data = $this->authService->verifyLoginOtp(
            $request->validated()
        );

        return $this->success([
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),
        ], 'Login Successful');
    }

    /**
     * Set a new password. Invited users land here first, with the temporary
     * password from their invitation email as `current_password`.
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $this->authService->changePassword(
            $request->user(),
            $request->validated()
        );

        return $this->success(
            new AuthUserResource($user),
            'Password changed successfully.'
        );
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $this->authService->updateProfile(
            $request->user(),
            $request->validated(),
            $request->file('profile_image')
        );

        return $this->success(
            new AuthUserResource($user),
            'Profile updated successfully.'
        );
    }
}
