<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Carrier\Concerns\DtTrustScoreSupport;
use App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3;
use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Models\CarrierShortlist;
use App\Support\Fmcsa;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The shortlist card needs the same real signals the advanced search page
 * shows - active authority, insurance, a DT score - and used to fake three
 * of them (see git history: the frontend hardcoded active_authority,
 * authority_verified and insurance_current to constants, and never read
 * dt_score at all, because this endpoint never sent real ones).
 *
 * The scoring machinery below (eager-load shape, cache-first
 * carrier_dt_score:{dot}, the batched fraud-network pre-warm, the v3.3
 * affiliate-stem exclusion) is a deliberate duplicate of
 * AdvancedCarrierSearchController's, not a shared trait — see that
 * decision recorded there. A shortlist page is small and column-limited
 * enough that duplicating ~400 lines of proven code was judged lower risk
 * than refactoring a search flow that was just hardened and is not to be
 * disturbed. Keep the two in step by hand if the scoring rules change.
 */
class CarrierShortlistController extends Controller
{
    use DtTrustScoreV3, DtTrustScoreSupport;

    private const CONN = 'external_db';

    /** Same TTL as the search page and the carrier profile - one stored
     * score per DOT, not three call sites that can disagree. */
    private const SEARCH_SCORE_TTL_HOURS = 6;

    /** Statement budget while eager-loading / running the batched stats
     * queries. Restored after scoring so nothing downstream inherits it. */
    private const QUERY_TIMEOUT_MS = 15000;

    private const SCORE_TIMEOUT_MS = 8000;

