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

        Cache::forever(CarrierBenchmarks::CACHE_KEY, $values + CarrierBenchmarks::DEFAULTS);

        $this->components->task('sms percentile cut-points', function () {
            $cuts = $this->smsCuts();

            if ($cuts === null) {
                return false;
            }

            Cache::forever(CarrierBenchmarks::SMS_CACHE_KEY, $cuts);

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
     * The 50th / 75th / 90th cut-point of each BASIC measure, in one pass.
     *
     * @return array<string, array<int, float>>|null
     */
    private function smsCuts(): ?array
    {
        $cases = [];
        $windows = [];

        foreach (CarrierBenchmarks::SMS_BASICS as $i => $basic) {
            $windows[] = "{$basic}_measure AS m{$i}";
            $windows[] = "PERCENT_RANK() OVER (ORDER BY {$basic}_measure) AS r{$i}";

            foreach ([50, 75, 90] as $p) {
                $cases[] = "MAX(CASE WHEN r{$i} <= ".($p / 100)." THEN m{$i} END) AS p{$p}_{$i}";
            }
        }

        try {
            $row = DB::connection('external_db')->selectOne(
                'SELECT '.implode(', ', $cases).
                ' FROM (SELECT '.implode(', ', $windows).
                ' FROM sms_measures WHERE insp_total > 0) t'
            );
        } catch (\Throwable $e) {
            $this->components->warn("sms cuts: {$e->getMessage()}");

            return null;
        }

        $cuts = [];

        foreach (CarrierBenchmarks::SMS_BASICS as $i => $basic) {
            foreach ([50, 75, 90] as $p) {
                $cuts[$basic][$p] = (float) ($row->{"p{$p}_{$i}"} ?? CarrierBenchmarks::SMS_DEFAULTS[$basic][$p]);
            }
        }

        return $cuts;
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
