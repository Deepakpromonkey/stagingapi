<?php

namespace App\Services\DtScore;

use App\Http\Controllers\Carrier\Concerns\DtTrustScoreSupport;
use App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3;
use App\Models\Carriers\Carrier;
use App\Models\Carriers\CarrierAuthority;
use App\Support\Fmcsa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a DT Trust Score is calculated.
 *
 *     DtScore::for(dot: '1234567');      // full result: score, grade, status, explanation …
 *     DtScore::for(mc: 'MC012345');      // same, looked up by MC / docket number
 *     DtScore::value(dot: '1234567');    // just the 0-100 number, cached
 *     DtScore::many(['123', '456']);     // [dot => number] for a page of carriers, cached
 *
 * Every scoring number lives in config/dtscore.php; the rule logic lives in
 * the DtTrustScoreV3 trait. This class finds the carrier, assembles the
 * seventeen inputs the engine takes, and caches the result — the work the
 * carrier profile, the advanced search and the shortlist used to each do
 * with their own copy.
 *
 * Cached scores are keyed by a fingerprint of config/dtscore.php, so editing
 * any parameter there retires every cached score at once: the next request
 * for a carrier recalculates it with the new numbers.
 */
final class DtScore
{
    use DtTrustScoreSupport, DtTrustScoreV3;

    /**
     * Everything the engine reads off the carrier, column-limited where it
     * reads only a few fields. Inspections and crashes in particular can run
     * to tens of thousands of rows per carrier, so only the columns the
     * rules touch are hydrated.
     */
    private const RELATIONS = [
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
        'violationDetails:dot_number,viol_code',
    ];

    private ?string $configFingerprint = null;

    /** Bump when the cached entry gains or loses a field. */
    private const CACHE_SHAPE = 2;

    /** Self-driven unit types, in the feed's own vocabulary. */
    private const POWER_UNIT_TYPES = [
        'TRUCK TRACTOR',
        'STRAIGHT TRUCK',
        'BUS',
        'SCHOOL BUS',
        'MOTOR COACH',
        'PASSENGER VAN',
        'LIMOUSINE',
    ];

    /*
    |--------------------------------------------------------------------------
    | Entry points
    |--------------------------------------------------------------------------
    */

    /**
     * Full score for one carrier, by DOT number or MC / docket number.
     * Always calculated fresh. Null when no carrier matches.
     */
    public static function for(?string $dot = null, ?string $mc = null): ?array
    {
        $score = self::instance();

        $carrier = $score->findCarrier($dot, $mc);

        return $carrier ? $score->calculate($carrier) : null;
    }

    /**
     * Just the 0-100 score, by DOT or MC number. Served from cache when a
     * score for the current config exists, otherwise calculated and cached.
     */
    public static function value(?string $dot = null, ?string $mc = null): ?int
    {
        $score = self::instance();

        $dot ??= $score->dotForMc($mc);

        if ($dot === null || $dot === '') {
            return null;
        }

        return self::many([$dot])[$dot] ?? null;
    }

    /**
     * Scores for a page of carriers, keyed by DOT number. Cached scores are
     * reused; the rest are calculated in one batch — a fixed handful of
     * queries however many carriers are on the page. A carrier whose data
     * cannot be scored is left out rather than failing the whole page.
     *
     * @param  array<int, mixed>  $dots
     * @return array<string, int>
     */
    public static function many(array $dots): array
    {
        return array_map(fn ($entry) => $entry['score'], self::manyWithStatus($dots));
    }

