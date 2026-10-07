<?php

namespace App\Jobs;

use App\Models\Eld\EldConnection;
use App\Services\Eld\EldSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pull one connection's fleet from Terminal.
 *
 * Always off the request path. The first pass runs the moment a carrier
 * finishes the Link flow, and the wizard says so rather than showing a tick
 * beside an empty fleet — the carrier reaches the next step while their trucks
 * are still arriving.
 */
class SyncEldConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * A location pass is a call per truck, so a large fleet's first sync is not
     * quick. Still bounded, so a provider that hangs cannot hold a worker.
     */
    public int $timeout = 900;

    /**
     * One connection syncs at most once every ten minutes, however many brokers
     * or webhooks ask for it. Terminal bills for what is read, so a duplicate
     * pass is not merely wasted work.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public int $connectionId,
        public bool $initial = false
    ) {
        // Pinned for the same reason the VIN jobs are: QUEUE_CONNECTION is
        // `sync` on this box, and inheriting it would run a full fleet import
        // inside the carrier's HTTP request.
        $this->onConnection(config('vin.connection', 'database'));
        $this->onQueue('eld');
    }

    public function handle(EldSyncService $sync): void
    {
        $connection = EldConnection::find($this->connectionId);

        if (! $connection) {
            return;
        }

        $sync->sync($connection, $this->initial);
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    /**
     * Backoff rather than immediate retries: the usual reason a sync fails is a
     * provider Terminal is proxying being briefly unavailable, and hammering it
     * helps nobody.
     */
    public function backoff(): array
    {
        return [60, 300];
    }
}
