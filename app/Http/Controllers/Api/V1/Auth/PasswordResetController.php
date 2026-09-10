<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use App\Services\PasswordResetService;

class PasswordResetController extends BaseController
{
    public function __construct(
        protected PasswordResetService $passwordResetService
    ) {}

    /**
     * Step 1 — email an OTP to the address the user typed.
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $data = $this->passwordResetService->sendOtp(
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
    public function verifyResetOtp(VerifyPasswordResetOtpRequest $request)
    {
        $data = $this->passwordResetService->verifyOtp(
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
    public function resetPassword(ResetPasswordRequest $request)
    {
        $this->passwordResetService->resetPassword(
            $request->validated()
        );

        return $this->success(
            null,
            'Password reset successfully. Please log in with your new password.'
        );
    }
}
