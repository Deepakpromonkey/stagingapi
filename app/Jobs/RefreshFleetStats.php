<?php

namespace App\Jobs;

use App\Services\Vin\FleetStatsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recompute one carrier's fleet age off the request path.
 *
 * Dispatched by the profile endpoint the first time a carrier is viewed, and
 * again once its stored figures go stale. The viewer does not wait for it —
 * they see whatever was last computed, or blanks on a carrier nobody has
 * opened before.
 */
class RefreshFleetStats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * One carrier is refreshed at most once every fifteen minutes however many
     * brokers open the profile.
     */
    public int $uniqueFor = 900;

    public function __construct(public string $dot)
    {
        // Pinned rather than inherited: on the sync connection the profile
        // endpoint would run the whole fleet aggregation inside the request.
        $this->onConnection(config('vin.connection', 'database'));
        $this->onQueue(config('vin.queue', 'vin'));
    }

    public function handle(FleetStatsService $stats): void
    {
        $stats->refresh($this->dot);
    }

    public function uniqueId(): string
    {
        return 'fleet-stats:'.$this->dot;
    }
}
