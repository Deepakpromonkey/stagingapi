<?php

namespace App\Jobs;

use App\Models\EldConnection;
use App\Services\Eld\EldSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pull one carrier's fleet from Terminal.
 *
 * Queued rather than inline because a first sync backfills a month of duty
 * status logs for a whole fleet, and the carrier who triggered it is sitting on
 * a redirect back from Terminal Link.
 *
 * QUEUE_CONNECTION is `sync` on this box, so the connection is named explicitly
 * at dispatch — see dispatchFor(). Without it this would run inside the request
 * it was meant to stay out of.
 */
class SyncEldConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** A fleet backfill is not quick. */
    public int $timeout = 600;

    /**
     * Terminal rate-limits, and a 429 during a backfill is ordinary rather than
     * exceptional. Back off rather than burn the attempts.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $connectionId
    ) {}

    /** One sync per connection in flight at a time. */
    public function uniqueId(): string
    {
        return 'eld-sync-'.$this->connectionId;
    }

    /**
     * Dispatch onto the queue the scheduler's worker actually drains.
     *
     * The `database` connection is named because QUEUE_CONNECTION is `sync`
     * here; the queue is named so a fleet backfill cannot sit in front of a
     * broker waiting on mail. Both must stay in step with routes/console.php.
     */
    public static function dispatchFor(EldConnection $connection): void
    {
        static::dispatch($connection->id)
            ->onConnection('database')
            ->onQueue(config('terminal.queue', 'eld'));
    }

    public function handle(EldSyncService $sync): void
    {
        $connection = EldConnection::find($this->connectionId);

        // Deleted between dispatch and run, or revoked by the carrier. Neither
        // is a failure worth retrying.
        if (! $connection || ! $connection->isUsable()) {
            return;
        }

        $sync->sync($connection);
    }
}
