<?php

namespace App\Jobs;

use App\Services\TeamsNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries one audit event to the Teams channel.
 *
 * Queued rather than sent inline: every sign-in raises an event, and an
 * outbound call to Microsoft on the login path would put their availability
 * in front of ours. QUEUE_CONNECTION is `sync` here, so the connection is
 * named explicitly — see the queue:work note in routes/console.php.
 *
 * Only scalars are held, never models, so a job sitting in the queue cannot
 * be changed by whatever happens to the row afterwards, and a user deleted
 * in the meantime does not fail the job on unserialize.
 */
class NotifyTeamsOfAuditEvent implements ShouldQueue
{
    use Queueable;

    /** A channel that is down is not worth more than a few attempts. */
    public $tries = 3;

    /** Seconds between attempts: give a blip time to pass, then give up. */
    public $backoff = [30, 120];

    public function __construct(
        private readonly string $event,
        private readonly ?string $actor,
        private readonly ?string $subject,
        private readonly array $properties,
        private readonly string $occurredAt,
    ) {
        $this->onConnection('database')->onQueue('audit');
    }

    public function handle(TeamsNotifier $teams): void
    {
        $teams->post(
            $this->event,
            $this->actor,
            $this->subject,
            $this->properties,
            $this->occurredAt
        );
    }
}
