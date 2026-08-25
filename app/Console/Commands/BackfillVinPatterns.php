<?php

namespace App\Console\Commands;

use App\Jobs\DecodeVinPatterns;
use App\Models\VinPattern;
use App\Support\Vin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Collect every distinct VIN pattern in the inspections table and queue the
 * ones not decoded yet.
 *
 * The whole design rests on the ratio this command reports. `inspections`
 * holds tens of millions of VIN values, but only positions 1-8 and 10 carry
 * year/make/model, so the distinct pattern count is smaller by orders of
 * magnitude — and it is patterns, not VINs, that have to be decoded. See the
 * ratio for yourself before running anything else:
 *
 *   php artisan vin:backfill --count
 *
 * Then the real thing. It is resumable — patterns already recorded are
 * skipped — so an interrupted run costs nothing but the scan.
 *
 *   php artisan vin:backfill                 full sweep, run once
 *   php artisan vin:backfill --incremental   nightly; only rows added since
 */
class BackfillVinPatterns extends Command
{
    protected $signature = 'vin:backfill
        {--count : Report VIN and pattern totals without queueing anything}
        {--incremental : Only inspection rows added since the last run}
        {--chunk=20000 : Rows read per pass}
        {--limit=0 : Stop after queueing this many patterns (0 = no limit)}
        {--dry-run : Report what would be queued without writing or dispatching}';

    protected $description = 'Collect distinct VIN patterns from inspections and queue them for decoding';

    /**
     * High-water mark for --incremental. The inspections view exposes the
     * loader's `_row_id` as `id` and the feed only ever appends, so the last
     * id seen is a sound resume point.
     */
    protected const CURSOR_KEY = 'vin:backfill:last_id';

    public function handle(): int
    {
        if ($this->option('count')) {
            return $this->reportCounts();
        }

        $started = microtime(true);
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $incremental = (bool) $this->option('incremental');

        $seen = [];
        $queued = 0;
        $scanned = 0;

        $this->components->info($incremental
            ? 'Scanning inspections added since the last run...'
            : 'Scanning all distinct VINs in inspections...');

        $source = $incremental ? $this->newRowVins() : $this->distinctVins();

        foreach ($source as $vins) {
            $scanned += count($vins);
            $patterns = [];

            foreach ($vins as $vin) {
                if (($pattern = Vin::pattern($vin)) && ! isset($seen[$pattern])) {
                    $seen[$pattern] = true;
                    $patterns[] = $pattern;
                }
            }

            if (! $patterns) {
                continue;
            }

            // Only patterns with no row at all. An existing 'failed' row is a
            // decision that was already made, not a gap to fill.
            $known = VinPattern::query()->whereIn('pattern', $patterns)->pluck('pattern')->all();
            $missing = array_values(array_diff($patterns, $known));

            if (! $missing) {
                continue;
            }

            if ($limit > 0 && $queued + count($missing) > $limit) {
                $missing = array_slice($missing, 0, $limit - $queued);
            }

            if (! $dryRun) {
                $this->register($missing);
            }

            $queued += count($missing);

            $this->components->twoColumnDetail(
                number_format($scanned).' VINs scanned',
                number_format($queued).' patterns queued'
            );

            if ($limit > 0 && $queued >= $limit) {
                $this->components->warn("Stopped at the --limit of {$limit}; rerun to continue.");
                break;
            }
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s VINs scanned, %s distinct patterns, %s %s in %ds.',
            number_format($scanned),
            number_format(count($seen)),
            number_format($queued),
            $dryRun ? 'would be queued' : 'queued',
            (int) (microtime(true) - $started),
        ));

        if ($queued > 0 && ! $dryRun) {
            $minutes = (int) ceil(
                ($queued / max(1, (int) config('vin.batch_size', 50)))
                / max(1, (int) config('vin.batches_per_minute', 6))
            );

            $this->components->info("At the configured pace that is about {$minutes} minutes of decoding.");
            $this->components->warn(sprintf(
                'A worker must be running: php artisan queue:work %s --queue=%s',
                config('vin.connection', 'database'),
                config('vin.queue', 'vin'),
            ));

            /*
             * Batches carry a delay so they reach NHTSA at the configured
             * pace, which means queue:work can look idle while jobs wait their
             * turn. Say when the first one is due so that is not mistaken for
             * a hang — and point at vin:status for the running picture.
             */
            $nextAt = DB::table(config(
                'queue.connections.'.config('vin.connection', 'database').'.table',
                'jobs'
            ))->where('queue', config('vin.queue', 'vin'))->min('available_at');

            if ($nextAt) {
                $wait = max(0, (int) $nextAt - time());

                $this->components->info($wait > 0
                    ? "First batch runs in {$wait}s — until then the worker is idle by design."
                    : 'First batch is runnable now.');
            }

            $this->components->info('Watch it with: php artisan vin:status --watch');
        }

