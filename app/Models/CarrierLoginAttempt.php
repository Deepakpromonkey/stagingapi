<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * One row per carrier portal sign-in attempt, with the originating IP.
 */
class CarrierLoginAttempt extends Model
{
    /** Signed in; a token was issued. */
    public const OUTCOME_SUCCESS = 'success';

    /** No account for the email that was typed. */
    public const OUTCOME_UNKNOWN_EMAIL = 'unknown_email';

    /** Account exists, password did not match. */
    public const OUTCOME_BAD_PASSWORD = 'bad_password';

    /** Credentials were right but the account is switched off. */
    public const OUTCOME_DISABLED = 'disabled';

    /** Credentials were right; an OTP was sent and is awaited. */
    public const OUTCOME_OTP_SENT = 'otp_sent';

    /** A code was submitted and rejected. */
    public const OUTCOME_OTP_FAILED = 'otp_failed';

    protected $fillable = [
        'carrier_user_id',
        'email',
        'outcome',
        'ip_address',
        'user_agent',
        'device_uuid',
    ];

    public function carrierUser()
    {
        return $this->belongsTo(CarrierUser::class, 'carrier_user_id');
    }

    /**
     * Record an attempt from the current request.
     *
     * Auditing must never be the reason a login fails, so a write problem here
     * is logged and swallowed rather than thrown.
     */
    public static function record(
        string $outcome,
        ?string $email = null,
        ?CarrierUser $carrierUser = null,
        ?string $deviceUuid = null
    ): void {
        try {
            static::create([
                'carrier_user_id' => $carrierUser?->id,
                'email' => $email ? mb_substr($email, 0, 255) : null,
                'outcome' => $outcome,
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
                'device_uuid' => $deviceUuid,
            ]);
        } catch (\Throwable $e) {
            Log::error('Carrier login attempt could not be recorded', [
                'outcome' => $outcome,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
