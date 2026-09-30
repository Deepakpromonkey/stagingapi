<?php

namespace App\Services\Carrier;

use App\Http\Controllers\Carrier\Concerns\DtTrustScoreSupport;
use App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3;
use App\Models\Carriers\Carrier;
use App\Support\Fmcsa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes and caches DT scores for a page of carriers - the same v3 engine
 * the carrier profile runs, off the same cache key
 * (`carrier_dt_score:{dot}`), so a score computed here, one computed by
 * AdvancedCarrierSearchController, and one computed by opening a carrier's
 * profile can never disagree.
 *
 * Extracted, not duplicated, on purpose. This is the second time this exact
 * batched-scoring machinery was needed by a different caller
 * (AdvancedCarrierSearchController first, CarrierShortlistController second
 * - that one duplicated it, a deliberate call made when the alternative was
 * touching a search flow that had just been hardened and was not to be
 * disturbed). A third caller (a background scoring job, so a live search no
 * longer blocks on computing scores for carriers nobody has looked at yet -
 * see ScoreCarrierSearchPage) made a third copy the wrong trade: nothing
 * here changes AdvancedCarrierSearchController's request-handling logic,
 * only WHERE this one self-contained "given some DOTs, score and cache
 * them" unit lives, so extracting it is a mechanical, behaviour-preserving
 * move, not a risk to the query-building code that's actually been tested
 * end to end this session.
 *
 * CarrierShortlistController's own copy is left exactly as it is - not
 * part of today's change, and migrating it to this service is a safe,
 * separate follow-up.
 */
class DtSearchScoringService
{
    use DtTrustScoreV3, DtTrustScoreSupport;

    private const CONN = 'external_db';

    /** Same TTL as the search page and the carrier profile - one stored
     * score per DOT, not three call sites that can disagree. */
    private const SEARCH_SCORE_TTL_HOURS = 6;

    /**
     * Per-statement budget for the scoring queries specifically.
     *
     * Deliberately well under QUERY_TIMEOUT_MS: a page of results that comes
     * back without scores is a far better outcome than one that arrives late
     * because an enrichment step went looking for them.
     */
    private const SCORE_TIMEOUT_MS = 8000;

    private const QUERY_TIMEOUT_MS = 25000;

    private function conn()
    {
        return DB::connection(self::CONN);
    }

    private function setStatementTimeout(int $ms): void
    {
        try {
            $this->conn()->statement('SET SESSION MAX_EXECUTION_TIME = '.(int) $ms);
        } catch (\Throwable) {
        }
    }

