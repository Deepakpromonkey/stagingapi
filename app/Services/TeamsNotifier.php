<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Posts audit trail events to a Microsoft Teams channel.
 *
 * The target is a Power Automate "Workflows" webhook, which takes an Adaptive
 * Card wrapped in a message envelope. The retired Office 365 connectors took a
 * MessageCard instead; that shape will not render here.
 *
 * Nothing in this class decides what is safe to send. The properties it is
 * handed have already been through AuditLog, which strips credentials before
 * anything is written down — so the card can only ever carry what the trail
 * itself carries.
 */
class TeamsNotifier
{
    /**
     * Events that read as something going wrong, and should be coloured so in
     * the channel. Everything else posts as plain text.
     */
    private const ATTENTION = [
        AuditLog::LOGIN_FAILED,
        AuditLog::OTP_FAILED,
        AuditLog::PASSWORD_CHANGE_REFUSED,
    ];

    /**
     * Events that change who can get in. Worth standing out from the routine
     * sign-in traffic even though nothing has failed.
     */
    private const WARNING = [
        AuditLog::USER_INVITED,
        AuditLog::INVITATION_RESENT,
        AuditLog::USER_REMOVED,
        AuditLog::ROLE_CHANGED,
        AuditLog::ACCOUNT_STATUS_CHANGED,
        AuditLog::PASSWORD_RESET_COMPLETED,
        AuditLog::RECORD_DELETED,
    ];

    /**
     * Is a channel configured and switched on?
     */
    public static function enabled(): bool
    {
        return (bool) config('services.teams.enabled')
            && filled(config('services.teams.webhook_url'));
    }

    /**
     * Should this particular event be posted?
     *
     * The default is everything, sign-ins included. See the note on
     * services.teams.events.
     */
    public static function shouldPost(string $event): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $events = config('services.teams.events', ['*']);

        return in_array('*', $events, true) || in_array($event, $events, true);
    }

    /**
     * Send one event to the channel.
     *
     * Returns false rather than throwing: an alert that cannot be delivered
     * must not take the queued job, or anything behind it, down with it. The
     * event is already safely recorded in activity_log by this point.
     */
    public function post(
        string $event,
        ?string $actor,
        ?string $subject,
        array $properties,
        string $occurredAt
    ): bool {
        if (! self::enabled()) {
            return false;
        }

        try {
            $response = Http::timeout((int) config('services.teams.timeout', 5))
                ->asJson()
                ->post(
                    (string) config('services.teams.webhook_url'),
                    $this->card($event, $actor, $subject, $properties, $occurredAt)
                );

            if ($response->failed()) {
                // The URL itself must not reach the log — it is a credential.
                Log::warning('Teams audit alert was rejected', [
                    'event' => $event,
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Teams audit alert could not be delivered', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The Adaptive Card, wrapped in the envelope Workflows expects.
     *
     * @return array<string, mixed>
     */
    private function card(
        string $event,
        ?string $actor,
        ?string $subject,
        array $properties,
        string $occurredAt
    ): array {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'text' => $this->headline($event),
                            'weight' => 'Bolder',
                            'size' => 'Medium',
                            'color' => $this->colour($event),
                            'wrap' => true,
                        ],
                        [
                            'type' => 'FactSet',
                            'facts' => $this->facts($event, $actor, $subject, $properties, $occurredAt),
                        ],
                    ],
                ],
            ]],
        ];
    }

    /**
     * "Sign-in failed" reads better in a channel than "login.failed".
     */
    private function headline(string $event): string
    {
        return match ($event) {
            AuditLog::LOGIN_SUCCEEDED => 'Signed in',
            AuditLog::LOGIN_FAILED => 'Sign-in refused',
            AuditLog::LOGOUT => 'Signed out',
            AuditLog::OTP_SENT => 'One-time code sent',
            AuditLog::OTP_FAILED => 'One-time code rejected',
            AuditLog::PASSWORD_RESET_REQUESTED => 'Password reset requested',
            AuditLog::PASSWORD_RESET_COMPLETED => 'Password reset completed',
            AuditLog::PASSWORD_CHANGED => 'Password changed',
            AuditLog::PASSWORD_CHANGE_REFUSED => 'Password change refused',
            AuditLog::USER_INVITED => 'Teammate invited',
            AuditLog::INVITATION_RESENT => 'Invitation resent',
            AuditLog::USER_REMOVED => 'Teammate removed',
            AuditLog::ROLE_CHANGED => 'Role changed',
            AuditLog::ACCOUNT_STATUS_CHANGED => 'Account switched on or off',
            AuditLog::RECORD_DELETED => 'Record deleted',
            default => Str::headline(str_replace('.', ' ', $event)),
        };
    }

    private function colour(string $event): string
    {
        return match (true) {
            in_array($event, self::ATTENTION, true) => 'Attention',
            in_array($event, self::WARNING, true) => 'Warning',
            default => 'Default',
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function facts(
        string $event,
        ?string $actor,
        ?string $subject,
        array $properties,
        string $occurredAt
    ): array {
        $facts = [
            ['title' => 'Event', 'value' => $event],
            ['title' => 'When', 'value' => $occurredAt],
        ];

        if ($actor) {
            $facts[] = ['title' => 'By', 'value' => $actor];
        }

        if ($subject) {
            $facts[] = ['title' => 'Account', 'value' => $subject];
        }

        // Whatever the trail kept, the channel can show. Long values are cut
        // so one stray user agent cannot push the card out of shape.
        foreach ($properties as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $facts[] = [
                'title' => Str::headline((string) $key),
                'value' => Str::limit(
                    is_scalar($value) ? (string) $value : json_encode($value),
                    300
                ),
            ];
        }

        return $facts;
    }
}