        return self::SUCCESS;
    }

    /**
     * Insert placeholder rows, then dispatch decode batches paced to
     * config('vin.batches_per_minute').
     *
     * @param  array<int, string>  $patterns
     */
    protected function register(array $patterns): void
    {
        $now = now();
        $batchSize = max(1, (int) config('vin.batch_size', 50));
        $spacing = (int) (60 / max(1, (int) config('vin.batches_per_minute', 6)));

        /*
         * Spacing counts from the batches already waiting, so a second pass
         * does not schedule its work on top of the first pass's.
         *
         * Counted *before* the insert below. Counting after would include the
         * batches being scheduled right here, and each pass would push itself
         * out by its own size — on a full backfill that roughly doubles the
         * wall-clock time for no reason.
         */
        $offset = (int) ceil(
            VinPattern::query()->where('status', 'pending')->count() / $batchSize
        );

        foreach (array_chunk($patterns, 1000) as $slice) {
            VinPattern::query()->insertOrIgnore(array_map(fn ($pattern) => [
                'pattern' => $pattern,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $slice));
        }

        foreach (array_chunk($patterns, $batchSize) as $index => $chunk) {
            DecodeVinPatterns::dispatch($chunk)
                ->onQueue(config('vin.queue', 'vin'))
                ->delay(now()->addSeconds(($offset + $index) * $spacing));
        }
    }

    /**
     * Distinct VIN values, walked in index order.
     *
     * sms_input_inspection carries idx_vin and idx_vin2, so paging on the VIN
     * itself keeps this an index-only scan — MySQL never touches a data page.
     * Reading whole rows instead would mean a full scan of a table with tens
     * of millions of rows in it.
     *
     * Both columns matter: one inspection records the power unit in `vin` and
     * the trailer it was pulling in `vin2`.
     *
     * @return \Generator<int, array<int, string>>
     */
    protected function distinctVins(): \Generator
    {
        foreach (['vin', 'vin2'] as $column) {
            $chunk = max(1000, (int) $this->option('chunk'));
            $cursor = '';

            while (true) {
                $rows = DB::connection('external_db')->select("
                    SELECT DISTINCT {$column} AS v
                    FROM inspections
                    WHERE {$column} > ? AND CHAR_LENGTH({$column}) = 17
                    ORDER BY {$column}
                    LIMIT {$chunk}
                ", [$cursor]);

                if (! $rows) {
                    break;
                }

                $cursor = $rows[array_key_last($rows)]->v;

                yield array_map(fn ($row) => $row->v, $rows);
            }
        }
    }

    /**
     * VINs from inspection rows added since the last run, by keyset on the id.
     *
     * @return \Generator<int, array<int, string>>
     */
    protected function newRowVins(): \Generator
    {
        $chunk = max(1000, (int) $this->option('chunk'));
        $lastId = (int) Cache::get(self::CURSOR_KEY, 0);
        $highest = $lastId;

        while (true) {
            $rows = DB::connection('external_db')->select("
                SELECT id, vin, vin2
                FROM inspections
                WHERE id > ?
                ORDER BY id
                LIMIT {$chunk}
            ", [$lastId]);

            if (! $rows) {
                break;
            }

            $vins = [];

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $highest = max($highest, $lastId);
                $vins[] = $row->vin;
                $vins[] = $row->vin2;
            }

            yield array_values(array_filter($vins));
        }

        // Only advanced once the pass completes, so an interrupted run repeats
        // rather than skips.
        Cache::forever(self::CURSOR_KEY, $highest);
    }

    /**
     * The sizing query — how much smaller the decode job is than the raw VIN
     * count suggests.
     */
    protected function reportCounts(): int
    {
        $this->components->info('Counting (this scans the VIN index and takes a while)...');

        $row = DB::connection('external_db')->selectOne("
            SELECT
                COUNT(*) AS vin_rows,
                COUNT(DISTINCT vin) AS distinct_vins,
                COUNT(DISTINCT CONCAT(LEFT(vin, 8), SUBSTRING(vin, 10, 1))) AS patterns
            FROM inspections
            WHERE CHAR_LENGTH(vin) = 17
        ");

        $vins = (int) ($row->vin_rows ?? 0);
        $distinct = (int) ($row->distinct_vins ?? 0);
        $patterns = (int) ($row->patterns ?? 0);
        $cached = VinPattern::query()->count();
        $decoded = VinPattern::query()->decoded()->count();

        $this->newLine();
        $this->components->twoColumnDetail('VIN rows (power unit column)', number_format($vins));
        $this->components->twoColumnDetail('Distinct VINs', number_format($distinct));
        $this->components->twoColumnDetail('Distinct patterns', number_format($patterns));
        $this->components->twoColumnDetail(
            'Rows per pattern',
            $patterns > 0 ? number_format($vins / $patterns, 1).' : 1' : 'n/a'
        );
        $this->components->twoColumnDetail('Already in cache', number_format($cached));
        $this->components->twoColumnDetail('Decoded', number_format($decoded));
        $this->components->twoColumnDetail('Left to decode', number_format(max(0, $patterns - $cached)));
        $this->newLine();

        return self::SUCCESS;
    }
}