    /**
     * As many(), with the engine's legacy status (Approved / Review /
     * High Risk / Rejected) alongside each score.
     *
     * @param  array<int, mixed>  $dots
     * @return array<string, array{score: int, status: ?string, band: ?string, needs_manual_review: bool}>
     */
    public static function manyWithStatus(array $dots): array
    {
        $dots = array_values(array_unique(array_filter(
            array_map(fn ($d) => trim((string) $d), $dots),
            fn ($d) => $d !== ''
        )));

        if (empty($dots)) {
            return [];
        }

        $score = self::instance();

        $results = [];
        $pending = [];

        foreach ($dots as $dot) {

            $cached = Cache::get($score->cacheKey($dot));

            if (is_array($cached)) {
                $results[$dot] = $cached;
            } else {
                $pending[] = $dot;
            }

        }

        if ($pending) {
            $results += $score->calculateMany($pending);
        }

        return $results;
    }

    /**
     * Full score for a carrier that is already loaded — the carrier profile,
     * which has every relation in memory for its own page. Pass the
     * inspection / crash figures when you have them; anything missing is
     * counted from the database.
     *
     * @param  array{observed_units?: int, observed_trailers?: int}|null  $inspectionStats
     * @param  array{crashes_total?: int, fatalities?: int, injuries?: int, tow_away?: int}|null  $crashStats
     */
    public static function forCarrier(Carrier $carrier, ?array $inspectionStats = null, ?array $crashStats = null): array
    {
        $score = self::instance();

        $result = $score->calculate($carrier, $inspectionStats, $crashStats);

        $score->remember((string) $carrier->dot_number, $result);
        $score->recordEvaluation((string) $carrier->dot_number, $result);

        return $result;
    }

    /**
     * Full, freshly calculated results for a few DOTs, loading each carrier
     * the same way a search page does - for verification packs and audits.
     *
     * @param  array<int, string>  $dots
     * @return array<string, array>
     */
    public static function fullResultsFor(array $dots): array
    {
        $score = self::instance();

        $results = [];

        foreach (Carrier::query()->whereIn('dot_number', $dots)->with(self::RELATIONS)->get() as $carrier) {
            $results[(string) $carrier->dot_number] = self::forCarrier($carrier);
        }

        return $results;
    }

    /**
     * Does the carrier hold a live (uncancelled) filing of this coverage
     * kind — 'bipd', 'cargo' or 'bond'? The same test the score applies,
     * for cards that show insurance status next to the score. Needs the
     * carrier's insuranceFilings relation.
     */
    public static function hasLiveInsurance(Carrier $carrier, string $kind): bool
    {
        return self::instance()->hasInsuranceFiling($carrier, $kind);
    }

    /**
     * Cached numbers for many DOTs in one cache round trip, without
     * calculating anything — for exports too large to score live.
     *
     * @param  array<int, mixed>  $dots
     * @return array<string, int>
     */
    public static function cachedMany(array $dots): array
    {
        $score = self::instance();

        $keys = [];

        foreach ($dots as $dot) {
            $dot = trim((string) $dot);

            if ($dot !== '') {
                $keys[$score->cacheKey($dot)] = $dot;
            }
        }

        if (! $keys) {
            return [];
        }

        $scores = [];

        foreach (Cache::many(array_keys($keys)) as $key => $cached) {
            if (is_array($cached)) {
                $scores[$keys[$key]] = $cached['score'];
            }
        }

        return $scores;
    }

    /**
     * Put a known score into the cache, as if it had just been calculated —
     * for tests that must not reach the carrier database.
     */
    public static function store(string $dot, int $score, ?string $status = null, ?string $band = null, bool $needsManualReview = false): void
    {
        self::instance()->remember($dot, [
            'overall_score' => $score,
            'status' => $status,
            'band' => $band === null ? null : ['key' => $band],
            'needs_manual_review' => $needsManualReview,
        ]);
    }

    /** Letter grade for a 0-100 score, from dtscore.grades. */
    public static function grade(int $score): string
    {
        return self::instance()->getGrade($score);
    }