    /**
     * Runs $fn(), logs how long it took, and returns whatever $fn()
     * returned. Same pattern as AdvancedCarrierSearchController::timed() -
     * every meaningful stage logs its own cost, so a slow background job
     * shows exactly which stage was the culprit in the logs instead of
     * requiring more guesswork.
     */
    private function timed(string $label, \Closure $fn)
    {
        $start = microtime(true);
        try {
            $result = $fn();
            $ms = round((microtime(true) - $start) * 1000, 1);
            Log::info("[CarrierSearch] {$label} took {$ms}ms");

            return $result;
        } catch (\Throwable $e) {
            $ms = round((microtime(true) - $start) * 1000, 1);
            Log::warning("[CarrierSearch] {$label} FAILED after {$ms}ms: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Compute (and cache) scores for a page of carriers.
     *
     * A fixed number of statements regardless of page size: one to load the
     * carriers with their relations, one for inspection aggregates, one for
     * crash aggregates. Everything after that runs against data already in
     * memory.
     *
     * @param  array<int, string>  $dots
     * @return array<int|string, int>
     */
    public function computePageScores(array $dots): array
    {
        /*
        | A tighter budget than the search itself gets.
        |
        | MAX_EXECUTION_TIME is per statement, not a pool, so scoring's three
        | queries could each run to the full search timeout and turn a 25s
        | worst case into a 100s one. Scoring is enrichment - the results are
        | already correct without it - so it gets a fraction of the budget,
        | and blowing it degrades to the cached/last-known score rather than
        | holding up the response.
        |
        | Restored in the finally below so nothing after this inherits the
        | shorter limit.
        */
        $this->setStatementTimeout(self::SCORE_TIMEOUT_MS);

        try {
            return $this->computePageScoresWithin($dots);
        } finally {
            $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);
        }
    }

    /** @param  array<int, string>  $dots */
    private function computePageScoresWithin(array $dots): array
    {
        $carriers = $this->timed(
            'dt score: carriers + relations ('.count($dots).' rows)',
            fn () => Carrier::whereIn('dot_number', $dots)
                ->with([
                    'carrierDetail',
                    'smsMeasures',
                    'authority',
                    'oosOrders',
                    'authorityOrders',
                    'insuranceFilings',
                    'insuranceFilingsPending',
                    'insuranceFilingsHistory',

                    // Column-limited on purpose - the authority rules read
                    // four fields off it and nothing else. dot_number stays
                    // because the eager load matches rows back on it.
                    'authorityHistory:dot_number,op_auth_type,original_action_desc,orig_served_date,disp_action_desc',

                    /*
                    | Crashes must be loaded here, not left to the engine.
                    |
                    | dtEvaluateCrash() reads $carrier->crashes directly,
                    | unguarded - so an unloaded relation lazy-loads, one
                    | SELECT per carrier per page. That is the per-row cost
                    | this whole file exists to avoid.
                    |
                    | The aggregates in dtCrashStatsFor() cannot stand in for
                    | it: those are lifetime totals, while the rules also need
                    | the rows to count what happened in the last 24 months.
                    | Column-limited to exactly the four fields the rules read.
                    */
                    'crashes:dot_number,report_date,fatalities,injuries,tow_away',

                    /*
                    | Inspections, for the same reason crashes are above.
                    |
                    | The v3.3 engine reads $carrier->inspections directly in
                    | three places, none guarded: INSP-03 walks every row for
                    | the newest insp_date, OPS-11 counts the collection for
                    | the ghost-fleet check, and dtNetworkCounts() plucks vin
                    | off it for the VIN-sharing signal. Leaving this unloaded
                    | would lazy-load the FULL row for every carrier that has
                    | more than five inspections logged - on the busiest
                    | carrier in the feed, 22,000+ full rows, once per page.
                    |
                    | Column-limited to the two fields those three rules
                    | actually read. observed_units / observed_trailers still
                    | come from dtInspectionStatsFor()'s SQL aggregate below,
                    | not from this collection - that needs unit_type_desc /
                    | vin2 too, which nothing here touches.
                    */
                    'inspections:dot_number,insp_date,vin',

                    /*
                    | OPS-12 (v3.5) reads $carrier->violationDetails directly,
                    | unguarded, same risk as crashes/inspections above if
                    | left unloaded - one lazy SELECT per carrier per page.
                    | The underlying table (sms_input_violation, exposed here
                    | as the violation_details view) is 6.7M+ rows with the
                    | busiest carriers running 10,000-14,000+ violations
                    | each, so this is column-limited to the one field the
                    | rule reads. Indexed on dot_number - confirmed via
                    | EXPLAIN this still uses that index through the view,
                    | not a scan.
                    */
                    'violationDetails:dot_number,viol_code',
                ])
                ->get()
        );

        if ($carriers->isEmpty()) {
            return [];
        }

        $loaded = $carriers->pluck('dot_number')->all();

        $inspectionStats = $this->timed('dt score: inspection stats', fn () => $this->dtInspectionStatsFor($loaded));
        $crashStats = $this->timed('dt score: crash stats', fn () => $this->dtCrashStatsFor($loaded));

        $this->timed('dt score: network prewarm', fn () => $this->dtPrewarmNetworkCounts($carriers));

        $scores = [];

        /*
        | Per-carrier, not just around the loop as a whole. Everything above
        | this point is timed(), but this loop wasn't originally - which was
        | exactly the gap that made a 60-90s page look like "somewhere after
        | network prewarm" instead of naming the one carrier actually
        | responsible. See dtCalculateTrustScore() and its dtEvaluate*()
        | groups for what a slow row here is actually spending time in -
        | this only proves which DOT to go look at, not why.
        */
        foreach ($carriers as $carrier) {
            $dot = (string) $carrier->dot_number;
            $rowStart = microtime(true);

            try {
                $score = $this->trustScoreForRow(
                    $carrier,
                    $inspectionStats[$carrier->dot_number] ?? [],
                    $crashStats[$carrier->dot_number] ?? [],
                );
            } catch (\Throwable $e) {
                // One carrier's bad data must not cost the other nine their
                // scores, so this is caught per row rather than per page.
                Log::warning("[CarrierSearch] DT scoring failed for DOT {$dot} after "
                    .round((microtime(true) - $rowStart) * 1000, 1)."ms: ".$e->getMessage());
                continue;
            }

            $rowMs = round((microtime(true) - $rowStart) * 1000, 1);

            // Only the slow ones - logging all ten every request would
            // bury the signal this exists to surface. 1s is well above
            // what a cache-hit row costs and well below what's normal for
            // a genuine cold one, so a line here means "look at this DOT
            // specifically", not "every row is always this slow".
            if ($rowMs > 1000) {
                Log::info("[CarrierSearch] dt score: DOT {$dot} took {$rowMs}ms");
            }

            if ($score === null) {
                continue;
            }

            Cache::put(
                'carrier_dt_score:'.$dot,
                $score,
                now()->addHours(self::SEARCH_SCORE_TTL_HOURS),
            );

            $scores[$dot] = $score;
        }

        return $scores;
    }

    /**
     * Run the trust score for one already-loaded carrier.
     *
     * Assembles the same inputs the carrier profile assembles, off relations
     * already in memory, and hands them to the shared v3 engine. Kept
     * deliberately identical to CarrierController::trustScoreForRow() so the
     * two entry points cannot produce different numbers.
     *
     * dtCalculateTrustScore() takes 17 positional args as of the v3.3 engine
     * - it no longer takes an inspection count or a last-inspection-date, and
     * dtInspectionStatsFor() has been trimmed accordingly: the engine now
     * derives both itself off $carrier->inspections (eager-loaded above),
     * matching what CarrierController's own trustScoreForRow() passes.
     */
    private function trustScoreForRow(Carrier $carrier, array $inspectionStats, array $crashStats): ?int
    {
        $trustScore = $this->runTrustScoreEngine($carrier, $inspectionStats, $crashStats);

        return isset($trustScore['overall_score']) ? (int) $trustScore['overall_score'] : null;
    }

    /**
     * Assembles the same inputs as trustScoreForRow() and runs the engine,
     * but returns its full output (including `status`) instead of just the
     * bare score. trustScoreForRow() itself now just plucks overall_score
     * off this - kept separate because computePageScores()'s cache only
     * ever stores the bare int, so the score-only path has no use for the
     * rest. enrichCarriers() below needs `status` too, to derive risk_level
     * correctly: a carrier can fail on risk points independently of its
     * numeric score, so risk_level is never re-derived from the score
     * alone.
     */
    private function runTrustScoreEngine(Carrier $carrier, array $inspectionStats, array $crashStats): array
    {
        $detail = $carrier->carrierDetail;
        $sms = $carrier->smsMeasures;
        $auth = $carrier->authority;

        // OOS percentages come off sms_measures, exactly as on the profile.
        $vehicleInspTotal = (int) ($sms?->vehicle_insp_total ?? 0);
        $vehicleOosTotal = (int) ($sms?->vehicle_oos_insp_total ?? 0);
        $driverInspTotal = (int) ($sms?->driver_insp_total ?? 0);
        $driverOosTotal = (int) ($sms?->driver_oos_insp_total ?? 0);

        $vehicleOosPct = $vehicleInspTotal > 0 ? round($vehicleOosTotal / $vehicleInspTotal * 100, 2) : null;
        $driverOosPct = $driverInspTotal > 0 ? round($driverOosTotal / $driverInspTotal * 100, 2) : null;

        $observedUnits = (int) ($inspectionStats['observed_units'] ?? 0);
        $observedTrailers = (int) ($inspectionStats['observed_trailers'] ?? 0);

        // Age of each authority type, from the oldest GRANTED history row.
        $getAuthorityAge = function (string $key) use ($carrier) {

            $types = Fmcsa::authorityType($key);

            $granted = $carrier->authorityHistory
                ->filter(fn ($h) => in_array(strtoupper((string) $h->op_auth_type), $types, true)
                    && strtoupper((string) $h->original_action_desc) === 'GRANTED')
                ->sortBy(fn ($h) => Fmcsa::dateKey($h->orig_served_date) ?: PHP_INT_MAX)
                ->first();

            $served = Fmcsa::date($granted?->orig_served_date);

            return $served ? (int) $served->diffInYears(now()) : null;
        };

        $mcs150Year = null;

        if ($carrier->mcs150_date) {
            try {
                $mcs150Year = (int) Carbon::parse($carrier->mcs150_date)->year;
            } catch (\Throwable) {
            }
        }

        $trustScore = $this->dtCalculateTrustScore(
            $carrier,
            $detail,
            $sms,
            $auth,
            $vehicleOosPct,
            $driverOosPct,
            $getAuthorityAge('common'),
            $getAuthorityAge('contract'),
            $getAuthorityAge('broker'),
            $this->dotAgeFrom($carrier->add_date ?? $detail?->add_date),
            $mcs150Year,
            $observedUnits,
            $observedTrailers,
            (int) ($crashStats['crashes_total'] ?? 0),
            (int) ($crashStats['fatalities'] ?? 0),
            (int) ($crashStats['injuries'] ?? 0),
            (int) ($crashStats['tow_away'] ?? 0),
        );

        return $trustScore;
    }

    /**
     * Full card fields for a small, already-known set of carriers, by their
     * internal id - active authority, insurance, a DT score, and the risk
     * pill. For screens like the blocklist that show a short, bounded list
     * rather than a live search page: everything here runs synchronously,
     * no background job, on the assumption the caller's list stays in the
     * tens, not the hundreds. computePageScores() above is for the search
     * page precisely because that assumption does not hold there.
     *
     * Mirrors CarrierShortlistController's enrichedCarriers()/enrichOne(),
     * which duplicated this shape first - see that controller's docblock
     * for why. This is the third caller of the same "given some carriers,
     * get authority/insurance/score" need, so it goes through the shared
     * engine hookup above (runTrustScoreEngine) instead of a third copy of
     * the score-assembly glue; the shortlist controller's own copy is left
     * exactly as it is, unrelated to this change.
     *
     * @param  array<int, int>  $carrierIds
     * @return array<int, array> keyed by carrier id
     */
    public function enrichCarriers(array $carrierIds): array
    {
        if (empty($carrierIds)) {
            return [];
        }

        $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);

        try {
            $carriers = Carrier::whereIn('id', $carrierIds)
                ->with([
                    'carrierDetail',
                    'smsMeasures',
                    'authority',
                    'oosOrders',
                    'authorityOrders',
                    'insuranceFilings',
                    'insuranceFilingsPending',
                    'insuranceFilingsHistory',
                    'authorityHistory:dot_number,op_auth_type,original_action_desc,orig_served_date,disp_action_desc',
                    'crashes:dot_number,report_date,fatalities,injuries,tow_away',
                    'inspections:dot_number,insp_date,vin',

                    // Same reasoning as computePageScoresWithin() above -
                    // column-limited, OPS-12 (v3.5) only reads viol_code.
                    'violationDetails:dot_number,viol_code',
                ])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[CarrierBlocked] carrier load failed: '.$e->getMessage());

            return [];
        }

        if ($carriers->isEmpty()) {
            return [];
        }

        $dots = $carriers->pluck('dot_number')->all();

        $inspectionStats = [];
        $crashStats = [];

        try {
            $inspectionStats = $this->dtInspectionStatsFor($dots);
            $crashStats = $this->dtCrashStatsFor($dots);
            $this->dtPrewarmNetworkCounts($carriers);
        } catch (\Throwable $e) {
            Log::warning('[CarrierBlocked] stats/prewarm failed: '.$e->getMessage());
        } finally {
            $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);
        }

        $out = [];

        foreach ($carriers as $carrier) {
            $out[$carrier->id] = $this->enrichOne(
                $carrier,
                $inspectionStats[$carrier->dot_number] ?? [],
                $crashStats[$carrier->dot_number] ?? [],
            );
        }

        return $out;
    }