    /**
     * The carrier's own columns, exactly as the pre-enrichment response
     * already sent them - explicit on purpose, not $carrier->toArray().
     * toArray() on a model with the relations below eager-loaded onto it
     * would dump every crash, inspection and insurance-filing row into the
     * response too; a shortlist card needs none of that.
     */
    private const CARRIER_COLUMNS = [
        'id', 'row_id', 'dot_number', 'legal_name', 'dba_name', 'carrier_operation', 'hm_flag',
        'phy_street', 'phy_city', 'phy_state', 'phy_zip', 'phy_zip5', 'phy_country',
        'mailing_street', 'mailing_city', 'mailing_state', 'mailing_zip', 'mailing_country',
        'telephone', 'fax', 'email_address',
        'mcs150_date', 'mcs150_mileage', 'mcs150_mileage_year', 'add_date',
        'nbr_power_unit', 'driver_total', 'created_at', 'updated_at',
    ];

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
     * The shortlist belongs to the company, so everyone on the team sees the
     * same carriers regardless of who added them.
     *
     * Paginated - a company's shortlist runs to the thousands in practice,
     * and every row on the page gets a live DT score computed if it isn't
     * already cached, which is too expensive to run over an unbounded list.
     * Only the page actually being shown ever gets scored, same principle
     * as the advanced search page.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $paginator = CarrierShortlist::where('company_id', $request->user()->company_id)
            ->with('user:id,first_name,last_name')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        $carrierIds = $paginator->getCollection()
            ->pluck('carrier_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $enriched = $this->enrichedCarriers($carrierIds);

        $carriers = $paginator->getCollection()->map(function (CarrierShortlist $entry) use ($enriched) {
            $meta = [
                'shortlisted_by' => $entry->user
                    ? trim($entry->user->first_name.' '.$entry->user->last_name)
                    : null,
                'shortlisted_at' => $entry->created_at?->format('m/d/y'),
            ];

            $carrier = $entry->carrier_id ? ($enriched[$entry->carrier_id] ?? null) : null;

            // The carrier row itself no longer resolves in the feed (rare,
            // but a shortlist entry can outlive one) - the shortlist row
            // still shows, just without carrier detail, rather than the
            // whole page silently losing an entry.
            if ($carrier === null) {
                return $meta + ['id' => $entry->carrier_id];
            }

            return $carrier + $meta;
        })->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Shortlisted carriers retrieved.',
            'data' => $carriers,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'has_more_pages' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * Full carrier detail for one page of the shortlist, keyed by carrier
     * id - live DT score, real active-authority status, insurance, and
     * everything else the card needs, computed for at most one page's worth
     * of carriers at a time.
     *
     * Never throws: a failure anywhere here means the shortlist page shows
     * carriers with blank enrichment fields rather than a 500. The base
     * identity fields (name, address, phone) are the one thing this
     * endpoint must not fail to show — everything added here is a bonus on
     * top of that.
     *
     * @param  array<int, int>  $carrierIds
     * @return array<int, array>
     */
    private function enrichedCarriers(array $carrierIds): array
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

                    // Column-limited for the same reason as the search
                    // page's identical eager load - see that file.
                    'authorityHistory:dot_number,op_auth_type,original_action_desc,orig_served_date,disp_action_desc',
                    'crashes:dot_number,report_date,fatalities,injuries,tow_away',
                    'inspections:dot_number,insp_date,vin',

                    // Column-limited for the same reason - the underlying
                    // table (sms_input_violation) is 6.7M+ rows, busiest
                    // carriers 10,000-14,000+ violations each. Needed for
                    // OPS-12 (v3.5): false/misleading federal filing
                    // citations, unguarded on $carrier->violationDetails.
                    'violationDetails:dot_number,viol_code',
                ])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[CarrierShortlist] carrier load failed: '.$e->getMessage());

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
            Log::warning('[CarrierShortlist] stats/prewarm failed: '.$e->getMessage());
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
     * One carrier's card fields: identity columns plus authority,
     * insurance, and a live-or-cached DT score.
     */
    private function enrichOne(Carrier $carrier, array $inspectionStats, array $crashStats): array
    {
        $base = array_intersect_key($carrier->toArray(), array_flip(self::CARRIER_COLUMNS));

        $auth = $carrier->authority;

        // Same definition CarrierController's own profile page uses for
        // both these fields — a carrier with any live authority type reads
        // as both "active" and "verified" together, on purpose.
        $authorityActive = Fmcsa::isActive($auth?->common_stat)
            || Fmcsa::isActive($auth?->contract_stat)
            || Fmcsa::isActive($auth?->broker_stat);

        $insuranceCurrent = false;

        try {
            $insuranceCurrent = $this->hasInsuranceFiling($carrier, 'bipd');
        } catch (\Throwable $e) {
            Log::warning("[CarrierShortlist] insurance check failed for DOT {$carrier->dot_number}: ".$e->getMessage());
        }

        $score = null;
        $status = null;

        try {
            [$score, $status] = $this->scoreCarrier($carrier, $inspectionStats, $crashStats);
        } catch (\Throwable $e) {
            Log::warning("[CarrierShortlist] DT scoring failed for DOT {$carrier->dot_number}: ".$e->getMessage());
        }

        return $base + [
            'mc_number' => $auth?->docket_number,
            'duns' => $carrier->carrierDetail?->dun_bradstreet_no,
            'safety_rating' => Fmcsa::safetyRating($carrier->carrierDetail?->safety_rating),
            'active_authority' => $authorityActive,
            'authority_verified' => $authorityActive,
            'insurance_current' => $insuranceCurrent,
            'dt_score' => $score,
            'risk_level' => $this->riskLevelFor($status),
        ];
    }

    /**
     * The DT score for one carrier - cached first under the exact key the
     * search page and the carrier profile both use, so a shortlist card,
     * a search result and the profile page can never show three different
     * numbers for the same DOT. A cache miss runs the live v3 engine and
     * writes the same key, same TTL, for whichever of the three asks next.
     *
     * @return array{0: ?int, 1: ?string} [score, legacy status string]
     */
    private function scoreCarrier(Carrier $carrier, array $inspectionStats, array $crashStats): array
    {
        $dot = (string) $carrier->dot_number;

        $cached = Cache::get('carrier_dt_score:'.$dot);

        if ($cached !== null) {
            // The cache only ever stores the bare score (see
            // AdvancedCarrierSearchController) - status isn't recoverable
            // from it, so a cache hit gives no risk_level. The gauge still
            // shows the right number; the risk pill goes blank rather than
            // stale or guessed.
            return [(int) $cached, null];
        }

        $this->setStatementTimeout(self::SCORE_TIMEOUT_MS);

        try {
            $detail = $carrier->carrierDetail;
            $sms = $carrier->smsMeasures;
            $auth = $carrier->authority;

            $vehicleInspTotal = (int) ($sms?->vehicle_insp_total ?? 0);
            $vehicleOosTotal = (int) ($sms?->vehicle_oos_insp_total ?? 0);
            $driverInspTotal = (int) ($sms?->driver_insp_total ?? 0);
            $driverOosTotal = (int) ($sms?->driver_oos_insp_total ?? 0);

            $vehicleOosPct = $vehicleInspTotal > 0 ? round($vehicleOosTotal / $vehicleInspTotal * 100, 2) : null;
            $driverOosPct = $driverInspTotal > 0 ? round($driverOosTotal / $driverInspTotal * 100, 2) : null;

            $observedUnits = (int) ($inspectionStats['observed_units'] ?? 0);
            $observedTrailers = (int) ($inspectionStats['observed_trailers'] ?? 0);

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
        } finally {
            $this->setStatementTimeout(self::QUERY_TIMEOUT_MS);
        }

        if (! isset($trustScore['overall_score'])) {
            return [null, null];
        }

        $score = (int) $trustScore['overall_score'];

        Cache::put('carrier_dt_score:'.$dot, $score, now()->addHours(self::SEARCH_SCORE_TTL_HOURS));

        return [$score, $trustScore['status'] ?? null];
    }

    /**
     * The engine's own vocabulary (Approved / Review / High Risk /
     * Rejected), relabelled for the risk pill the card shows. No new
     * thresholds invented here - this reads the same status string
     * dtCalculateTrustScore() already decided, just renamed for the UI.
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
     * Years since a DOT number was issued. Identical to the copy on
     * AdvancedCarrierSearchController and CarrierController - see either
     * for why the FMCSA date format needs the special-cased branch.
     */
    private function dotAgeFrom($addDate): ?int
    {
        if (! $addDate) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($addDate))) {
                $year = substr($addDate, -2);
                $century = $year > date('y') ? '19' : '20';
                $parsedDate = Carbon::createFromFormat('d-M-Y', strtoupper(substr($addDate, 0, -2).$century.$year));
            } else {
                $parsedDate = Carbon::parse($addDate);
            }

            return (int) $parsedDate->diffInYears(now());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * observed_units / observed_trailers for a page of carriers, keyed by
     * DOT number. Identical to AdvancedCarrierSearchController's copy.
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

        $power = [
            'TRUCK TRACTOR', 'STRAIGHT TRUCK', 'BUS', 'SCHOOL BUS',
            'MOTOR COACH', 'PASSENGER VAN', 'LIMOUSINE',
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
     * Crash totals for a page of carriers, keyed by DOT number. Identical
     * to AdvancedCarrierSearchController's copy.
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
     | Fraud-network pre-warm — identical to AdvancedCarrierSearchController.
     |
     | Duplicated deliberately, not shared - see this file's class docblock.
     | Cache key, stem-exclusion logic and self-subtraction rules must stay
     | byte-for-byte the same as the search page's copy, or the two pages
     | could disagree about a carrier's own network-graph signal.
     ====================================================================== */

    private function dtPrewarmNetworkCounts($carriers): void
    {
        if (! config('trustscore.network_checks', true)) {
            return;
        }

        $pending = [];

        foreach ($carriers as $carrier) {
            $dot = (string) $carrier->dot_number;

            if ($dot === '') {
                continue;
            }

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
                Cache::put('dt:trust:net:v2:'.$dot, [
                    'phone' => $phones[$dot] ?? null,
                    'email' => $emails[$dot] ?? null,
                    'address' => $addresses[$dot] ?? null,
                    'vin' => $vins[$dot] ?? null,
                ], 21600);
            }
        } catch (\Throwable $e) {
            Log::warning('[CarrierShortlist] network prewarm failed, engine will fall back to per-carrier: '.$e->getMessage());
        }
    }

    private function dtSharedFieldCounts(array $pending, string $column): array
    {
        if (! in_array($column, ['telephone', 'email_address'], true)) {
            return [];
        }

        $buckets = [];
        $bucketMeta = [];

        foreach ($pending as $carrier) {
            if (empty($carrier->{$column})) {
                continue;
            }

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

    private function dtSharedAddressCounts(array $pending): array
    {
        $usable = fn ($c) => ! empty($c->phy_street) && strlen(trim((string) $c->phy_street)) > 5;

        $addressKey = fn ($c) => implode("\0", [(string) $c->phy_state, (string) $c->phy_city, (string) $c->phy_street]);

        $buckets = [];
        $bucketMeta = [];

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

    private function dtSharedVinCounts(array $pending): array
    {
        $dots = array_map('strval', array_keys($pending));

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

            if (empty($perCarrier[$dot])) {
                continue;
            }

            $stem = $this->dtSearchNameStem($carrier->legal_name);
            $others = [];

            foreach ($perCarrier[$dot] as $vin) {
                foreach (array_keys($owners[$vin] ?? []) as $owner) {
                    $owner = (string) $owner;

                    if ($owner === $dot) {
                        continue;
                    }

                    if ($stem !== null) {
                        $ownerName = strtoupper((string) ($ownerNames[$owner] ?? ''));

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
     * Affiliate name stem — identical to AdvancedCarrierSearchController's
     * copy, which is itself identical to the engine's own dtNameStem(). See
     * either for why this needs its own name rather than overriding the
     * trait's private method of the same job.
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

    public function store(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Only the key is needed here, and resolving it is cached — the
        // shortlist itself is local, so the remote carrier lookup was the whole
        // of the delay on this endpoint.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.',
            ], 404);
        }

        CarrierShortlist::updateOrCreate(
            [
                'company_id' => $request->user()->company_id,
                'carrier_id' => $carrierId,
            ],
            [
                'user_id' => $request->user()->id,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier added to shortlist successfully.',
        ]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Only the key is needed here, and resolving it is cached — the
        // shortlist itself is local, so the remote carrier lookup was the whole
        // of the delay on this endpoint.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.',
            ], 404);
        }

        CarrierShortlist::where('company_id', $request->user()->company_id)
            ->where('carrier_id', $carrierId)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier removed from shortlist.',
        ]);
    }
}
