<?php

namespace App\Models;

use App\Services\AuditLog;
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
     * How each outcome reads in the security audit trail.
     *
     * This table is the only thing keeping the carrier login history and the
     * trail in step, so a new outcome above belongs here too.
     */
    private const AUDIT_EVENTS = [
        self::OUTCOME_SUCCESS => [AuditLog::LOGIN_SUCCEEDED, null],
        self::OUTCOME_UNKNOWN_EMAIL => [AuditLog::LOGIN_FAILED, AuditLog::REASON_UNKNOWN_EMAIL],
        self::OUTCOME_BAD_PASSWORD => [AuditLog::LOGIN_FAILED, AuditLog::REASON_BAD_PASSWORD],
        self::OUTCOME_DISABLED => [AuditLog::LOGIN_FAILED, AuditLog::REASON_DISABLED],
        self::OUTCOME_OTP_SENT => [AuditLog::OTP_SENT, null],
        self::OUTCOME_OTP_FAILED => [AuditLog::OTP_FAILED, AuditLog::REASON_BAD_OTP],
    ];

    /**
     * Record an attempt from the current request.
     *
     * The row here is the per-account login history the portal shows the
     * carrier; the audit trail entry alongside it is the security record that
     * sits with the broker and driver events. Written together so the two
     * cannot drift apart.
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

        [$event, $reason] = self::AUDIT_EVENTS[$outcome] ?? [AuditLog::LOGIN_FAILED, $outcome];

        AuditLog::record(
            $event,
            $carrierUser,
            // Only a sign-in that worked has an actor to name; the rest are
            // attempts by someone who has not proved who they are.
            $outcome === self::OUTCOME_SUCCESS ? $carrierUser : null,
            array_filter([
                'email' => $email,
                'reason' => $reason,
                'portal' => 'carrier',
            ])
        );
    }
}
