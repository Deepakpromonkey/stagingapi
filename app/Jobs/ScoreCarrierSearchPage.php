<?php

namespace App\Jobs;

use App\Services\Carrier\DtSearchScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Computes and caches DT scores for a batch of carriers, off the request
 * that first asked for them.
 *
 * AdvancedCarrierSearchController used to compute these inline, and a page
 * of ten carriers nobody had ever searched before was measured taking
 * 30-90 seconds on its own to do it - the dominant share of the whole
 * request. Dispatched here instead: the search response goes out with
 * dt_score: null for whichever rows had no cached score yet, and this job
 * fills carrier_dt_score:{dot} in the background - the same cache key
 * AdvancedCarrierSearchController, CarrierShortlistController and the
 * carrier profile page all read, so the next view of any of those carriers
 * (another search, the shortlist, the profile) sees the real number.
 *
 * The scoring itself lives in DtSearchScoringService, not here - this job
 * is only the queue plumbing around it.
 *
 * Its one call site (AdvancedCarrierSearchController::dispatchScoring())
 * dispatches this ->afterResponse(), which runs it inline right after the
 * HTTP response has gone out - not through a queue connection at all, so
 * a score is typically ready within a few seconds instead of waiting on
 * the next cron-triggered queue:work cycle. Still implements ShouldQueue
 * and pins onConnection/onQueue below so a future call site can dispatch
 * it as a genuinely queued job if that's ever the better fit again; the
 * afterResponse() path simply ignores both.
 */
class ScoreCarrierSearchPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Generous, not tight - unlike the request path this ran on before,
     * nothing is waiting on this to finish, so there is no reason to cut it
     * off at the search page's own budget. Ten carriers measured well under
     * this even cold; this is headroom for a busier page, not the expected
     * case. Only takes effect if this ever runs as a real queued job again
     * - afterResponse() has no timeout of its own beyond the web server's.
     */
    public int $timeout = 180;

    /** @param  array<int, string>  $dots */
    public function __construct(public array $dots)
    {
        // No-ops on the current afterResponse() call site (see class
        // docblock) - kept so this class stays a genuinely queueable job,
        // pinned the same way the ELD jobs are: QUEUE_CONNECTION is `sync`
        // on this box, and inheriting it would run scoring inline on
        // whatever request dispatched it, which is exactly what a real
        // queued dispatch of this job would exist to avoid.
        $this->onConnection(config('vin.connection', 'database'));
        $this->onQueue('dt-score');
    }

    public function handle(DtSearchScoringService $scoring): void
    {
        try {
            $scoring->computePageScores($this->dots);
        } finally {
            // Cleared whether scoring succeeded or not - a carrier whose
            // scoring genuinely failed (bad data, a timeout) should be
            // dispatchable again on the next search that touches it, not
            // locked out for the full two-minute dedupe window for no
            // result.
            foreach ($this->dots as $dot) {
                Cache::forget('dt:score:queued:'.$dot);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('[CarrierSearch] background scoring job failed: '.$e->getMessage(), [
            'dots' => $this->dots,
        ]);

        foreach ($this->dots as $dot) {
            Cache::forget('dt:score:queued:'.$dot);
        }
    }
}
