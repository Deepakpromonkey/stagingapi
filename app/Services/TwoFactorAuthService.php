<?php

namespace App\Services;

use App\Models\LoginDevice;
use App\Models\LoginOtp;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\LoginOtpMail;
use Illuminate\Support\Facades\Mail;

class TwoFactorAuthService
{
    /**
     * Check whether OTP is required.
     */
    public function requiresOtp(User $user, ?string $deviceUuid): bool
    {
        // 2FA disabled
        if (! $user->two_factor_enabled) {
            return false;
        }

        // New device
        if (! $deviceUuid) {
            return true;
        }

        // Check trusted device
        return ! TrustedDevice::where('user_id', $user->id)
            ->where('device_uuid', $deviceUuid)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Generate Login OTP
     */
    public function generateOtp(User $user, string $ipAddress): array
    {
        // Delete previous unused OTPs
        LoginOtp::where('user_id', $user->id)
            ->delete();

        $otp = (string) random_int(100000, 999999);

        $otpSession = (string) Str::uuid();

        LoginOtp::create([
            'user_id' => $user->id,
            'otp_session' => $otpSession,
            'otp' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'ip_address' => $ipAddress,
        ]);
try {
    Mail::to($user->email)->send(new LoginOtpMail($otp));
} catch (\Exception $e) {
    Log::error('OTP email failed', [
        'email' => $user->email,
        'error' => $e->getMessage(),
    ]);
}

        // Only log in local environment
        if (app()->environment('local')) {

            Log::info('========== LOGIN OTP ==========');

            Log::info('Email', [
                'email' => $user->email,
            ]);

            Log::info('OTP', [
                'otp' => $otp,
            ]);

            Log::info('OTP Session', [
                'otp_session' => $otpSession,
            ]);

            Log::info('===============================');
        }

        return [
            'otp_session' => $otpSession,
        ];
    }

    /**
     * Verify Login OTP
     */
    public function verifyOtp(array $data): User
    {
        $loginOtp = LoginOtp::with([
            'user.company',
            'user.roles',
        ])
            ->where('otp_session', $data['otp_session'])
            ->first();

        if (! $loginOtp) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP session.'],
            ]);
        }

        if ($loginOtp->expires_at->isPast()) {
            $loginOtp->delete();

            throw ValidationException::withMessages([
                'otp' => ['OTP has expired.'],
            ]);
        }

        if ($loginOtp->attempts >= 5) {

            $loginOtp->delete();

            throw ValidationException::withMessages([
                'otp' => ['Maximum OTP attempts exceeded.'],
            ]);
        }

        if (! Hash::check($data['otp'], $loginOtp->otp)) {

            $loginOtp->increment('attempts');

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP.'],
            ]);
        }

        return DB::transaction(function () use ($loginOtp) {

            $user = $loginOtp->user;

            if (! $user->status) {

                throw ValidationException::withMessages([
                    'email' => ['Your account has been deactivated.'],
                ]);
            }

            // OTP consumed
            $loginOtp->delete();

            return $user;
        });
    }

    public function verifyLoginOtp(array $data)
    {
        $user = $this->twoFactorAuthService->verifyOtp($data);

        // One Device Login
        $user->tokens()->delete();

        // Update Login Information
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        // Remember Device
        if (! empty($data['remember_device']) && ! empty($data['device_uuid'])) {

            TrustedDevice::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_uuid' => $data['device_uuid'],
                ],
                [
                    'device_name' => request()->userAgent(),
                    'browser' => null,
                    'platform' => null,
                    'ip_address' => request()->ip(),
                    'last_used_at' => now(),
                    'expires_at' => now()->addYear(),
                ]
            );
        }

        // Login History
        LoginDevice::create([
            'user_id' => $user->id,
            'device_name' => request()->userAgent(),
            'browser' => null,
            'platform' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'is_current' => true,
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('broker-api')->plainTextToken;

        return [
            'token' => $token,
            'user' => $user->fresh()->load('company', 'roles'),
        ];
    }
}
