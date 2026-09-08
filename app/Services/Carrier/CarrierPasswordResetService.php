<?php

namespace App\Services\Carrier;

use App\Mail\PasswordResetOtpMail;
use App\Models\CarrierLoginOtp;
use App\Models\CarrierPasswordResetOtp;
use App\Models\CarrierUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgot-password for the carrier portal — the same three steps as the broker
 * side (PasswordResetService), against carrier_users.
 */
class CarrierPasswordResetService
{
    /** Minutes an emailed OTP stays valid. */
    protected int $otpTtl = 10;

    /** Minutes the post-verification reset token stays valid. */
    protected int $resetTokenTtl = 15;

    protected int $maxAttempts = 5;

    /**
     * Step 1 — email a reset OTP.
     *
     * The response is identical whether or not the address belongs to an
     * account, so this endpoint cannot be used to discover carrier logins.
     */
    public function sendOtp(string $email, ?string $ipAddress): array
    {
        $carrierUser = CarrierUser::where('email', strtolower(trim($email)))->first();

        if (! $carrierUser || ! $carrierUser->status) {
            // Same shape as the success path, but nothing is stored, so any OTP
            // submitted against this session will fail.
            return ['otp_session' => (string) Str::uuid()];
        }

        // Only one live reset request per account.
        CarrierPasswordResetOtp::where('carrier_user_id', $carrierUser->id)->delete();

        $otp = (string) random_int(100000, 999999);

        $otpSession = (string) Str::uuid();

        CarrierPasswordResetOtp::create([
            'carrier_user_id' => $carrierUser->id,
            'otp_session' => $otpSession,
            'otp' => Hash::make($otp),
            'expires_at' => now()->addMinutes($this->otpTtl),
            'attempts' => 0,
            'ip_address' => $ipAddress,
        ]);

        try {
            Mail::to($carrierUser->email)->send(new PasswordResetOtpMail($otp, $this->otpTtl));
        } catch (\Throwable $e) {
            Log::error('Carrier password reset OTP email failed', [
                'carrier_user' => $carrierUser->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment('local')) {
            Log::info('====== CARRIER PASSWORD RESET OTP ======');
            Log::info('Email', ['email' => $carrierUser->email]);
            Log::info('OTP', ['otp' => $otp]);
            Log::info('OTP Session', ['otp_session' => $otpSession]);
            Log::info('========================================');
        }

        return ['otp_session' => $otpSession];
    }

    /**
     * Step 2 — verify the OTP and hand back a single-use reset token.
     */
    public function verifyOtp(array $data): array
    {
        $record = CarrierPasswordResetOtp::where('otp_session', $data['otp_session'])->first();

        if (! $record || $record->isExpired()) {
            $record?->delete();

            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired OTP.'],
            ]);
        }

        if ($record->attempts >= $this->maxAttempts) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['Maximum OTP attempts exceeded. Please request a new code.'],
            ]);
        }

        if (! Hash::check($data['otp'], $record->otp)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP.'],
            ]);
        }

        $resetToken = Str::random(64);

        $record->update([
            'verified_at' => now(),
            'reset_token' => hash('sha256', $resetToken),
            'expires_at' => now()->addMinutes($this->resetTokenTtl),
            'attempts' => 0,
        ]);

        return [
            'reset_token' => $resetToken,
            'expires_in_minutes' => $this->resetTokenTtl,
        ];
    }

    /**
     * Step 3 — set the new password and sign every session out.
     */
    public function resetPassword(array $data): CarrierUser
    {
        $record = CarrierPasswordResetOtp::with('carrierUser')
            ->where('reset_token', hash('sha256', $data['reset_token']))
            ->whereNotNull('verified_at')
            ->first();

        if (! $record || $record->isExpired()) {
            $record?->delete();

            throw ValidationException::withMessages([
                'reset_token' => ['This reset request is invalid or has expired. Please start again.'],
            ]);
        }

        $carrierUser = $record->carrierUser;

        if (! $carrierUser || ! $carrierUser->status) {
            throw ValidationException::withMessages([
                'reset_token' => ['This account is not available.'],
            ]);
        }

        return DB::transaction(function () use ($record, $carrierUser, $data) {

            $carrierUser->forceFill([
                'password' => Hash::make($data['password']),
                // A password they chose themselves clears the temporary hold.
                'must_change_password' => false,
                'last_password_changed_at' => now(),
            ])->save();

            // A password change invalidates every existing session and any
            // pending login challenge.
            $carrierUser->tokens()->delete();

            CarrierLoginOtp::where('carrier_user_id', $carrierUser->id)->delete();

            $record->delete();

            return $carrierUser;
        });
    }
}
