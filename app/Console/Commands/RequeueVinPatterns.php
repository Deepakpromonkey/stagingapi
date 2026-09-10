<?php

namespace App\Console\Commands;

use App\Jobs\DecodeVinPatterns;
use App\Models\VinPattern;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-dispatch patterns that are marked pending but have no job behind them.
 *
 * A pattern row is written before its decode job is dispatched, so the two can
 * come apart: a worker killed mid-batch, a `queue:clear`, a job that exhausted
 * its attempts into failed_jobs, or a run interrupted between the insert and
 * the dispatch. The row is then stranded — `vin:backfill` and
 * `VinDecoderService::queueUnknown()` both skip any pattern that already has a
 * row, precisely so a decided 'failed' is not retried forever, and neither can
 * tell a stranded row from one that is legitimately in flight.
 *
 * This is the reconciler. Scheduled hourly, so the queue heals itself.
 *
 *   php artisan vin:requeue
 *   php artisan vin:requeue --older-than=5   (minutes; default 30)
 *   php artisan vin:requeue --force          (ignore the in-flight guard)
 */
class RequeueVinPatterns extends Command
{
    protected $signature = 'vin:requeue
        {--older-than=30 : Only patterns pending this many minutes, so in-flight work is left alone}
        {--limit=20000 : Maximum patterns to re-queue in one run}
        {--force : Reconcile even while jobs are still queued}
        {--dry-run : Report what would be re-queued without dispatching}';

    protected $description = 'Re-dispatch VIN patterns left pending with no job behind them';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('older-than'));
        $limit = max(1, (int) $this->option('limit'));

        /*
         * Never reconcile while work is still in flight.
         *
         * The age guard alone is not enough. A backfill paces its batches out
         * over hours — the last of 1,400 batches at 15/min is scheduled 95
         * minutes ahead — so a pattern can sit pending far longer than
         * --older-than while its job is queued and perfectly healthy. Age
         * cannot distinguish that from a stranded row, and re-dispatching it
         * doubles the calls to NHTSA for no gain.
         *
         * A pattern is only genuinely orphaned once the queue has drained, so
         * that is when this runs. Anything still queued will be reconciled on
         * a later pass.
         */
        $queued = DB::table(config(
            'queue.connections.'.config('vin.connection', 'database').'.table',
            'jobs'
        ))->where('queue', config('vin.queue', 'vin'))->count();

        if ($queued > 0 && ! $this->option('force')) {
            $this->components->info(
                number_format($queued).' jobs still queued — nothing is orphaned while work is in flight. Skipping.'
            );

            return self::SUCCESS;
        }

        /*
         * The age guard is what keeps this from duplicating live work: a
         * pattern queued moments ago is almost certainly sitting in the jobs
         * table waiting its paced turn, not stranded.
         */
        $patterns = VinPattern::query()
            ->where('status', 'pending')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('pattern')
            ->all();

        if (! $patterns) {
            $this->components->info('Nothing stranded.');

            return self::SUCCESS;
        }

        $batchSize = max(1, (int) config('vin.batch_size', 50));
        $spacing = (int) (60 / max(1, (int) config('vin.batches_per_minute', 6)));

        if ($this->option('dry-run')) {
            $this->components->info(number_format(count($patterns)).' patterns would be re-queued.');

            return self::SUCCESS;
        }

        foreach (array_chunk($patterns, $batchSize) as $index => $chunk) {
            DecodeVinPatterns::dispatch($chunk)
                ->onQueue(config('vin.queue', 'vin'))
                ->delay(now()->addSeconds($index * $spacing));
        }

        // Touched so a second run inside the age window leaves them alone.
        VinPattern::query()->whereIn('pattern', $patterns)->update(['updated_at' => now()]);

        $this->components->info(number_format(count($patterns)).' patterns re-queued.');

        return self::SUCCESS;
    }
}
