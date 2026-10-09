<?php

namespace App\Http\Controllers\Api\V1\Extension;

use App\Http\Controllers\Controller;
use App\Jobs\ScoreCarrierSearchPage;
use App\Models\CarrierBlocked;
use App\Models\CarrierShortlist;
use App\Services\Carrier\DtSearchScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The DollarTraq Chrome extension's carrier lookup.
 *
 * A broker hovers a DOT number on any website and the extension asks this
 * for the hover card. Only the DOT number is ever sent - never the page,
 * its URL or anything else on it.
 *
 * Everything else the extension needs already exists and is reused as-is:
 * /login + /verify-login-otp for the token, /me, /logout, /carrier/scores
 * to poll for a score this returned as pending, /carriers/{dot}/risk for
 * the card's reliability and risk factors (the profile's own lists), and
 * /shortlist + /blocked (row_id = the DOT) for the card's buttons.
 */
class ExtensionCarrierController extends Controller
{
    public function show(Request $request, string $dot, DtSearchScoringService $scoring)
    {
        $dot = (int) $dot;

        try {
            $card = $scoring->quickCard($dot);
        } catch (\Throwable $e) {
            Log::warning("[Extension] lookup failed for DOT {$dot}: ".$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Carrier lookup is unavailable right now. Please try again in a moment.',
            ], 503);
        }

        if ($card === null) {
            return response()->json([
                'status' => 'error',
                'message' => "No carrier found with DOT {$dot}.",
            ], 404);
        }

        $pending = $card['dt_score'] === null;

        if ($pending) {
            $this->queueScoring((string) $dot);
        }

        $companyId = $request->user()->company_id;
        $carrierId = $card['carrier_id'];

        return response()->json([
            'status' => 'success',
            'data' => $card + [
                // true means: poll GET /carrier/scores?dots[]={dot} until it
                // comes back with a number - usually a few seconds.
                'dt_score_pending' => $pending,
                'shortlisted' => CarrierShortlist::where('company_id', $companyId)
                    ->where('carrier_id', $carrierId)
                    ->exists(),
                'blocked' => CarrierBlocked::where('company_id', $companyId)
                    ->where('carrier_id', $carrierId)
                    ->exists(),
                'profile_url' => rtrim(config('app.frontend_url'), '/').'/carriers/'.$dot,
            ],
        ]);
    }

    /**
     * Score this carrier in the background, after the response has gone
     * out - the same job, cache key and two-minute dedupe guard the search
     * page uses (see AdvancedCarrierSearchController::dispatchScoring()), so
     * a broker hovering the same DOT repeatedly, or a search and a hover
     * landing together, runs one scoring pass, not several.
     */
    private function queueScoring(string $dot): void
    {
        if (! Cache::add('dt:score:queued:'.$dot, true, now()->addMinutes(2))) {
            return;
        }

        try {
            ScoreCarrierSearchPage::dispatch([$dot])->afterResponse();
        } catch (\Throwable $e) {
            Log::warning('[Extension] failed to dispatch background scoring: '.$e->getMessage());

            Cache::forget('dt:score:queued:'.$dot);
        }
    }
}
