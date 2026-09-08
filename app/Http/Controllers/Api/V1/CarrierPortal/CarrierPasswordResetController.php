<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\CarrierPortal\CarrierForgotPasswordRequest;
use App\Http\Requests\CarrierPortal\CarrierResetPasswordRequest;
use App\Http\Requests\CarrierPortal\CarrierVerifyPasswordResetOtpRequest;
use App\Services\Carrier\CarrierPasswordResetService;

/**
 * Forgot-password for the carrier portal. Public by definition — a carrier who
 * has lost their password cannot hold a token.
 */
class CarrierPasswordResetController extends BaseController
{
    public function __construct(
        protected CarrierPasswordResetService $carrierPasswordResetService
    ) {}

    /**
     * Step 1 — email an OTP to the address the carrier typed.
     */
    public function forgotPassword(CarrierForgotPasswordRequest $request)
    {
        $data = $this->carrierPasswordResetService->sendOtp(
            $request->validated()['email'],
            $request->ip()
        );

        return $this->success([
            'otp_session' => $data['otp_session'],
        ], 'If an account exists for this email, a reset code has been sent.');
    }

    /**
     * Step 2 — verify the OTP and hand back a single-use reset token.
     */
    public function verifyResetOtp(CarrierVerifyPasswordResetOtpRequest $request)
    {
        $data = $this->carrierPasswordResetService->verifyOtp(
            $request->validated()
        );

        return $this->success([
            'reset_token' => $data['reset_token'],
            'expires_in_minutes' => $data['expires_in_minutes'],
        ], 'OTP verified. You can now set a new password.');
    }

    /**
     * Step 3 — set the new password.
     */
    public function resetPassword(CarrierResetPasswordRequest $request)
    {
        $this->carrierPasswordResetService->resetPassword(
            $request->validated()
        );

        return $this->success(
            null,
            'Password reset successfully. Please log in with your new password.'
        );
    }
}