    /**
     * Cached entries (score, status, band, review flag) for many DOTs in one
     * cache round trip, without calculating anything.
     *
     * @param  array<int, mixed>  $dots
     * @return array<string, array{score: int, status: ?string, band: ?string, needs_manual_review: bool}>
     */
    public static function cachedEntries(array $dots): array
    {
        $score = self::instance();

        $keys = [];

        foreach ($dots as $dot) {
            $dot = trim((string) $dot);

            if ($dot !== '') {
                $keys[$score->cacheKey($dot)] = $dot;
            }
        }

        if (! $keys) {
            return [];
        }

        $entries = [];

        foreach (Cache::many(array_keys($keys)) as $key => $cached) {
            if (is_array($cached)) {
                $entries[$keys[$key]] = $cached;
            }
        }

        return $entries;
    }

    /** The cached number for a DOT, without calculating anything. */
    public static function cached(string $dot): ?int
    {
        $cached = Cache::get(self::instance()->cacheKey($dot));

        return is_array($cached) ? $cached['score'] : null;
    }

    /**
     * One instance per request (or queued job), so the national benchmark
     * memo the support trait keeps is read once per request, not per carrier.
     */
    private static function instance(): self
    {
        return app(self::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Finding the carrier
    |--------------------------------------------------------------------------
    */

    private function findCarrier(?string $dot, ?string $mc): ?Carrier
    {
        $dot = $dot !== null ? trim($dot) : null;

        if ($dot === null || $dot === '') {
            $dot = $this->dotForMc($mc);
        }

        if ($dot === null || $dot === '') {
            return null;
        }

        return Carrier::query()
            ->where('dot_number', $dot)
            ->with(self::RELATIONS)
            ->first();
    }

    /**
     * DOT number for an MC / docket number. Accepts 'MC012345', 'MC-12345',
     * 'mc 12345' or a bare '12345'; the feed stores 'MC' plus at least six
     * zero-padded digits.
     */
    private function dotForMc(?string $mc): ?string
    {
        $mc = strtoupper(trim((string) $mc));

        if ($mc === '') {
            return null;
        }

        $prefix = preg_match('/^([A-Z]{2})/', $mc, $m) ? $m[1] : 'MC';

        $digits = preg_replace('/\D+/', '', $mc);

        if ($digits === '') {
            return null;
        }

        $docket = $prefix.str_pad(ltrim($digits, '0'), 6, '0', STR_PAD_LEFT);

        $dot = CarrierAuthority::query()
            ->where('docket_number', $docket)
            ->value('dot_number');

        return $dot !== null ? (string) $dot : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Calculating
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, string>  $dots
     * @return array<string, array{score: int, status: ?string}>
     */
    private function calculateMany(array $dots): array
    {
        $carriers = Carrier::query()
            ->whereIn('dot_number', $dots)
            ->with(self::RELATIONS)
            ->get();

        if ($carriers->isEmpty()) {
            return [];
        }

        $loaded = $carriers->pluck('dot_number')->all();

        $inspectionStats = $this->inspectionStatsFor($loaded);
        $crashStats = $this->crashStatsFor($loaded);

        app(NetworkCountsPrewarmer::class)->prewarm($carriers);

        $results = [];

        foreach ($carriers as $carrier) {

            $dot = (string) $carrier->dot_number;

            try {
                $result = $this->calculate(
                    $carrier,
                    $inspectionStats[$carrier->dot_number] ?? [],
                    $crashStats[$carrier->dot_number] ?? [],
                );
            } catch (\Throwable $e) {
                // One carrier's bad data must not cost the rest of the page
                // their scores.
                Log::warning("[DtScore] scoring failed for DOT {$dot}: ".$e->getMessage());

                continue;
            }

            if ($entry = $this->remember($dot, $result)) {
                $results[$dot] = $entry;
            }

            $this->recordEvaluation($dot, $result);

        }

        return $results;
    }

    /**
     * Assemble the engine's inputs off a loaded carrier and run it.
     *
     * @param  array<string, int>|null  $inspectionStats
     * @param  array<string, int>|null  $crashStats
     */
    private function calculate(Carrier $carrier, ?array $inspectionStats = null, ?array $crashStats = null): array
    {
        $dot = $carrier->dot_number;

        $inspectionStats ??= $this->inspectionStatsFor([$dot])[$dot] ?? [];
        $crashStats ??= $this->crashStatsFor([$dot])[$dot] ?? [];

        $detail = $carrier->carrierDetail;
        $sms = $carrier->smsMeasures;

        $vehicleInspTotal = (int) ($sms?->vehicle_insp_total ?? 0);
        $vehicleOosTotal = (int) ($sms?->vehicle_oos_insp_total ?? 0);
        $driverInspTotal = (int) ($sms?->driver_insp_total ?? 0);
        $driverOosTotal = (int) ($sms?->driver_oos_insp_total ?? 0);

        return $this->dtCalculateTrustScore(
            $carrier,
            $detail,
            $sms,
            $carrier->authority,
            $vehicleInspTotal > 0 ? round($vehicleOosTotal / $vehicleInspTotal * 100, 2) : null,
            $driverInspTotal > 0 ? round($driverOosTotal / $driverInspTotal * 100, 2) : null,
            $this->authorityAgeYears($carrier, 'common'),
            $this->authorityAgeYears($carrier, 'contract'),
            $this->authorityAgeYears($carrier, 'broker'),
            $this->dotAgeYears($carrier->add_date ?? $detail?->add_date),
            $this->mcs150Year($carrier),
            (int) ($inspectionStats['observed_units'] ?? 0),
            (int) ($inspectionStats['observed_trailers'] ?? 0),
            (int) ($crashStats['crashes_total'] ?? 0),
            (int) ($crashStats['fatalities'] ?? 0),
            (int) ($crashStats['injuries'] ?? 0),
            (int) ($crashStats['tow_away'] ?? 0),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    /**
     * Cache the score from a full result, and return what was cached.
     *
     * The band and the review flag ride along so search and shortlist cards
     * can tell a clean 83 from a flagged one without recalculating.
     *
     * @return array{score: int, status: ?string, band: ?string, needs_manual_review: bool}|null
     */
    private function remember(string $dot, array $result): ?array
    {
        if (! isset($result['overall_score'])) {
            return null;
        }

        $entry = [
            'score' => (int) $result['overall_score'],
            'status' => $result['status'] ?? null,
            'band' => $result['band']['key'] ?? null,
            'needs_manual_review' => (bool) ($result['needs_manual_review'] ?? false),
        ];

        Cache::put($this->cacheKey($dot), $entry, now()->addHours((int) config('dtscore.cache_hours')));

        return $entry;
    }

    /**
     * Keep the receipt for a freshly calculated score in
     * trust_score_evaluations, once per distinct result: the same score,
     * status, fired rules and cap under the same config write nothing new.
     *
     * Never allowed to cost a broker their score, so a failure here (the
     * table not migrated yet, a lost connection) is logged and dropped.
     */
    public function recordEvaluation(string $dot, array $result): void
    {
        if (! isset($result['overall_score'])) {
            return;
        }

        $this->cacheKey($dot); // sets the config fingerprint

        try {
            DB::table('trust_score_evaluations')->insertOrIgnore([
                'dot_number' => $dot,
                'model_version' => (string) config('dtscore.model_version'),
                'config_fingerprint' => $this->configFingerprint,
                'score' => (int) $result['overall_score'],
                'status' => (string) ($result['status'] ?? ''),
                'band' => (string) ($result['band']['key'] ?? ''),
                'needs_manual_review' => (bool) ($result['needs_manual_review'] ?? false),
                'payload' => json_encode($result),
                'payload_fingerprint' => sha1(json_encode([
                    $this->configFingerprint,
                    $result['overall_score'],
                    $result['status'] ?? null,
                    array_column($result['v3']['rules_fired'] ?? [], 'id'),
                    $result['v3']['score_cap_applied'] ?? null,
                ])),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DtScore] could not record evaluation for DOT {$dot}: ".$e->getMessage());
        }
    }

    /** The newest evaluation id for a DOT, for stamping a booking. */
    public static function latestEvaluationId(?string $dot): ?int
    {
        $dot = trim((string) $dot);

        if ($dot === '') {
            return null;
        }

        try {
            $id = DB::table('trust_score_evaluations')->where('dot_number', $dot)->max('id');
        } catch (\Throwable) {
            return null;
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * carrier_dt_score:{config fingerprint}:{dot}. The fingerprint changes
     * whenever config/dtscore.php does, or the shape of a cached entry does
     * (CACHE_SHAPE), so stale scores and stale shapes are never served.
     */
    private function cacheKey(string $dot): string
    {
        $this->configFingerprint ??= substr(md5(serialize([config('dtscore'), self::CACHE_SHAPE])), 0, 10);

        return "carrier_dt_score:{$this->configFingerprint}:{$dot}";
    }

    /*
    |--------------------------------------------------------------------------
    | Inputs
    |--------------------------------------------------------------------------
    */

    /** Whole years since the oldest GRANTED authority of this type. */
    private function authorityAgeYears(Carrier $carrier, string $type): ?int
    {
        $types = Fmcsa::authorityType($type);

        $granted = $carrier->authorityHistory
            ->filter(fn ($h) => in_array(strtoupper((string) $h->op_auth_type), $types, true)
                && strtoupper((string) $h->original_action_desc) === 'GRANTED')
            // orig_served_date is a '24-APR-24' string; sorting it as text
            // puts the wrong row first.
            ->sortBy(fn ($h) => Fmcsa::dateKey($h->orig_served_date) ?: PHP_INT_MAX)
            ->first();

        $served = Fmcsa::date($granted?->orig_served_date);

        return $served ? (int) $served->diffInYears(now()) : null;
    }

    /**
     * Years since the DOT number was issued. The feed writes '01-JUN-74',
     * which has no century: later than this year's two digits is 19xx.
     */
    private function dotAgeYears($addDate): ?int
    {
        if (! $addDate) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($addDate))) {

                $year = substr($addDate, -2);

                $century = (int) $year > ((int) date('y') + 10) ? '19' : '20';

                $parsed = Carbon::createFromFormat('d-M-Y', strtoupper(substr($addDate, 0, -2).$century.$year));

            } else {

                $parsed = Carbon::parse($addDate);

            }

            return (int) $parsed->diffInYears(now());

        } catch (\Throwable) {
            return null;
        }
    }

    private function mcs150Year(Carrier $carrier): ?int
    {
        if (! $carrier->mcs150_date) {
            return null;
        }

        try {
            return (int) Carbon::parse($carrier->mcs150_date)->year;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Distinct power units and trailers seen at roadside, keyed by DOT.
     *
     * Counted in SQL because it needs unit_type_desc and the vin2 slot,
     * neither of which is worth hydrating. The two unit slots are stacked
     * with UNION ALL so one VIN recorded in either slot counts once. Relies
     * on the covering indexes idx_dot_vin_type / idx_dot_vin2_type.
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, array{observed_units: int, observed_trailers: int}>
     */
    private function inspectionStatsFor(array $dots): array
    {
        if (! $dots) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($dots), '?'));

        $powerPlaceholders = implode(',', array_fill(0, count(self::POWER_UNIT_TYPES), '?'));

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

        $stats = [];

        foreach ($this->conn()->select($sql, [...self::POWER_UNIT_TYPES, ...$dots, ...$dots]) as $row) {
            $stats[$row->dot_number] = [
                'observed_units' => (int) $row->observed_units,
                'observed_trailers' => (int) $row->observed_trailers,
            ];
        }

        return $stats;
    }

    /**
     * Lifetime crash totals, keyed by DOT. tow_away is a tinyint(1).
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, array{crashes_total: int, fatalities: int, injuries: int, tow_away: int}>
     */
    private function crashStatsFor(array $dots): array
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

    private function conn()
    {
        return DB::connection((new Carrier)->getConnectionName());
    }
}
