<?php

namespace App\Services\Carrier;

use App\Mail\LoginOtpMail;
use App\Models\CarrierLoginOtp;
use App\Models\CarrierTrustedDevice;
use App\Models\CarrierUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login OTP and remembered devices for the carrier portal.
 *
 * The broker equivalent is TwoFactorAuthService; the two are deliberately
 * separate because every table on this side is keyed to `carrier_users`.
 */
class CarrierTwoFactorService
{
    /** Minutes an emailed login code stays valid. */
    protected int $otpTtl = 10;

    protected int $maxAttempts = 5;

    /** How long "remember this device" holds before the code is asked again. */
    protected int $trustedDeviceDays = 30;

    /**
     * Whether this sign-in has to clear an emailed code.
     */
    public function requiresOtp(CarrierUser $carrierUser, ?string $deviceUuid): bool
    {
        if (! $carrierUser->two_factor_enabled) {
            return false;
        }

        // No device identity offered — treat as a device we have never seen.
        if (! $deviceUuid) {
            return true;
        }

        return ! $this->trustedDevice($carrierUser, $deviceUuid);
    }

    /**
     * The live trust record for this device, if there is one.
     */
    public function trustedDevice(CarrierUser $carrierUser, ?string $deviceUuid): ?CarrierTrustedDevice
    {
        if (! $deviceUuid) {
            return null;
        }

        return CarrierTrustedDevice::where('carrier_user_id', $carrierUser->id)
            ->where('device_uuid', $deviceUuid)
            ->live()
            ->first();
    }

    /**
     * Mail a fresh code and open a challenge session.
     *
     * @return array{otp_session: string, expires_in_minutes: int}
     */
    public function generateOtp(CarrierUser $carrierUser, ?string $deviceUuid = null): array
    {
        // One live challenge per account: asking for a new code invalidates the
        // one before it.
        CarrierLoginOtp::where('carrier_user_id', $carrierUser->id)->delete();

        $otp = (string) random_int(100000, 999999);

        $otpSession = (string) Str::uuid();

        CarrierLoginOtp::create([
            'carrier_user_id' => $carrierUser->id,
            'otp_session' => $otpSession,
            'otp' => Hash::make($otp),
            'expires_at' => now()->addMinutes($this->otpTtl),
            'attempts' => 0,
            'device_uuid' => $deviceUuid,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);

        // A mail failure must not strand the carrier without a session record —
        // they can ask for another code.
        try {
            Mail::to($carrierUser->email)->send(new LoginOtpMail($otp));
        } catch (\Throwable $e) {
            Log::error('Carrier login OTP email failed', [
                'carrier_user' => $carrierUser->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment('local')) {
            Log::info('========== CARRIER LOGIN OTP ==========');
            Log::info('Email', ['email' => $carrierUser->email]);
            Log::info('OTP', ['otp' => $otp]);
            Log::info('OTP Session', ['otp_session' => $otpSession]);
            Log::info('=======================================');
        }

        return [
            'otp_session' => $otpSession,
            'expires_in_minutes' => $this->otpTtl,
        ];
    }

    /**
     * Check a submitted code and consume the challenge.
     *
     * @return array{carrier_user: CarrierUser, device_uuid: ?string}
     */
    public function verifyOtp(array $data): array
    {
        $record = CarrierLoginOtp::with('carrierUser')
            ->where('otp_session', $data['otp_session'])
            ->first();

        if (! $record || $record->isExpired()) {
            $record?->delete();

            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired OTP.'],
            ]);
        }

        if ($record->attempts >= $this->maxAttempts) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['Maximum OTP attempts exceeded. Please sign in again.'],
            ]);
        }

        if (! Hash::check($data['otp'], $record->otp)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP.'],
            ]);
        }

        $carrierUser = $record->carrierUser;

        if (! $carrierUser) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['This account is no longer available.'],
            ]);
        }

        if (! $carrierUser->status) {
            $record->delete();

            throw ValidationException::withMessages([
                'email' => ['This account has been disabled. Please contact your broker.'],
            ]);
        }

        // The device the challenge was raised for, unless the client names one.
        $deviceUuid = $data['device_uuid'] ?? $record->device_uuid;

        // Consumed — a code is good for exactly one sign-in.
        $record->delete();

        return [
            'carrier_user' => $carrierUser,
            'device_uuid' => $deviceUuid,
        ];
    }

    /**
     * Remember this device so the next sign-in from it skips the code.
     */
    public function trustDevice(CarrierUser $carrierUser, string $deviceUuid): CarrierTrustedDevice
    {
        return CarrierTrustedDevice::updateOrCreate(
            [
                'carrier_user_id' => $carrierUser->id,
                'device_uuid' => $deviceUuid,
            ],
            [
                'device_name' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
                'browser' => null,
                'platform' => null,
                'ip_address' => request()->ip(),
                'last_used_at' => now(),
                'expires_at' => now()->addDays($this->trustedDeviceDays),
            ]
        );
    }

    /**
     * Devices currently trusted by this carrier.
     */
    public function trustedDevices(CarrierUser $carrierUser)
    {
        return CarrierTrustedDevice::where('carrier_user_id', $carrierUser->id)
            ->live()
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * Drop a remembered device, so it is challenged again next time.
     */
    public function forgetDevice(CarrierUser $carrierUser, string $deviceUuid): void
    {
        $deleted = CarrierTrustedDevice::where('carrier_user_id', $carrierUser->id)
            ->where('device_uuid', $deviceUuid)
            ->delete();

        if (! $deleted) {
            throw ValidationException::withMessages([
                'device_uuid' => ['This device is not on your trusted list.'],
            ]);
        }
    }
}
