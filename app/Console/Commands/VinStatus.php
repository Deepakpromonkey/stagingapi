<?php

namespace App\Console\Commands;

use App\Models\CarrierFleetStat;
use App\Models\VinPattern;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the VIN decode queue is actually doing.
 *
 * Batches are dispatched with a delay so they reach NHTSA at the configured
 * pace, which means a worker can sit with an empty-looking queue for minutes
 * while jobs wait for their turn. That is indistinguishable from a hang unless
 * you can see `available_at` — hence this command.
 *
 *   php artisan vin:status
 *   php artisan vin:status --watch
 */
class VinStatus extends Command
{
    protected $signature = 'vin:status {--watch : Refresh every 10 seconds until interrupted}';

    protected $description = 'Show VIN decode queue depth, progress and pace';

    public function handle(): int
    {
        do {
            if ($this->option('watch')) {
                $this->output->write("\033[2J\033[H");
            }

            $this->render();

            if ($this->option('watch')) {
                sleep(10);
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }

    protected function render(): void
    {
        $queue = config('vin.queue', 'vin');
        $connection = config('vin.connection', 'database');
        $driver = config("queue.connections.{$connection}.driver");
        $table = config("queue.connections.{$connection}.table", 'jobs');
        $batchSize = max(1, (int) config('vin.batch_size', 50));
        $perMinute = max(1, (int) config('vin.batches_per_minute', 6));

        /*
         * The failure that looks like every other failure. On the sync driver
         * a dispatched job runs inline and never reaches the jobs table, so a
         * worker sits idle forever while decoding happens on the request path.
         */
        if ($driver === 'sync') {
            $this->newLine();
            $this->components->error(
                "vin.connection resolves to '{$connection}', which is the sync driver. "
                .'Jobs run inline and never reach a worker — set VIN_QUEUE_CONNECTION=database.'
            );

            return;
        }

        $counts = VinPattern::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts['pending'] ?? 0);
        $ok = (int) ($counts['ok'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $total = $pending + $ok + $failed;

        $queued = DB::table($table)->where('queue', $queue)->count();
        $ready = DB::table($table)->where('queue', $queue)->where('available_at', '<=', time())->count();
        $nextAt = DB::table($table)->where('queue', $queue)->min('available_at');
        $failedJobs = DB::table('failed_jobs')->where('queue', $queue)->count();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Patterns</>', '');
        $this->components->twoColumnDetail('  decoded', number_format($ok).($total ? ' ('.round($ok / $total * 100).'%)' : ''));
        $this->components->twoColumnDetail('  pending', number_format($pending));
        $this->components->twoColumnDetail('  failed', number_format($failed));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Queue</>', '');
        $this->components->twoColumnDetail('  jobs waiting', number_format($queued));
        $this->components->twoColumnDetail('  runnable right now', number_format($ready));

        /*
         * The number that explains an idle-looking worker: a job whose delay
         * has not elapsed is invisible to queue:work, by design.
         */
        if ($queued > 0 && $ready === 0 && $nextAt) {
            $wait = max(0, (int) $nextAt - time());
            $this->components->twoColumnDetail(
                '  <fg=yellow>next batch runs in</>',
                "<fg=yellow>{$wait}s — the worker is paced, not stuck</>"
            );
        }

        if ($failedJobs > 0) {
            $this->components->twoColumnDetail('  <fg=red>failed jobs</>', "<fg=red>{$failedJobs}</> (php artisan queue:failed)");
        }

        if ($pending > 0) {
            $minutes = (int) ceil(($pending / $batchSize) / $perMinute);
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=cyan>Estimated time remaining</>',
                $minutes >= 60
                    ? sprintf('%dh %dm at %d batches/min', intdiv($minutes, 60), $minutes % 60, $perMinute)
                    : "{$minutes}m at {$perMinute} batches/min"
            );
        }

        $stats = CarrierFleetStat::query()->count();
        $withAges = CarrierFleetStat::query()
            ->where(fn ($q) => $q->whereNotNull('avg_power_age')->orWhereNotNull('avg_trailer_age'))
            ->count();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Carrier fleet stats</>', '');
        $this->components->twoColumnDetail('  carriers computed', number_format($stats));
        $this->components->twoColumnDetail('  with an age to show', number_format($withAges));
        $this->newLine();

        if ($queued === 0 && $pending > 0) {
            $this->components->warn('Patterns are pending but nothing is queued — rerun php artisan vin:backfill.');
        }

        if ($queued > 0) {
            $this->components->info("A worker must be running: php artisan queue:work {$connection} --queue={$queue}");
        }
    }
}
