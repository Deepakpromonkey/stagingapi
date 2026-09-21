<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds one provider login quietly covering two of our carriers.
 *
 * Terminal resolves a connection from the provider account, so two carriers
 * sharing a Motive or Samsara login come back as ONE connection holding both
 * fleets. Nothing errors. The vehicles simply arrive under whichever carrier
 * happened to connect, and the other carrier's trucks are attributed to them.
 *
 * Rare enough that building for it would be waste — Terminal's own advice was
 * to make it detectable instead and fix the account on the day it appears. So
 * this looks for the symptom rather than the cause: the same VIN sitting on
 * connections that belong to two different DOT numbers. A truck can only be in
 * one fleet, so that overlap is either a shared login or a stale connection
 * that never had its vehicles cleared.
 *
 * The fix, when it fires, is two connections forked with an external id and
 * non-overlapping vehicle include lists, set BEFORE the first sync — metering
 * is per vehicle per connection, so two connections cost nothing extra as long
 * as no truck lands on both.
 *
 *   php artisan eld:detect-shared-logins
 *   php artisan eld:detect-shared-logins --fail-on-find   (for the scheduler)
 */
class DetectSharedEldLogins extends Command
{
    protected $signature = 'eld:detect-shared-logins
                            {--fail-on-find : Exit non-zero when an overlap is found}';

    protected $description = 'Flag VINs appearing under two different carriers, which means a shared provider login';

    public function handle(): int
    {
        $overlaps = $this->overlaps();

        if ($overlaps->isEmpty()) {
            $this->info('No shared logins detected. No VIN appears under more than one carrier.');

            return self::SUCCESS;
        }

        $this->warn($overlaps->count().' VIN(s) appear under more than one carrier:');
        $this->newLine();

        $this->table(
            ['VIN', 'Carriers (DOT)', 'Connections', 'Providers'],
            $overlaps->map(fn ($row) => [
                $row->vin,
                $row->dot_numbers,
                $row->connection_ids,
                $row->providers ?: '—',
            ])->all(),
        );

        $this->newLine();
        $this->line('Each row is one truck claimed by two carriers. Confirm which carrier owns it,');
        $this->line('then fork the login into one connection per carrier and apply non-overlapping');
        $this->line('vehicle include lists before the next sync runs.');

        return $this->option('fail-on-find') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * VINs on connections belonging to more than one DOT number.
     *
     * Archived connections are excluded — they are kept to settle disputes over
     * loads already hauled and stop syncing, so an old fleet overlapping a
     * current one is history rather than a live billing problem. A blank VIN is
     * not an identifier, so those are skipped rather than grouped together.
     */
    private function overlaps()
    {
        return DB::table('eld_vehicles as v')
            ->join('eld_connections as c', 'c.id', '=', 'v.eld_connection_id')
            ->whereNotNull('v.vin')
            ->where('v.vin', '!=', '')
            ->whereNull('c.archived_at')
            ->groupBy('v.vin')
            ->havingRaw('COUNT(DISTINCT c.carrier_dot_number) > 1')
            ->select([
                'v.vin',
                DB::raw('GROUP_CONCAT(DISTINCT c.carrier_dot_number ORDER BY c.carrier_dot_number SEPARATOR ", ") as dot_numbers'),
                DB::raw('GROUP_CONCAT(DISTINCT c.terminal_connection_id SEPARATOR ", ") as connection_ids'),
                DB::raw('GROUP_CONCAT(DISTINCT c.provider SEPARATOR ", ") as providers'),
            ])
            ->orderBy('v.vin')
            ->get();
    }
}