    /**
     * One carrier's card fields: mc/duns, authority, insurance, and a
     * cache-first-then-live DT score with its risk pill. Never throws - a
     * failure anywhere here means that carrier's card shows blank
     * enrichment fields, not a 500 for the whole list.
     */
    private function enrichOne(Carrier $carrier, array $inspectionStats, array $crashStats): array
    {
        $auth = $carrier->authority;

        // Same definition CarrierController's own profile page uses for
        // both these fields - a carrier with any live authority type reads
        // as both "active" and "verified" together, on purpose.
        $authorityActive = Fmcsa::isActive($auth?->common_stat)
            || Fmcsa::isActive($auth?->contract_stat)
            || Fmcsa::isActive($auth?->broker_stat);

        $insuranceCurrent = false;

        try {
            $insuranceCurrent = $this->hasInsuranceFiling($carrier, 'bipd');
        } catch (\Throwable $e) {
            Log::warning("[CarrierBlocked] insurance check failed for DOT {$carrier->dot_number}: ".$e->getMessage());
        }

        $dot = (string) $carrier->dot_number;
        $cached = Cache::get('carrier_dt_score:'.$dot);

        $score = null;
        $status = null;

        if ($cached !== null) {
            // Same limitation as the shortlist controller's cache-hit path:
            // only the bare score is ever cached, so a hit here has no
            // status to derive risk_level from. The gauge still shows the
            // right number; the risk pill goes blank rather than guessed.
            $score = (int) $cached;
        } else {
            $this->setStatementTimeout(self::SCORE_TIMEOUT_MS);

            try {
                $trustScore = $this->runTrustScoreEngine($carrier, $inspectionStats, $crashStats);

                if (isset($trustScore['overall_score'])) {
                    $score = (int) $trustScore['overall_score'];
                    $status = $trustScore['status'] ?? null;

                    Cache::put('carrier_dt_score:'.$dot, $score, now()->addHours(self::SEARCH_SCORE_TTL_HOURS));
                }
            } catch (\Throwable $e) {
                Log::warning("[CarrierBlocked] DT scoring failed for DOT {$dot}: ".$e->getMessage());
            } finally {
                $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);
            }
        }

