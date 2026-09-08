<?php

namespace App\Console\Commands;

use App\Models\CarrierFleetStat;
use App\Services\Vin\FleetStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute average power-unit and trailer age per carrier from the decoded
 * VIN patterns.
 *
 * These two numbers are what the fleet cards on the carrier profile show. They
 * are computed here rather than on the request path because the aggregate has
 * to look at every inspection a carrier has ever had — a carrier with 15k of
 * them cannot pay that per page view, and the profile endpoint only returns
 * the most recent 25 rows anyway, so an average taken there would be an
 * average of 25 inspections rather than of the fleet.
 *
 *   php artisan carrier:refresh-fleet-stats --dot=1234567   one carrier
 *   php artisan carrier:refresh-fleet-stats --stale         everything past its TTL
 *   php artisan carrier:refresh-fleet-stats --active        carriers viewed recently
 */
class RefreshCarrierFleetStats extends Command
{
    protected $signature = 'carrier:refresh-fleet-stats
        {--dot=* : Specific DOT numbers}
        {--stale : Every carrier whose stats are older than the configured TTL}
        {--active : Carriers with an inspection in the last two years}
        {--limit=1000 : Maximum carriers per run}';

    protected $description = 'Recompute per-carrier fleet age from decoded VIN patterns';

    public function handle(FleetStatsService $stats): int
    {
        $dots = $this->targets();

        if (! $dots) {
            $this->components->warn('No carriers selected. Pass --dot, --stale or --active.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $computed = 0;

        $this->components->info(number_format(count($dots)).' carriers to refresh.');

        foreach ($dots as $dot) {
            try {
                $stats->refresh((string) $dot);
                $computed++;
            } catch (\Throwable $e) {
                $this->components->warn("DOT {$dot}: {$e->getMessage()}");
            }

            if ($computed % 100 === 0) {
                $this->components->twoColumnDetail('Refreshed', number_format($computed));
            }
        }

        $this->components->info(sprintf(
            '%s carriers refreshed in %ds.',
            number_format($computed),
            (int) (microtime(true) - $started),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function targets(): array
    {
        $limit = max(1, (int) $this->option('limit'));

        if ($dots = $this->option('dot')) {
            return array_map('strval', $dots);
        }

        if ($this->option('stale')) {
            $cutoff = now()->subSeconds((int) config('vin.fleet_stats_ttl', 86400));

            return CarrierFleetStat::query()
                ->where(fn ($query) => $query
                    ->whereNull('computed_at')
                    ->orWhere('computed_at', '<', $cutoff))
                ->orderBy('computed_at')
                ->limit($limit)
                ->pluck('dot_number')
                ->all();
        }

        if ($this->option('active')) {
            /*
             * Carriers inspected in the last two years. insp_date is a varchar
             * in the feed's '24-APR-24' format, so it has to be parsed before
             * it can be compared — see docs/carrier-database-notes.md.
             */
            $since = now()->subYears(2)->toDateString();

            return collect(DB::connection('external_db')->select("
                SELECT DISTINCT dot_number
                FROM inspections
                WHERE STR_TO_DATE(insp_date, '%d-%b-%y') >= ?
                LIMIT {$limit}
            ", [$since]))->pluck('dot_number')->map('strval')->all();
        }

        return [];
    }
}
