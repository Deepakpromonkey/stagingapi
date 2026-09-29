<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The security audit trail: who did what, to whom, from where.
 *
 * Rows live in activity_log and are kept for a year (config/activitylog.php).
 * Everything here goes in one log name so the trail can be read on its own,
 * apart from any other use of the activity log.
 */
class AuditLog
{
    /** The activity log name reserved for security events. */
    public const LOG_NAME = 'audit';

    /* Sign in and out. */
    public const LOGIN_SUCCEEDED = 'login.succeeded';
    public const LOGIN_FAILED = 'login.failed';
    public const LOGOUT = 'logout';

    /* Second factor. */
    public const OTP_SENT = 'otp.sent';
    public const OTP_FAILED = 'otp.failed';

    /* Passwords. */
    public const PASSWORD_RESET_REQUESTED = 'password.reset_requested';
    public const PASSWORD_RESET_COMPLETED = 'password.reset_completed';
    public const PASSWORD_CHANGED = 'password.changed';

    /* Who can get in. */
    public const USER_INVITED = 'user.invited';
    public const INVITATION_RESENT = 'user.invitation_resent';
    public const USER_REMOVED = 'user.removed';
    public const ROLE_CHANGED = 'user.role_changed';

    /* Anything else worth keeping a year. */
    public const RECORD_DELETED = 'record.deleted';

    /**
     * Why a sign-in was refused. Kept as short codes rather than sentences so
     * the trail can be counted and alerted on.
     */
    public const REASON_UNKNOWN_EMAIL = 'unknown_email';
    public const REASON_BAD_PASSWORD = 'bad_password';
    public const REASON_DISABLED = 'disabled';
    public const REASON_BAD_OTP = 'bad_otp';

    /**
     * Property names that must never be written to the trail, whatever the
     * caller passes. config/activitylog.php holds the same list for the
     * attribute changes the package records by itself.
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'otp',
        'otp_code',
        'code',
        'token',
        'api_token',
        'access_token',
        'plain_text_token',
        'secret',
    ];

    /**
     * Record one event, with the current request's origin attached.
     *
     * $subject is what was acted on, $causer is who did it. Leave $causer null
     * for the signed-in user; pass it explicitly when there is no session yet,
     * as on a failed sign-in.
     *
     * Auditing must never be the reason a request fails, so a write problem
     * here is logged and swallowed rather than thrown. The same choice is made
     * in CarrierLoginAttempt::record().
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = []
    ): void {
        try {
            $logger = activity(self::LOG_NAME)
                ->event($event)
                ->withProperties(self::context($properties));

            if ($subject) {
                $logger->performedOn($subject);
            }

            // causedBy(null) would fall back to the signed-in user, which is
            // wrong for an event that has no actor, so ask for anonymous.
            $causer ? $logger->causedBy($causer) : $logger->causedByAnonymous();

            $logger->log($event);
        } catch (\Throwable $e) {
            Log::error('Audit trail entry could not be recorded', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an event caused by whoever is signed in on this request.
     */
    public static function recordByCurrentUser(
        string $event,
        ?Model $subject = null,
        array $properties = []
    ): void {
        self::record($event, $subject, auth()->user(), $properties);
    }

    /**
     * Add where the request came from, and drop anything secret.
     */
    private static function context(array $properties): array
    {
        foreach (array_keys($properties) as $key) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                unset($properties[$key]);
            }
        }

        if (isset($properties['email'])) {
            $properties['email'] = mb_substr((string) $properties['email'], 0, 255);
        }

        $request = request();

        return $properties + array_filter([
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
            'route' => $request?->method().' '.$request?->path(),
        ]);
    }
}
