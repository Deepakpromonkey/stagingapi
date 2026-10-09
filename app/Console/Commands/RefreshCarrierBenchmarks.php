<?php

namespace App\Console\Commands;

use App\Support\CarrierBenchmarks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Recompute the national carrier benchmarks and put them in the cache.
 *
 * These are whole-population aggregates over 4.48M carriers and 695k SMS rows —
 * the power-units-per-mile percentile alone takes about four minutes. They used
 * to be computed inline on a cache miss, which meant the risk endpoint timed
 * out before it could store anything and then paid the cost again on the next
 * request.
 *
 * Schedule this daily. Nothing calls it on the request path.
 */
class RefreshCarrierBenchmarks extends Command
{
    protected $signature = 'carrier:refresh-benchmarks';

    protected $description = 'Recompute national carrier risk benchmarks into the cache';

    public function handle(): int
    {
        $started = microtime(true);
        $values = [];

        foreach ($this->queries() as $key => $sql) {
            $this->components->task($key, function () use ($key, $sql, &$values) {
                try {
                    $row = DB::connection('external_db')->selectOne($sql);
                } catch (\Throwable $e) {
                    $this->components->warn("{$key}: {$e->getMessage()}");

                    return false;
                }

                foreach ((array) $row as $column => $value) {
                    $values[$key === 'oos' ? 'natl_vehicle_oos' : "{$key}_{$column}"] =
                        $value === null ? null : (float) $value;
                }

                return true;
            });
        }

        if ($values === []) {
            $this->components->error('Nothing computed; leaving the existing benchmarks in place.');

            return self::FAILURE;
        }

        Cache::store(config('cache.durable_store'))->forever(CarrierBenchmarks::CACHE_KEY, $values + CarrierBenchmarks::DEFAULTS);

        $this->components->task('sms peer-group cut-points', function () {
            $cuts = $this->smsCuts();

            if ($cuts === null) {
                return false;
            }

            Cache::store(config('cache.durable_store'))->forever(CarrierBenchmarks::SMS_CACHE_KEY, $cuts);

            return true;
        });

        $this->components->info(sprintf(
            'Benchmarks refreshed in %ds: %s',
            (int) (microtime(true) - $started),
            json_encode($values)
        ));

        return self::SUCCESS;
    }

    /**
     * The 50/65/75/80/90th cut-points of each BASIC measure inside each
     * FMCSA safety-event peer group (CarrierBenchmarks::SMS_GROUPS), among
     * carriers with a measure above zero and at least the group floor of
     * relevant events. One window query per BASIC.
     *
     * A group the population leaves empty keeps the shipped snapshot.
     *
     * @return array{cuts: array, meta: array}|null
     */
    private function smsCuts(): ?array
    {
        $cuts = [];
        $meta = ['generated_at' => now()->toIso8601String(), 'groups_n' => []];

        foreach (CarrierBenchmarks::SMS_GROUPS as $basic => $spec) {

            $case = 'CASE';
            foreach ($spec['bounds'] as $i => $edge) {
                $case .= " WHEN {$spec['count']} <= {$edge} THEN ".($i + 1);
            }
            $case .= ' ELSE '.(count($spec['bounds']) + 1).' END';

            $pcts = 'COUNT(*) n';
            foreach (CarrierBenchmarks::SMS_PERCENTILES as $p) {
                $pcts .= ', MAX(CASE WHEN r <= '.($p / 100)." THEN m END) p{$p}";
            }

            try {
                $rows = DB::connection('external_db')->select(
                    "SELECT grp, {$pcts}
                     FROM (SELECT {$basic}_measure m, {$case} grp,
                                  PERCENT_RANK() OVER (PARTITION BY {$case}
                                                       ORDER BY {$basic}_measure) r
                           FROM sms_measures
                           WHERE {$basic}_measure > 0 AND {$spec['count']} >= {$spec['min']}) t
                     GROUP BY grp"
                );
            } catch (\Throwable $e) {
                $this->components->warn("sms cuts ({$basic}): {$e->getMessage()}");

                return null;
            }

            foreach ($rows as $row) {
                foreach (CarrierBenchmarks::SMS_PERCENTILES as $p) {
                    $cuts[$basic][(int) $row->grp][$p] = (float) ($row->{"p{$p}"} ?? 0);
                }
                $meta['groups_n'][$basic][(int) $row->grp] = (int) $row->n;
            }

            foreach (array_keys(CarrierBenchmarks::SMS_DEFAULTS[$basic]) as $grp) {
                $cuts[$basic][$grp] ??= CarrierBenchmarks::SMS_DEFAULTS[$basic][$grp];
            }

            ksort($cuts[$basic]);
        }

        return ['cuts' => $cuts, 'meta' => $meta];
    }

    /**
     * CAST to DOUBLE matters: MySQL's DECIMAL division keeps four decimal
     * places, which rounds a ratio like 23 power units / 1,091,782 miles
     * straight to zero.
     */
    private function queries(): array
    {
        return [
            'oos' => 'SELECT AVG(vehicle_oos_insp_total / CAST(vehicle_insp_total AS DOUBLE)) AS v
                      FROM sms_measures WHERE vehicle_insp_total >= 5',

            'pum' => 'SELECT MAX(CASE WHEN pr <= 0.05 THEN r END) AS lo,
                             MAX(CASE WHEN pr <= 0.95 THEN r END) AS hi
                      FROM (SELECT r, PERCENT_RANK() OVER (ORDER BY r) AS pr FROM
                        (SELECT nbr_power_unit / CAST(mcs150_mileage AS DOUBLE) AS r
                         FROM carriers WHERE nbr_power_unit > 0 AND mcs150_mileage > 0) s) t',

            'im' => 'SELECT MAX(CASE WHEN pr <= 0.05 THEN r END) AS lo,
                            MAX(CASE WHEN pr <= 0.95 THEN r END) AS hi
                     FROM (SELECT r, PERCENT_RANK() OVER (ORDER BY r) AS pr FROM
                       (SELECT m.insp_total / CAST(c.mcs150_mileage AS DOUBLE) AS r
                        FROM sms_measures m JOIN carriers c ON c.dot_number = m.dot_number
                        WHERE m.insp_total > 0 AND c.mcs150_mileage > 0) s) t',
        ];
    }
}