        return [
            'mc_number' => $auth?->docket_number,
            'duns' => $carrier->carrierDetail?->dun_bradstreet_no,
            'active_authority' => $authorityActive,
            'authority_verified' => $authorityActive,
            'insurance_current' => $insuranceCurrent,
            'dt_score' => $score,
            'risk_level' => $this->riskLevelFor($status),
        ];
    }

    /**
     * The engine's own vocabulary (Approved / Review / High Risk /
     * Rejected), relabelled for the risk pill the card shows. No new
     * thresholds invented here - reads the same status string
     * dtCalculateTrustScore() already decided, just renamed for the UI.
     * Identical to CarrierShortlistController's copy of the same mapping.
     */
    private function riskLevelFor(?string $status): ?string
    {
        return match ($status) {
            'Approved' => 'Low',
            'Review' => 'Medium',
            'High Risk', 'Rejected' => 'High',
            default => null,
        };
    }

    /**
     * observed_units / observed_trailers for a page of carriers, keyed by
     * DOT number.
     *
     * Counts distinct VINs across both inspection unit slots - a truck
     * stopped nine times is one unit - done in SQL because it needs
     * unit_type_desc and the vin2 slot, neither of which is worth eager-
     * loading onto every carrier just for this.
     *
     * Used to compute insp_total and last_insp_date too, before the v3.3
     * engine started deriving both itself off $carrier->inspections. That
     * query is gone - it was a second full statement per page for values
     * nothing reads anymore.
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, array<string, mixed>>
     */
    private function dtInspectionStatsFor(array $dots): array
    {
        if (! $dots) {
            return [];
        }

        $connection = $this->conn();

        $placeholders = implode(',', array_fill(0, count($dots), '?'));

        // The feed's own vocabulary for a self-driven unit; everything towed
        // is matched on the words that appear in the trailer types.
        $power = [
            'TRUCK TRACTOR',
            'STRAIGHT TRUCK',
            'BUS',
            'SCHOOL BUS',
            'MOTOR COACH',
            'PASSENGER VAN',
            'LIMOUSINE',
        ];

        $powerPlaceholders = implode(',', array_fill(0, count($power), '?'));

        $sql = <<<SQL
            SELECT dot_number,
                   COUNT(DISTINCT CASE WHEN unit_type IN ({$powerPlaceholders}) THEN unit_vin END) AS observed_units,
                   COUNT(DISTINCT CASE WHEN unit_type LIKE '%TRAILER%'
                                          OR unit_type LIKE '%CHASSIS%'
                                          OR unit_type LIKE '%DOLLY%'
                                       THEN unit_vin END) AS observed_trailers
            FROM (
                SELECT dot_number, vin AS unit_vin, unit_type_desc AS unit_type
                FROM inspections
                WHERE dot_number IN ({$placeholders}) AND vin IS NOT NULL AND vin <> ''
                UNION ALL
                SELECT dot_number, vin2 AS unit_vin, unit_type_desc2 AS unit_type
                FROM inspections
                WHERE dot_number IN ({$placeholders}) AND vin2 IS NOT NULL AND vin2 <> ''
            ) AS slots
            GROUP BY dot_number
            SQL;

        $units = $connection->select($sql, [...$power, ...$dots, ...$dots]);

        $stats = [];

        foreach ($units as $row) {
            $stats[$row->dot_number] = [
                'observed_units' => (int) $row->observed_units,
                'observed_trailers' => (int) $row->observed_trailers,
            ];
        }

        return $stats;
    }

    /**
     * Crash totals for a page of carriers, keyed by DOT number.
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, array<string, int>>
     */
    private function dtCrashStatsFor(array $dots): array
    {
        if (! $dots) {
            return [];
        }

        return $this->conn()
            ->table('crashes')
            ->selectRaw('dot_number,
                         COUNT(*) AS crashes_total,
                         COALESCE(SUM(fatalities), 0) AS fatalities,
                         COALESCE(SUM(injuries), 0) AS injuries,
                         COALESCE(SUM(tow_away = 1), 0) AS tow_away')
            ->whereIn('dot_number', $dots)
            ->groupBy('dot_number')
            ->get()
            ->keyBy('dot_number')
            ->map(fn ($row) => [
                'crashes_total' => (int) $row->crashes_total,
                'fatalities' => (int) $row->fatalities,
                'injuries' => (int) $row->injuries,
                'tow_away' => (int) $row->tow_away,
            ])
            ->all();
    }

    /* ======================================================================
     | Fraud-network pre-warm
     |
     | dtNetworkCounts() in the engine asks, for one carrier: how many OTHER
     | carriers share this phone, this email, this street address, one of
     | these VINs. Four questions, each a COUNT(DISTINCT dot_number) over a
     | 4.48M-row view, and it wraps the answer in Cache::remember for six
     | hours - so a carrier profile pays it once and never again.
     |
     | A search page pays it ten times, on ten cold keys, and measured ~4s
     | each: 40 of the 68 seconds a first search took.
     |
     | Nothing here changes the engine or the score. It answers the same four
     | questions for the whole page in four queries instead of forty, then
     | writes the results into the very keys dtNetworkCounts() is about to
     | read. By the time the engine runs, every Cache::remember is a hit, so
     | the score is computed from identical inputs - the profile page and the
     | search still agree to the digit.
     |
     | v3.3 changed what those four questions actually ask: a shared
     | identifier no longer counts against a carrier when the OTHER party's
     | legal_name shares its name stem (KAPLAN TRUCKING / KAPLAN LOGISTICS at
     | one HQ read as family, not a chameleon reincarnation) - see
     | dtNameStem() on the engine. That changed the cache key too
     | (dt:trust:net: -> dt:trust:net:v2:), because the old key's values were
     | computed a different way and would otherwise be served back as if
     | they were the new answer. Every helper below applies the identical
     | exclusion, so a value this file writes and a value the engine would
     | have computed cold are the same number, not an approximation of it.
     |
     | Everything degrades: if any of this throws, the keys simply stay cold
     | and the engine computes them itself, exactly as before, only slower.
     ====================================================================== */

    /**
     * Fill dt:trust:net:v2:{dot} for a page of carriers before scoring them.
     *
     * @param  \Illuminate\Support\Collection<int, Carrier>  $carriers
     */
    private function dtPrewarmNetworkCounts($carriers): void
    {
        // The engine returns null immediately when this is off, and never
        // touches the cache - so pre-warming would be writing keys nothing
        // will ever read.
        if (! config('trustscore.network_checks', true)) {
            return;
        }

        $pending = [];

        foreach ($carriers as $carrier) {
            $dot = (string) $carrier->dot_number;

            if ($dot === '') {
                continue;
            }

            // Already warm - almost always the majority of a page, since the
            // keys live six hours and carriers recur across searches.
            if (Cache::get('dt:trust:net:v2:'.$dot) !== null) {
                continue;
            }

            $pending[$dot] = $carrier;
        }

        if (empty($pending)) {
            return;
        }

        try {
            $phones = $this->dtSharedFieldCounts($pending, 'telephone');
            $emails = $this->dtSharedFieldCounts($pending, 'email_address');
            $addresses = $this->dtSharedAddressCounts($pending);
            $vins = $this->dtSharedVinCounts($pending);

            foreach ($pending as $dot => $carrier) {
                /*
                | Key, TTL and array shape all copied from dtNetworkCounts()
                | deliberately. A missing entry stays null rather than
                | becoming 0, because the engine distinguishes them: null is
                | "no phone on file, cannot ask", 0 is "asked, shares it with
                | nobody", and the identity rules read those differently.
                */
                Cache::put('dt:trust:net:v2:'.$dot, [
                    'phone' => $phones[$dot] ?? null,
                    'email' => $emails[$dot] ?? null,
                    'address' => $addresses[$dot] ?? null,
                    'vin' => $vins[$dot] ?? null,
                ], 21600);
            }
        } catch (\Throwable $e) {
            Log::warning('[CarrierSearch] network prewarm failed, engine will fall back to per-carrier: '.$e->getMessage());
        }
    }

    /**
     * How many other carriers share each page carrier's phone / email.
     *
     * The engine asks with `dot_number <> self AND legal_name NOT LIKE
     * {self's name stem}%` - self excluded explicitly, an affiliate (same
     * stem) excluded because it isn't the chameleon pattern the rule exists
     * to catch. Every carrier on the page can have a different stem even
     * when two of them share the same phone, so the bucket a value falls
     * into is (value, stem) together, not the value alone - two carriers
     * only ever share a bucket when both the value AND the stem match,
     * which is exactly when they'd get the same answer from the engine too.
     *
     * Self-subtraction only happens when no stem filter ran: when it did,
     * self is already excluded by name (a carrier's own legal_name always
     * matches its own stem), and subtracting again would undercount by one.
     *
     * UNION ALL of one indexed `=` lookup per bucket, NOT `IN (...) GROUP
     * BY`. That distinction is worth 26 seconds: `carriers` is a view, and
     * grouping on a view column makes MySQL materialise and scan all 4.48M
     * rows, while plain equality merges into the view and rides idx_phone /
     * idx_email. Measured on one page of ten: 26.2s grouped, 0.56s this way,
     * identical numbers out. One round trip either way.
     *
     * @param  array<string, Carrier>  $pending
     * @return array<string, int>
     */
    private function dtSharedFieldCounts(array $pending, string $column): array
    {
        // Whitelisted because it is interpolated into SQL below. Both are
        // this file's own literals today; this keeps it that way.
        if (! in_array($column, ['telephone', 'email_address'], true)) {
            return [];
        }

        $buckets = [];      // "value\0stem" => bucket index
        $bucketMeta = [];   // bucket index => ['value' => ..., 'stem_like' => ...|null]

        foreach ($pending as $carrier) {
            // empty(), not === '', to match the engine: it skips '0' too.
            if (empty($carrier->{$column})) {
                continue;
            }

            // A string value, not an array key: PHP turns a numeric-string
            // key like a phone number into an int, and binding an int
            // against a varchar column is how you quietly lose the index.
            $value = (string) $carrier->{$column};

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $stemLike = $stem !== null ? addcslashes($stem, '\\%_').'%' : null;

            $bucketKey = $value."\0".($stem ?? '');

            if (isset($buckets[$bucketKey])) {
                continue;
            }

            $buckets[$bucketKey] = count($bucketMeta);

            $bucketMeta[] = ['value' => $value, 'stem_like' => $stemLike];
        }

        if (empty($bucketMeta)) {
            return [];
        }

        $parts = [];
        $bindings = [];

        foreach ($bucketMeta as $i => $meta) {

            if ($meta['stem_like'] !== null) {
                $parts[] = "SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers WHERE {$column} = ? AND legal_name NOT LIKE ?";
                $bindings[] = $i;
                $bindings[] = $meta['value'];
                $bindings[] = $meta['stem_like'];
            } else {
                $parts[] = "SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers WHERE {$column} = ?";
                $bindings[] = $i;
                $bindings[] = $meta['value'];
            }
        }

        $shared = [];

        foreach ($this->conn()->select(implode(' UNION ALL ', $parts), $bindings) as $row) {
            $shared[(int) $row->bucket] = (int) $row->shared;
        }

        $out = [];

        foreach ($pending as $dot => $carrier) {
            if (empty($carrier->{$column})) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $bucketKey = ((string) $carrier->{$column})."\0".($stem ?? '');

            $bucket = $buckets[$bucketKey];

            $count = $shared[$bucket] ?? 0;

            if ($bucketMeta[$bucket]['stem_like'] === null) {
                $count = max(0, $count - 1);
            }

            $out[(string) $dot] = $count;
        }

        return $out;
    }

    /**
     * How many other carriers sit at each page carrier's physical address.
     *
     * Matched on the same three columns the engine matches on, plus the same
     * name-stem exclusion and (value, stem) bucketing as
     * dtSharedFieldCounts() above - see that method's docblock for why both
     * exist. UNION ALL of plain equality so each branch still rides idx_phy
     * (phy_state, phy_city, phy_street), rather than a row-constructor IN
     * with a GROUP BY, which scans the view.
     *
     * @param  array<string, Carrier>  $pending
     * @return array<string, int>
     */
    private function dtSharedAddressCounts(array $pending): array
    {
        // Same guard as the engine: a street too short to be a real address
        // is not worth asking about, and would group half the feed together.
        $usable = fn ($c) => ! empty($c->phy_street) && strlen(trim((string) $c->phy_street)) > 5;

        $addressKey = fn ($c) => implode("\0", [(string) $c->phy_state, (string) $c->phy_city, (string) $c->phy_street]);

        $buckets = [];      // "state\0city\0street\0stem" => bucket index
        $bucketMeta = [];   // bucket index => ['tuple' => [state, city, street], 'stem_like' => ...|null]

        foreach ($pending as $carrier) {
            if (! $usable($carrier)) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $stemLike = $stem !== null ? addcslashes($stem, '\\%_').'%' : null;

            $bucketKey = $addressKey($carrier)."\0".($stem ?? '');

            if (isset($buckets[$bucketKey])) {
                continue;
            }

            $buckets[$bucketKey] = count($bucketMeta);

            $bucketMeta[] = [
                'tuple' => [(string) $carrier->phy_state, (string) $carrier->phy_city, (string) $carrier->phy_street],
                'stem_like' => $stemLike,
            ];
        }

        if (empty($bucketMeta)) {
            return [];
        }

        $parts = [];
        $bindings = [];

        foreach ($bucketMeta as $i => $meta) {

            $bindings[] = $i;

            array_push($bindings, ...$meta['tuple']);

            if ($meta['stem_like'] !== null) {
                $parts[] = 'SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers
                             WHERE phy_state = ? AND phy_city = ? AND phy_street = ? AND legal_name NOT LIKE ?';

                $bindings[] = $meta['stem_like'];
            } else {
                $parts[] = 'SELECT ? AS bucket, COUNT(DISTINCT dot_number) AS shared FROM carriers
                             WHERE phy_state = ? AND phy_city = ? AND phy_street = ?';
            }
        }

        $shared = [];

        foreach ($this->conn()->select(implode(' UNION ALL ', $parts), $bindings) as $row) {
            $shared[(int) $row->bucket] = (int) $row->shared;
        }

        $out = [];

        foreach ($pending as $dot => $carrier) {
            if (! $usable($carrier)) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $bucketKey = $addressKey($carrier)."\0".($stem ?? '');

            $bucket = $buckets[$bucketKey];

            $count = $shared[$bucket] ?? 0;

            if ($bucketMeta[$bucket]['stem_like'] === null) {
                $count = max(0, $count - 1);
            }

            $out[(string) $dot] = $count;
        }

        return $out;
    }

    /**
     * How many other carriers have been inspected in the same trucks.
     *
     * Two queries for the page's own VINs and who else has them, plus a
     * third for the affiliate exclusion - see dtSharedFieldCounts() for why
     * that exists. It has to be a separate lookup here: an owner's stem
     * membership depends on the TARGET carrier's name, not the owner's, so
     * unlike phone/email/address there is no single WHERE clause that can
     * apply it while the counting is still happening in SQL. Instead every
     * distinct owner DOT across the whole page is named-looked-up once, and
     * the exclusion is applied in PHP while tallying each target's count.
     *
     * The engine caps each carrier at 200 VINs and so does this - for a
     * carrier with more than 200 the two can pick a different 200, since
     * neither orders the rows, but the signal being scored is "is this truck
     * shared at all", which does not turn on which 200 were sampled.
     *
     * @param  array<string, Carrier>  $pending
     * @return array<string, int>
     */
    private function dtSharedVinCounts(array $pending): array
    {
        $dots = array_map('strval', array_keys($pending));

        // DISTINCT because a truck stopped fifty times is one VIN, and the
        // busiest carriers in the feed have over 22,000 inspection rows.
        $vinRows = $this->conn()
            ->table('inspections')
            ->select('dot_number', 'vin')
            ->distinct()
            ->whereIn('dot_number', $dots)
            ->whereRaw('CHAR_LENGTH(vin) = 17')
            ->get();

        $perCarrier = [];

        foreach ($vinRows as $row) {
            $dot = (string) $row->dot_number;

            if (count($perCarrier[$dot] ?? []) >= 200) {
                continue;
            }

            $perCarrier[$dot][] = (string) $row->vin;
        }

        if (empty($perCarrier)) {
            return [];
        }

        $allVins = array_values(array_unique(array_merge(...array_values($perCarrier))));

        $owners = [];

        // Chunked so the IN() list stays inside what the server will plan
        // for, even on a full page of heavily-inspected carriers.
        foreach (array_chunk($allVins, 1000) as $chunk) {
            $rows = $this->conn()
                ->table('inspections')
                ->select('vin', 'dot_number')
                ->distinct()
                ->whereIn('vin', $chunk)
                ->get();

            foreach ($rows as $row) {
                $owners[(string) $row->vin][(string) $row->dot_number] = true;
            }
        }

        /*
        | Every distinct "other" owner across the whole page, named in one
        | batch - not per target carrier, since the same owner DOT can turn
        | up against several targets on a busy page and its name never
        | changes between them.
        */
        $ownerDots = [];

        foreach ($pending as $dot => $carrier) {
            $dot = (string) $dot;

            foreach ($perCarrier[$dot] ?? [] as $vin) {
                foreach (array_keys($owners[$vin] ?? []) as $owner) {
                    if ((string) $owner !== $dot) {
                        $ownerDots[(string) $owner] = true;
                    }
                }
            }
        }

        $ownerNames = [];

        if (! empty($ownerDots)) {
            $ownerNames = $this->conn()
                ->table('carriers')
                ->select('dot_number', 'legal_name')
                ->whereIn('dot_number', array_keys($ownerDots))
                ->pluck('legal_name', 'dot_number')
                ->all();
        }

        $out = [];

        foreach ($pending as $dot => $carrier) {
            $dot = (string) $dot;

            // No usable VINs means the engine leaves this null, not zero.
            if (empty($perCarrier[$dot])) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);

            $others = [];

            foreach ($perCarrier[$dot] as $vin) {
                foreach (array_keys($owners[$vin] ?? []) as $owner) {
                    // Both sides cast to string on purpose: PHP turns numeric
                    // array keys into ints, so a bare !== would compare "123"
                    // against 123 and call every owner a different carrier.
                    $owner = (string) $owner;

                    if ($owner === $dot) {
                        continue;
                    }

                    if ($stem !== null) {
                        $ownerName = strtoupper((string) ($ownerNames[$owner] ?? ''));

                        // Case-insensitive prefix match in PHP, not a SQL
                        // LIKE - this never touches the database, so it
                        // cannot silently depend on the column's collation
                        // being case-insensitive the way the engine's own
                        // `LIKE` implicitly does.
                        if (str_starts_with($ownerName, $stem)) {
                            continue;
                        }
                    }

                    $others[$owner] = true;
                }
            }

            $out[$dot] = count($others);
        }

        return $out;
    }

    /**
     * Affiliate name stem, identical to the engine's dtNameStem(): uppercase,
     * punctuation stripped, leading article (THE/A/AN) dropped, first token
     * kept when it is 4+ characters. Named differently from the trait's own
     * private method of the same job so composing DtTrustScoreV3 can never
     * silently pick this one up instead of its own - a plain class method
     * always wins over an identically-named trait method, so a genuine
     * name clash here would mean the engine's internal exclusion quietly
     * started running this class's logic instead of its own.
     */
    private function dtSearchNameStem($name): ?string
    {
        $name = strtoupper(trim((string) ($name ?? '')));

        $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name);

        $tokens = array_values(array_filter(explode(' ', (string) $name)));

        if (isset($tokens[0]) && in_array($tokens[0], ['THE', 'A', 'AN'], true)) {
            array_shift($tokens);
        }

        $stem = $tokens[0] ?? '';

        return strlen($stem) >= 4 ? $stem : null;
    }

    /**
     * Years since a DOT number was issued.
     *
     * Same derivation as the carrier profile so a search row and the profile
     * arrive at the same age, and therefore the same score. The feed writes
     * dates as '01-JUN-74', which Carbon cannot parse on its own.
     */
    private function dotAgeFrom($addDate): ?int
    {
        if (! $addDate) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($addDate))) {

                $year = substr($addDate, -2);

                // FMCSA data started long before 2000, so 74 -> 1974, 06 -> 2006.
                $century = $year > date('y') ? '19' : '20';

                $parsedDate = Carbon::createFromFormat('d-M-Y', strtoupper(substr($addDate, 0, -2).$century.$year));

            } else {

                $parsedDate = Carbon::parse($addDate);

            }

            return (int) $parsedDate->diffInYears(now());

        } catch (\Throwable $e) {
            return null;
        }
    }
}
