<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Auth\SendSignupOtpRequest;
use App\Http\Requests\Auth\VerifySignupOtpRequest;
use App\Models\SignupOtp;
use App\Services\SignupOtpService;

/**
 * Verification of the email address and phone number on the signup form.
 *
 * Public by necessity — there is no account to authenticate against yet — so
 * both routes are rate limited at the router. The cost of abuse here is real:
 * every phone send is an SMS somebody pays for.
 */
class SignupOtpController extends BaseController
{
    public function __construct(private SignupOtpService $otpService) {}

    public function send(SendSignupOtpRequest $request)
    {
        $data = $request->validated();

        $result = $this->otpService->send(
            $data['channel'],
            $data['destination'],
            $request->ip()
        );

        return $this->success([
            'otp_session' => $result['otp_session'],
            'channel' => $data['channel'],

            // Echoed back normalised so the form can show exactly where the
            // code went — a number typed as 555-0100 arrives as +15550100,
            // and a user staring at the wrong one needs to see that.
            'destination' => $data['channel'] === SignupOtp::CHANNEL_EMAIL
                ? $result['destination']
                : $this->maskPhone($result['destination']),

            'expires_at' => $result['expires_at'],
            'resend_after_seconds' => (int) config('signup.resend_cooldown_seconds', 30),
        ], 'Verification code sent.');
    }

    public function verify(VerifySignupOtpRequest $request)
    {
        $data = $request->validated();

        $result = $this->otpService->verify($data['otp_session'], $data['otp']);

        return $this->success([
            'channel' => $result['channel'],

            /*
            | Held by the client and presented at signup. It proves one
            | address, so the form must keep the email and phone tokens apart
            | rather than treating either as "verified".
            */
            'verification_token' => $result['verification_token'],
        ], 'Verified.');
    }

    /**
     * Shown back to the user, so the last four stay readable — it is the part
     * that lets them recognise their own number.
     */
    private function maskPhone(string $phone): string
    {
        return strlen($phone) <= 4
            ? $phone
            : str_repeat('*', strlen($phone) - 4).substr($phone, -4);
    }
}
