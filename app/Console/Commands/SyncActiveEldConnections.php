<?php

namespace App\Console\Commands;

use App\Jobs\SyncEldConnection;
use App\Models\Eld\EldConnection;
use App\Models\Shipment;
use Illuminate\Console\Command;

/**
 * Refresh the fleets that are actually carrying something.
 *
 * Terminal bills for data synced, not for entities held, so polling every
 * carrier who ever completed onboarding would run up a bill for fleets nobody
 * is looking at. A connection is refreshed while its carrier has a shipment
 * that is live or about to be — and goes quiet again afterwards.
 *
 * `--all` exists for a one-off catch-up, and is deliberately not what the
 * scheduler runs.
 */
class SyncActiveEldConnections extends Command
{
    protected $signature = 'eld:sync-active
                            {--all : Every connected carrier, not just those with active loads}
                            {--limit=500 : Most connections to queue in one pass}';

    protected $description = 'Queue ELD fleet syncs for carriers with live loads';

    public function handle(): int
    {
        $query = EldConnection::where('status', EldConnection::STATUS_CONNECTED);

        if (! $this->option('all')) {
            $dots = $this->carriersWithLiveLoads();

            if ($dots === []) {
                $this->info('No carriers with live loads; nothing to sync.');

                return self::SUCCESS;
            }

            $query->whereIn('carrier_dot_number', $dots);
        }

        $queued = 0;

        $query->orderBy('last_sync_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (EldConnection $connection) use (&$queued) {
                SyncEldConnection::dispatch($connection->id);
                $queued++;
            });

        $this->info("Queued {$queued} ELD sync".($queued === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }

    /**
     * Carriers a broker is relying on right now.
     *
     * `active` is the only shipment status that means a truck is under load —
     * draft has not been tendered, and completed and cancelled are both done
     * with. A carrier whose loads are all finished stops being polled, which is
     * the whole point of syncing this way.
     */
    private function carriersWithLiveLoads(): array
    {
        return Shipment::query()
            ->whereNotNull('carrier_dot')
            ->where('status', 'active')
            ->pluck('carrier_dot')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
