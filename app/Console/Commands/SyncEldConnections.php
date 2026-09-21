<?php

namespace App\Console\Commands;

use App\Jobs\SyncEldConnection;
use App\Models\EldConnection;
use Illuminate\Console\Command;

/**
 * Resync telematics connections whose data has gone stale.
 *
 * Terminal's webhooks are the primary signal and this is the backstop: a
 * delivery that never arrived, an endpoint that was down, a connection whose
 * provider simply went quiet. Without it a fleet can sit unchanged
 * indefinitely and nothing would say so.
 *
 * Capped per run so a large account cannot turn one tick of the scheduler into
 * an unbounded fan-out of jobs.
 */
class SyncEldConnections extends Command
{
    protected $signature = 'eld:sync
        {--stale : Only connections not synced within the configured window}
        {--limit=200 : Most connections to queue in one run}
        {--connection= : A single terminal connection id, ignoring staleness}';

    protected $description = 'Queue Terminal ELD syncs for connected carriers';

    public function handle(): int
    {
        if (! config('terminal.enabled')) {
            $this->warn('Terminal is disabled; nothing to sync.');

            return self::SUCCESS;
        }

        $query = EldConnection::query()
            ->where('status', EldConnection::STATUS_CONNECTED);

        if ($single = $this->option('connection')) {
            $query->where('terminal_connection_id', $single);
        } elseif ($this->option('stale')) {
            $cutoff = now()->subMinutes((int) config('terminal.resync_after_minutes', 360));

            // A connection that has never synced is stale by definition — a
            // null last_synced_at is exactly the case worth catching, and
            // a plain `<` comparison would skip it.
            $query->where(fn ($q) => $q
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', $cutoff)
            );
        }

        $connections = $query
            ->orderByRaw('last_synced_at is null desc')
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($connections as $connection) {
            SyncEldConnection::dispatchFor($connection);
        }

        $this->info("Queued {$connections->count()} ELD sync(s).");

        return self::SUCCESS;
    }
}
