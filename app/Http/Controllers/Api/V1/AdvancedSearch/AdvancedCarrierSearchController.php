<?php

namespace App\Http\Controllers\Api\V1\AdvancedSearch;

use App\Http\Controllers\Carrier\Concerns\DtTrustScoreSupport;
use App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3;
use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Support\Fmcsa;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced Filter Search for Carriers, Brokers and Shippers.
 *
 * FIX HISTORY (read this before "optimizing" anything below)
 * ----------------------------------------------------------------------
 * 1. Query the base table (company_census_file, aliased `carriers`)
 *    directly instead of a `carriers` view + whereExists() back into
 *    company_census_file for cargo/safety. That whereExists() ran once
 *    PER CANDIDATE ROW against a 1.5M-row table - fine at 50 candidates
 *    (25mi radius), catastrophic at 3,500+ (250mi radius).
 * 2. Zip-radius temp table uses InnoDB (not MEMORY) so its miles index is
 *    a real BTREE, not a HASH that can't support ORDER BY / range scans.
 * 3. FORCE INDEX (idx_miles) on the temp table + FORCE INDEX
 *    (idx_census_phy_zip5) on company_census_file, both inside a
 *    STRAIGHT_JOIN, so MySQL can't decide to full-scan either side and the
 *    output already comes out sorted by distance (no filesort needed).
 * 4. Several places used SELECT aliases (hm_flag, nbr_power_unit, row_id,
 *    id) in WHERE/ORDER BY clauses. Aliases only work in SELECT - fixed to
 *    use the real column names (hm_ind, power_units, dot_number) for
 *    filtering/sorting.
 * 5. carrier_all_with_history and actpendinsur_all_with_history both store
 *    dot_number as inconsistently zero-padded TEXT (e.g. "04516637" vs
 *    "4516637" - even the padding length varies row to row). Both tables
 *    also have a clean, indexed dot_int column - all matching against
 *    those tables (filtering AND the active_authority/insurance_current
 *    display badges) now uses dot_int instead.
 * 6. Authority and insurance checks were correlated whereExists()
 *    subqueries - re-run once per candidate carrier. Converted to
 *    independent whereIn(subquery): "which DOT numbers qualify" can be
 *    computed ONCE and reused, instead of once per candidate row.
 *
 * Comprehensive logging (see the timed() helper and the Log::info calls
 * throughout) - every meaningful stage logs how long it took, so a slow
 * request's cause shows up directly in storage/logs/laravel.log instead of
 * requiring more guesswork.
 */
class AdvancedCarrierSearchController extends Controller
{
    /*
    | DT Score comes from the same engine the carrier profile page runs, not
    | a copy of it. DtTrustScoreV3 is the live scoring engine (CarrierController
    | installs the identical trait); DtTrustScoreSupport carries the eight
    | small helpers the engine expects to find on whatever class hosts it.
    |
    | This matters more than it looks: the score on a search card and the score
    | on that same carrier's profile have to agree, and the only way to
    | guarantee that permanently is for both to execute the same code. A
    | duplicated implementation would agree on the day it shipped and drift
    | from then on.
    */
    use DtTrustScoreV3, DtTrustScoreSupport;

    private const CONN = 'external_db';

    /**
     * How long a computed search score stays usable.
     *
     * Six hours, matching CarrierController — and deliberately the same cache
     * key (`carrier_dt_score:{dot}`), so a score computed by this search and
     * one computed by opening the profile are the same stored value rather
     * than two numbers that can disagree.
     */
    private const SEARCH_SCORE_TTL_HOURS = 6;

    /**
     * Per-statement budget for the scoring queries specifically.
     *
     * Deliberately well under QUERY_TIMEOUT_MS: a page of results that comes
     * back without scores is a far better outcome than one that arrives late
     * because an enrichment step went looking for them.
     */
    private const SCORE_TIMEOUT_MS = 8000;
    private const USE_FULLTEXT_NAME_SEARCH = false;
    private const QUERY_TIMEOUT_MS = 25000;
    private const EXPORT_CHUNK = 2000;
    private const MAX_RADIUS_ZIPS = 40000;
    private const COUNT_CAP = 10000;
    private const COUNT_CACHE_TTL = 300;
    private const TMP_ZIP_TABLE = 'tmp_zip_radius';
    private const CENSUS_TABLE = 'company_census_file';
    private const ZIP_INDEX = 'idx_census_phy_zip5';

    private const FLEET_BRACKETS = [
        'A' => [1, 1],       'B' => [2, 3],       'C' => [4, 6],       'D' => [7, 8],
        'E' => [9, 11],      'F' => [12, 14],     'G' => [15, 17],     'H' => [18, 19],
        'I' => [20, 23],     'J' => [24, 28],     'K' => [29, 32],     'L' => [33, 38],
        'M' => [39, 44],     'N' => [45, 55],     'O' => [56, 75],     'P' => [76, 100],
        'Q' => [101, 200],   'R' => [201, 300],   'S' => [301, 400],   'T' => [401, 550],
        'U' => [551, 999],   'V' => [1000, 2000], 'W' => [2001, 3000], 'X' => [3001, 4000],
        'Y' => [4001, 5000], 'Z' => [5001, 999999],
    ];

    private const CARGO_MAP = [
        'agricultural/farm supplies' => 'crgo_farmsupp',   'beverages'                 => 'crgo_beverages',
        'building materials'         => 'crgo_bldgmat',    'chemicals'                 => 'crgo_chem',
        'coal/coke'                  => 'crgo_coalcoke',   'commodities dry bulk'      => 'crgo_drybulk',
        'construction'               => 'crgo_construct',  'drive/tow away'            => 'crgo_drivetow',
        'fresh produce'              => 'crgo_produce',    'garbage/refuse'            => 'crgo_garbage',
        'general freight'            => 'crgo_genfreight', 'grain/feed/hay'            => 'crgo_grainfeed',
        'household goods'            => 'crgo_household',  'intermodal containers'     => 'crgo_intermodal',
        'liquids/gases'              => 'crgo_liqgas',     'livestock'                 => 'crgo_livestock',
        'logs/poles/beams/lumber'    => 'crgo_logpole',    'machinery/large objects'   => 'crgo_machlrg',
        'meat'                       => 'crgo_meat',       'metal: sheets/coils/rolls' => 'crgo_metalsheet',
        'mobile homes'               => 'crgo_mobilehome', 'motor vehicles'            => 'crgo_motoveh',
        'other'                      => 'crgo_cargoothr',  'oilfield equipment'        => 'crgo_oilfield',
        'paper products'             => 'crgo_paperprod',  'passengers'                => 'crgo_passengers',
        'refrigerated food'          => 'crgo_coldfood',   'water well'                => 'crgo_waterwell',
        'u.s. mail'                  => 'crgo_usmail',     'utilities'                 => 'crgo_utility',
        'dry van'                    => 'crgo_genfreight', 'reefer'                    => 'crgo_coldfood',
        'flatbed'                    => 'crgo_bldgmat',    'tanker'                    => 'crgo_liqgas',
        'auto carrier'               => 'crgo_motoveh',    'container'                 => 'crgo_intermodal',
        'dump trailer'               => 'crgo_drybulk',
    ];

    private array $zipDistances = [];
    private bool $isRadiusSearch = false;

    public function filter(Request $request)
    {
        $request->validate([
            'query'           => 'nullable|string|max:120',
            'entity_type'     => 'nullable|string|max:32',
            'location'        => 'nullable|string|max:100',
            'radius_miles'    => 'nullable|numeric|min:1|max:1000',
            'authority'       => 'nullable|array|max:5',
            'authority.*'     => 'string|max:32',
            'min_months'      => 'nullable|integer|min:0|max:1200',
            'max_months'      => 'nullable|integer|min:0|max:1200',
            'operations'      => 'nullable|array|max:5',
            'operations.*'    => 'string|max:32',
            'min_fleet'       => 'nullable|integer|min:0|max:999999',
            'max_fleet'       => 'nullable|integer|min:0|max:999999',
            'fleet_bracket'   => 'nullable|string|size:1',
            'min_bipd'        => 'nullable|numeric|min:0',
            'safety_rating'   => 'nullable|array|max:5',
            'safety_rating.*' => 'string|max:32',
            'cargo'           => 'nullable|array|max:40',
            'cargo.*'         => 'string|max:64',
            'per_page'        => 'nullable|integer|min:1|max:100',
            'page'            => 'nullable|integer|min:1',
            'export_csv'      => 'nullable|boolean',
        ]);

        Log::info('[CarrierSearch] Incoming request: ' . json_encode($request->all()));

        $isExport = $request->boolean('export_csv');
        $this->setStatementTimeout($isExport ? 0 : self::QUERY_TIMEOUT_MS);

        try {
            $query = $this->buildQuery($request);
        } catch (EmptyResultException $e) {
            return $isExport
                ? $this->emptyCsv()
                : response()->json($this->emptyPayload($request));
        }

        return $isExport
            ? $this->streamCsv($request, $query)
            : $this->respondPaginated($request, $query);
    }

    private function conn()
    {
        return DB::connection(self::CONN);
    }

    /**
     * Runs $fn(), logs how long it took, and returns whatever $fn()
     * returned. On failure, logs how long it ran before failing and
     * re-throws. Wrap any DB call here so a slow/timed-out request shows
     * exactly which stage was the culprit in the logs.
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
            Log::warning("[CarrierSearch] {$label} FAILED after {$ms}ms: " . $e->getMessage());
            throw $e;
        }
    }

    private function buildQuery(Request $request): Builder
    {
        $query = $this->conn()
            ->table(self::CENSUS_TABLE . ' as carriers')
            ->select($this->carrierSelectColumns());

        $this->applyTextSearch($query, $request);
        $this->applyEntityType($query, $request);
        $this->applyLocation($query, $request);
        $this->applyAuthority($query, $request);
        $this->applyAuthorityAge($query, $request);
        $this->applyOperations($query, $request);
        $this->applyFleet($query, $request);
        $this->applyInsurance($query, $request);
        $this->applyCensusFilters($query, $request);

        Log::info('[CarrierSearch] Final SQL: ' . $query->toSql() . ' | Bindings: ' . json_encode($query->getBindings()));

        return $query;
    }

    /**
     * Columns pulled straight from company_census_file, aliased to the
     * names the rest of the app already expects (id, telephone,
     * nbr_power_unit, hm_flag, etc).
     *
     * IMPORTANT: these are DISPLAY aliases only, valid in SELECT / when
     * reading a fetched row in PHP. Anywhere we FILTER or SORT (WHERE /
     * ORDER BY) we must use the real column name on the left of each
     * "as" below - MySQL doesn't let you use a SELECT alias in WHERE.
     */
    private function carrierSelectColumns(): array
    {
        return [
            'carriers.dot_number as id',
            DB::raw('CAST(carriers.dot_number AS CHAR) as row_id'),
            'carriers.dot_number',
            'carriers.legal_name',
            'carriers.dba_name',
            'carriers.phy_street',
            'carriers.phy_city',
            'carriers.phy_state',
            'carriers.phy_zip',
            'carriers.phone as telephone',
            'carriers.email_address',
            'carriers.power_units as nbr_power_unit',
            'carriers.mcs150_mileage',
            'carriers.carrier_operation',
            'carriers.hm_ind as hm_flag',
            DB::raw("STR_TO_DATE(NULLIF(carriers.add_date, 0), '%Y%m%d') as add_date"),
        ];
    }

    private function applyTextSearch(Builder $query, Request $request): void
    {
        if (!$request->filled('query')) {
            return;
        }

        $term = trim($request->input('query'));

        if (preg_match('/^\d{2,9}$/', $term)) {
            $query->where('carriers.dot_number', $term);
            return;
        }

        if (self::USE_FULLTEXT_NAME_SEARCH) {
            $boolean = collect(preg_split('/\s+/', $term))
                ->filter(fn ($w) => mb_strlen($w) >= 2)
                ->map(fn ($w) => '+' . preg_replace('/[+\-><()~*"@]+/', '', $w) . '*')
                ->implode(' ');

            if ($boolean !== '') {
                $query->whereRaw(
                    'MATCH(carriers.legal_name, carriers.dba_name) AGAINST (? IN BOOLEAN MODE)',
                    [$boolean]
                );
                return;
            }
        }

        $escaped = addcslashes($term, '%_\\');
        $pattern = $request->boolean('loose_match') ? "%{$escaped}%" : "{$escaped}%";

        $query->where(function (Builder $sub) use ($pattern) {
            $sub->where('carriers.legal_name', 'LIKE', $pattern)
                ->orWhere('carriers.dba_name', 'LIKE', $pattern);
        });
    }

    private function applyEntityType(Builder $query, Request $request): void
    {
        if (!$request->filled('entity_type')) {
            return;
        }

        switch (strtolower(trim($request->input('entity_type')))) {
            case 'brokers':
                $query->where('carriers.carrier_operation', 'B');
                break;
            case 'carriers':
                $query->whereIn('carriers.carrier_operation', ['A', 'C']);
                break;
            case 'both':
            case 'all companies':
                $query->whereIn('carriers.carrier_operation', ['A', 'B', 'C']);
                break;
        }
    }

    private function applyLocation(Builder $query, Request $request): void
    {
        if (!$request->filled('location')) {
            return;
        }

        $loc    = trim($request->input('location'));
        $radius = $request->filled('radius_miles') ? (float) $request->input('radius_miles') : null;

        if ($radius && $radius > 0) {
            $origin = $this->resolveOrigin($loc);

            if ($origin) {
                $zips = $this->zipsWithinRadius($origin['lat'], $origin['lng'], $radius);

                if (empty($zips)) {
                    throw new EmptyResultException();
                }

                $this->zipDistances  = $zips;
                $this->isRadiusSearch = true;

                $zipColumn = $this->zipJoinColumn();

                if ($this->createZipTempTable($zips)) {
                    // Drive the query from the small radius temp table, then
                    // hop over to company_census_file through its indexed
                    // phy_zip5 column. Cost now scales with the number of
                    // zip codes in the radius, not with the 1.5M rows in
                    // company_census_file.
                    $query->fromRaw(
                        self::TMP_ZIP_TABLE . ' as zr FORCE INDEX (idx_miles) ' . $this->radiusJoinClause($zipColumn)
                    );
                    $query->where('zr.miles', '>=', 0); // keeps the optimizer anchored on zr's BTREE index
                    $query->addSelect('zr.miles as distance_in_miles');
                    $query->orderBy('zr.miles', 'asc');
                } else {
                    $query->whereIn($zipColumn, array_keys($zips));
                }

                return;
            }
        }

        $upper = strtoupper($loc);

        // Anything that isn't a 2-letter state, a 5-digit zip, or "city,
        // state" falls back to a "city starts with X" search below. If that
        // leftover text is only 1-2 characters, it isn't narrowing anything
        // down - "a" matches Atlanta, Austin, Albany, Arlington... a huge
        // slice of the whole table, with none of the fast machinery above.
        // Treat an unrecognized, too-short location as "not a real place"
        // instead of silently running a near-unbounded scan.
        $isState     = strlen($loc) === 2;
        $isZip       = (bool) preg_match('/^\d{5}/', $loc);
        $isCityState = str_contains($loc, ',');

        if (!$isState && !$isZip && !$isCityState && strlen($loc) < 3) {
            throw new EmptyResultException();
        }

        $query->where(function (Builder $sub) use ($loc, $upper, $isState, $isZip, $isCityState) {
            if ($isState) {
                $sub->where('carriers.phy_state', $upper);
                return;
            }
            if ($isZip) {
                $sub->where('carriers.phy_zip', 'LIKE', substr($loc, 0, 5) . '%');
                return;
            }
            if ($isCityState) {
                [$city, $state] = array_pad(explode(',', $loc, 2), 2, '');
                $sub->where('carriers.phy_city', 'LIKE', addcslashes(trim($city), '%_\\') . '%')
                    ->where('carriers.phy_state', strtoupper(trim($state)));
                return;
            }
            $sub->where('carriers.phy_city', 'LIKE', addcslashes($loc, '%_\\') . '%');
        });
    }

    private function zipJoinColumn(): string
    {
        $hasZip5 = Cache::remember('census.has_phy_zip5', 86400, function () {
            try {
                return Schema::connection(self::CONN)->hasColumn(self::CENSUS_TABLE, 'phy_zip5');
            } catch (\Throwable $e) {
                return false;
            }
        });

        return $hasZip5 ? 'carriers.phy_zip5' : 'carriers.phy_zip';
    }

    /**
     * Builds the STRAIGHT_JOIN clause that hops from the zip radius temp
     * table over to company_census_file. Adds a FORCE INDEX hint whenever
     * we know the fast phy_zip5 index is available, so the optimizer can't
     * decide to scan company_census_file instead on a big radius.
     */
    private function radiusJoinClause(string $zipColumn): string
    {
        $hint = $zipColumn === 'carriers.phy_zip5'
            ? ' FORCE INDEX (' . self::ZIP_INDEX . ')'
            : '';

        return 'STRAIGHT_JOIN ' . self::CENSUS_TABLE . ' as carriers' . $hint . ' ON ' . $zipColumn . ' = zr.zip5';
    }

    private function resolveOrigin(string $loc): ?array
    {
        $key = 'geo:origin:' . md5(strtolower($loc));

        $origin = Cache::remember($key, 86400, function () use ($loc) {
            $table = fn () => $this->conn()->table('zip_centroids');

            if (preg_match('/^(\d{5})/', $loc, $m)) {
                $row = $table()->where('zip', $m[1])->first(['lat', 'lng']);
            } elseif (strlen($loc) === 2) {
                $row = $table()->where('state', strtoupper($loc))
                    ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')->first();
            } elseif (str_contains($loc, ',')) {
                [$city, $state] = array_pad(explode(',', $loc, 2), 2, '');
                $row = $table()->where('state', strtoupper(trim($state)))
                    ->where('city', trim($city))
                    ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')->first();
            } else {
                $row = $table()->where('city', $loc)
                    ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')->first();
            }

            if (!$row || $row->lat === null || $row->lng === null) {
                return ['lat' => null, 'lng' => null];
            }

            return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
        });

        return $origin['lat'] === null ? null : $origin;
    }

    private function zipsWithinRadius(float $lat, float $lng, float $radius): array
    {
        $key = sprintf('geo:radius:%.4f:%.4f:%.1f', $lat, $lng, $radius);

        return Cache::remember($key, 86400, function () use ($lat, $lng, $radius) {
            $latFudge = $radius / 69.0;
            $lngFudge = $radius / max(0.0001, 69.0 * cos(deg2rad($lat)));

            $haversine = '(3958.7613 * 2 * ASIN(SQRT(
                POWER(SIN(RADIANS(lat - ?) / 2), 2) +
                COS(RADIANS(?)) * COS(RADIANS(lat)) *
                POWER(SIN(RADIANS(lng - ?) / 2), 2)
            )))';

            $rows = $this->timed('zip radius lookup', function () use ($haversine, $lat, $lng, $latFudge, $lngFudge, $radius) {
                return $this->conn()->table('zip_centroids')
                    ->select('zip')
                    ->selectRaw("{$haversine} AS miles", [$lat, $lat, $lng])
                    ->whereBetween('lat', [$lat - $latFudge, $lat + $latFudge])
                    ->whereBetween('lng', [$lng - $lngFudge, $lng + $lngFudge])
                    ->havingRaw('miles <= ?', [$radius])
                    ->orderBy('miles')
                    ->limit(self::MAX_RADIUS_ZIPS)
                    ->get();
            });

            $out = [];
            foreach ($rows as $row) {
                $zip = str_pad((string) $row->zip, 5, '0', STR_PAD_LEFT);
                $out[$zip] = round((float) $row->miles, 2);
            }

            Log::info('[CarrierSearch] zip radius produced ' . count($out) . " zip codes for radius={$radius}mi");

            return $out;
        });
    }

    private function createZipTempTable(array $zips): bool
    {
        try {
            $this->timed('build zip temp table (' . count($zips) . ' zips)', function () use ($zips) {
                $this->conn()->statement('DROP TEMPORARY TABLE IF EXISTS ' . self::TMP_ZIP_TABLE);

                // InnoDB, not MEMORY: on a MEMORY table, a plain KEY defaults to
                // a HASH index, which cannot be used for ORDER BY / range scans -
                // MySQL falls back to sorting the whole temp table by hand.
                // InnoDB gives idx_miles a real BTREE structure.
                $this->conn()->statement(
                    'CREATE TEMPORARY TABLE ' . self::TMP_ZIP_TABLE . ' (
                        zip5  VARCHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
                        miles DECIMAL(7,2) NOT NULL,
                        PRIMARY KEY (zip5),
                        KEY idx_miles (miles)
                    ) ENGINE=InnoDB'
                );

                foreach (array_chunk($zips, 2000, true) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $zip => $miles) {
                        $rows[] = ['zip5' => $zip, 'miles' => $miles];
                    }
                    $this->conn()->table(self::TMP_ZIP_TABLE)->insert($rows);
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('[CarrierSearch] temp zip table unavailable, using IN() fallback: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Operating authority — and the reason every result is an active carrier.
     *
     * Two jobs in one subquery, on purpose. If the broker picked specific
     * authority types, those columns are checked for 'A'. If they picked
     * none, all three are checked for 'A' instead — so a search with no
     * authority filter still only returns carriers that actually hold live
     * operating authority.
     *
     * Folding the always-on check into this same subquery rather than adding
     * a second one is the whole trick: a broker who filtered on "broker
     * authority" is already asking a strictly narrower question than "any
     * active authority", so stacking both would make MySQL evaluate two
     * overlapping DOT-number sets to reach an answer the narrower one had
     * already given. One subquery either way, and the cost of "active only"
     * on an otherwise unfiltered search is the same cost an authority-
     * filtered search has always paid.
     *
     * Reads carrier_authorities, NOT carrier_all_with_history. The latter is
     * what its name says - every authority row a carrier has ever had - so a
     * carrier granted common authority in 2018 and revoked in 2024 still has
     * a row sitting in it with common_stat = 'A'. Filtering on that table
     * asks "was this carrier ever active", and every revoked carrier in the
     * feed answers yes. carrier_authorities is the current snapshot, and its
     * newest row per DOT is the live status - the same row Carrier::authority()
     * pins with ofMany('id', 'max') and the same one the carrier profile
     * reports, so the search's idea of "active" and the profile's are now one
     * answer instead of two that disagree.
     */
    private function applyAuthority(Builder $query, Request $request): void
    {
        $authorities = $request->input('authority');
        $columns = [];

        if ($request->filled('authority') && is_array($authorities)) {
            foreach ($authorities as $auth) {
                $n = strtolower(trim($auth));
                if (str_contains($n, 'broker'))   $columns[] = 'broker_stat';
                if (str_contains($n, 'common'))   $columns[] = 'common_stat';
                if (str_contains($n, 'contract')) $columns[] = 'contract_stat';
            }
            $columns = array_unique($columns);
        }

        // No explicit selection (or nothing recognisable in it) falls back to
        // "holds any active authority" rather than to no filter at all.
        if (empty($columns)) {
            $columns = ['common_stat', 'contract_stat', 'broker_stat'];
        }

        // Converted from a correlated whereExists() to an independent
        // whereIn(subquery). This question - "which DOT numbers have this
        // authority active" - doesn't actually depend on each candidate
        // carrier row, so it can be answered ONCE. MySQL can materialize
        // that answer into a small indexed list and reuse it for every
        // candidate, instead of running a separate check per candidate row
        // (which is what a correlated EXISTS does, and what caused the
        // authority-filter timeouts even after removing the stray LIMIT 1).
        /*
        | The Motus load left duplicate rows in carrier_authorities, so
        | "newest row per DOT" is not optional - without it the OR below
        | matches a superseded row and lets a revoked carrier through, which
        | is the same bug reading the history table caused.
        |
        | Collapsed with a grouped MAX(id) joined back on the primary key
        | rather than a correlated `id = (SELECT MAX(id) ... WHERE dot_number
        | = outer.dot_number)`. The correlated form re-runs once per row of a
        | multi-million-row table; the grouped form is a single loose index
        | scan over the (dot_number, id) index, computed once and reused.
        |
        | DISTINCT is gone on purpose: the join already guarantees one row per
        | DOT, so keeping it would only buy a redundant temp-table dedupe.
        */
        $latestAuthority = $this->conn()
            ->table('carrier_authorities')
            ->selectRaw('MAX(id) AS id')
            ->groupBy('dot_number');

        $query->whereIn('carriers.dot_number', function ($sub) use ($columns, $latestAuthority) {
            $sub->select('auth_now.dot_number')
                ->from('carrier_authorities as auth_now')
                ->joinSub($latestAuthority, 'latest', 'latest.id', '=', 'auth_now.id')
                ->where(function ($s) use ($columns) {
                    foreach ($columns as $col) {
                        $s->orWhere("auth_now.{$col}", 'A');
                    }
                });
        });
    }

    private function applyAuthorityAge(Builder $query, Request $request): void
    {
        // carriers.add_date is stored as a plain YYYYMMDD integer (that's
        // why the SELECT above has to STR_TO_DATE it for display) - compare
        // against that same YYYYMMDD shape instead of a 'Y-m-d' string.
        // NOTE: double-check this assumption against your real schema.
        if ($request->filled('min_months')) {
            $cutoff = (int) Carbon::now()->subMonths((int) $request->input('min_months'))->format('Ymd');
            $query->where('carriers.add_date', '<=', $cutoff);
        }

        if ($request->filled('max_months')) {
            $cutoff = (int) Carbon::now()->subMonths((int) $request->input('max_months'))->format('Ymd');
            $query->where('carriers.add_date', '>=', $cutoff);
        }
    }

    private function applyOperations(Builder $query, Request $request): void
    {
        $ops = $request->input('operations');
        if (!$request->filled('operations') || !is_array($ops)) {
            return;
        }

        $ops   = array_map(fn ($op) => strtolower(trim($op)), $ops);
        $codes = [];

        if (in_array('interstate', $ops, true)) $codes[] = 'A';
        if (in_array('intrastate', $ops, true)) $codes[] = 'C';

        if (!empty($codes)) {
            $query->whereIn('carriers.carrier_operation', $codes);
        }

        if (in_array('hazmat', $ops, true)) {
            // hm_flag is only the SELECT alias for hm_ind - filter on the real column.
            $query->where('carriers.hm_ind', 1);
        }
    }

    private function applyFleet(Builder $query, Request $request): void
    {
        // nbr_power_unit is only the SELECT alias for power_units - filter on the real column.
        if ($request->filled('min_fleet')) {
            $query->where('carriers.power_units', '>=', (int) $request->input('min_fleet'));
        }

        if ($request->filled('max_fleet')) {
            $query->where('carriers.power_units', '<=', (int) $request->input('max_fleet'));
        }

        if ($request->filled('fleet_bracket')) {
            $bracket = strtoupper(trim($request->input('fleet_bracket')));
            if (isset(self::FLEET_BRACKETS[$bracket])) {
                $query->whereBetween('carriers.power_units', self::FLEET_BRACKETS[$bracket]);
            }
        }
    }

    private function applyInsurance(Builder $query, Request $request): void
    {
        if (!$request->filled('min_bipd')) {
            return;
        }

        $minBipd = (float) $request->input('min_bipd');

        // Same reasoning as applyAuthority() above: "which DOT numbers have
        // qualifying insurance" doesn't depend on each candidate row, so it
        // can be answered once instead of per-row.
        $query->whereIn('carriers.dot_number', function ($sub) use ($minBipd) {
            $sub->select('ins.dot_int')
                ->distinct()
                ->from('actpendinsur_all_with_history as ins')
                ->where('ins.max_cov_amount', '>=', $minBipd)
                ->where(function ($s) {
                    $s->where('ins.mod_col_1', 'LIKE', '%BIPD%')
                      ->orWhere('ins.ins_form_code', 'LIKE', '91%');
                });
        });
    }

    /**
     * Cargo capability and safety rating both live directly on
     * company_census_file, which is now our base table (aliased
     * `carriers`). So there's no subquery here at all anymore - we just
     * filter the row we already have.
     *
     * This IS the timeout fix: the old version ran this as a whereExists()
     * against company_census_file for every single candidate row, which is
     * the same as asking "does a row matching this exact row exist?" -
     * always true, and brutally slow once the candidate count grows.
     */
    private function applyCensusFilters(Builder $query, Request $request): void
    {
        $mappedCodes  = [];
        $includeNone  = false;
        $ratingMap    = ['satisfactory' => 'S', 'conditional' => 'C', 'unsatisfactory' => 'U'];
        $ratings      = $request->input('safety_rating');

        if ($request->filled('safety_rating') && is_array($ratings)) {
            foreach ($ratings as $r) {
                $clean = strtolower(trim($r));
                if (isset($ratingMap[$clean])) {
                    $mappedCodes[] = $ratingMap[$clean];
                } elseif ($clean === 'none') {
                    $includeNone = true;
                }
            }
        }

        $cargoColumns = [];
        $cargo        = $request->input('cargo');

        if ($request->filled('cargo') && is_array($cargo)) {
            foreach ($cargo as $item) {
                $clean = strtolower(trim($item));
                if (isset(self::CARGO_MAP[$clean])) {
                    $cargoColumns[] = self::CARGO_MAP[$clean];
                }
            }
            $cargoColumns = array_values(array_unique($cargoColumns));
        }

        $needsSafety = !empty($mappedCodes) || $includeNone;
        $needsCargo  = !empty($cargoColumns);

        if (!$needsSafety && !$needsCargo) {
            return;
        }

        if ($needsSafety) {
            $query->where(function (Builder $s) use ($mappedCodes, $includeNone) {
                if (!empty($mappedCodes)) {
                    $s->whereIn('carriers.safety_rating', $mappedCodes);
                }
                if ($includeNone) {
                    $s->orWhereNull('carriers.safety_rating')
                      ->orWhereIn('carriers.safety_rating', ['', 'N']);
                }
            });
        }

        if ($needsCargo) {
            $query->where(function (Builder $s) use ($cargoColumns) {
                foreach ($cargoColumns as $column) {
                    $s->orWhereIn("carriers.{$column}", ['X', 'Y', '1']);
                }
            });
        }
    }

    private function respondPaginated(Request $request, Builder $query)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 10)));
        $page    = max(1, (int) $request->input('page', LengthAwarePaginator::resolveCurrentPage()));

        $rowQuery = clone $query;
        if (!$this->isRadiusSearch) {
            // Radius searches are already sorted by zr.miles using an index
            // (see applyLocation + the FORCE INDEX (idx_miles) hint). Adding
            // a second sort key here would force MySQL to load and sort the
            // ENTIRE matching set by hand before it can hand back one page -
            // that's the actual cause of the 100/250-mile timeouts.
            $rowQuery->orderBy('carriers.dot_number', 'asc');
        }

        try {
            $rows = $this->timed('main paginated fetch', fn () => $rowQuery->forPage($page, $perPage)->get());
        } catch (\Throwable $e) {
            return $this->timeoutResponse($e);
        }

        $items = $this->hydrateRows($rows->all());

        /*
        | Live DT scores, for this page only.
        |
        | hydrateRows() fills dt_score from the local `carriers` mirror, which
        | nothing writes to - it has always come back null. The real number
        | comes from the same engine the carrier profile runs; anything the
        | engine could not answer keeps whatever hydrateRows() had, which is
        | still null.
        */
        $scores = $this->scoreCurrentPage(array_column($items, 'dot_number'));

        if (! empty($scores)) {
            foreach ($items as &$item) {
                $dot = $item['dot_number'] ?? null;

                if ($dot !== null && isset($scores[$dot])) {
                    $item['dt_score'] = $scores[$dot];
                }
            }
            unset($item);
        }

        if ($this->isRadiusSearch && empty($this->zipDistances) === false) {
            usort($items, fn ($a, $b) => ($a['distance_in_miles'] ?? PHP_INT_MAX) <=> ($b['distance_in_miles'] ?? PHP_INT_MAX));
        }

        [$total, $capped] = $this->boundedCount($query, $request, $page, $perPage, count($items));

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]);

        $payload = $paginator->toArray();
        $payload['count_capped'] = $capped;

        return response()->json($payload);
    }

    private function boundedCount(Builder $query, Request $request, int $page, int $perPage, int $returned): array
    {
        if ($returned < $perPage) {
            return [(($page - 1) * $perPage) + $returned, false];
        }

        $filters = $request->except(['page', 'per_page', 'export_csv']);
        ksort($filters);
        $cacheKey = 'carrier_search:count:' . md5(json_encode($filters));

        $total = Cache::remember($cacheKey, self::COUNT_CACHE_TTL, function () use ($query) {
            try {
                $inner = (clone $query)
                    ->reorder()
                    ->select(DB::raw('1'))
                    ->limit(self::COUNT_CAP + 1);

                return $this->timed('bounded count query', function () use ($inner) {
                    return (int) $this->conn()
                        ->table(DB::raw('(' . $inner->toSql() . ') as bounded'))
                        ->mergeBindings($inner)
                        ->count();
                });
            } catch (\Throwable $e) {
                Log::warning('[CarrierSearch] bounded count failed: ' . $e->getMessage());
                return self::COUNT_CAP + 1;
            }
        });

        return [min($total, self::COUNT_CAP), $total > self::COUNT_CAP];
    }

    private function hydrateRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $dots = array_values(array_unique(array_filter(array_map(
            fn ($r) => (string) (((array) $r)['dot_number'] ?? ''),
            $rows
        ))));
        $dots = array_values(array_filter($dots, fn ($d) => $d !== ''));

        $census    = collect();
        $authority = [];
        $insurance = [];
        $local     = collect();

        if (!empty($dots)) {
            $census = $this->conn()->table('company_census_file')
                ->whereIn('dot_number', $dots)
                ->select('dot_number', 'dun_bradstreet_no', 'docket1prefix', 'docket1', 'safety_rating')
                ->get()
                ->keyBy('dot_number');

            /*
            | Same source as applyAuthority() - see its docblock for why the
            | history table is the wrong one to ask. These two MUST agree:
            | the filter is always on, so every row on this page is one the
            | filter called active, and an `active_authority: false` next to
            | it would be the response contradicting itself.
            |
            | The correlated MAX(id) is fine here where it would not be in
            | the filter: this runs against one page of DOT numbers (100 at
            | most), not the whole table, so each lookup is an index seek on
            | a list the WHERE has already narrowed.
            */
            $authority = $this->conn()->table('carrier_authorities as auth_now')
                ->whereIn('auth_now.dot_number', $dots)
                ->whereRaw('auth_now.id = (SELECT MAX(dup.id) FROM carrier_authorities AS dup WHERE dup.dot_number = auth_now.dot_number)')
                ->where(function ($q) {
                    $q->where('auth_now.common_stat', 'A')
                      ->orWhere('auth_now.contract_stat', 'A')
                      ->orWhere('auth_now.broker_stat', 'A');
                })
                ->pluck('auth_now.dot_number')
                ->flip()
                ->all();

            // Same zero-padding issue as carrier_all_with_history above -
            // actpendinsur_all_with_history.dot_number is inconsistently
            // padded text, so match on the clean, indexed dot_int instead.
            $insurance = $this->conn()->table('actpendinsur_all_with_history')
                ->whereIn('dot_int', $dots)
                ->distinct()
                ->pluck('dot_int')
                ->flip()
                ->all();

            $local = DB::table('carriers')
                ->whereIn('dot_number', $dots)
                ->get()
                ->keyBy('dot_number');
        }

        $out = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $dot  = $data['dot_number'] ?? null;

            $c = $census->get($dot);
            $l = $local->get($dot);

            $address = trim(
                ($data['phy_street'] ?? '') . ', ' . ($data['phy_city'] ?? '') . ', ' .
                ($data['phy_state'] ?? '') . ' ' . ($data['phy_zip'] ?? ''),
                ', '
            );

            $builtMc = trim((string) ($c->docket1prefix ?? '') . (string) ($c->docket1 ?? ''));

            $item = [
                'carrier_id'        => $data['id'] ?? null,
                'company_name'      => $data['legal_name'] ?? 'UNKNOWN',
                'dba_name'          => $data['dba_name'] ?? null,
                'dot_number'        => $dot,
                'mc_number'         => $builtMc !== '' ? $builtMc : null,
                'duns'              => $c->dun_bradstreet_no ?? null,
                'fleet_size'        => $data['nbr_power_unit'] ?? 0,
                'mileage'           => $data['mcs150_mileage'] ?? 0,
                'safety_rating'     => $c->safety_rating ?? 'Not Rated',
                'active_authority'  => isset($authority[$dot]),
                'insurance_current' => isset($insurance[$dot]),
                'phone'             => $data['telephone'] ?? 'N/A',
                'email'             => $data['email_address'] ?? 'N/A',
                'address'           => $address !== '' ? $address : 'N/A',
                'phy_street'        => $data['phy_street'] ?? null,
                'phy_city'          => $data['phy_city'] ?? null,
                'phy_state'         => $data['phy_state'] ?? null,
                'phy_zip'           => $data['phy_zip'] ?? null,
                'carrier_operation' => $data['carrier_operation'] ?? null,
                'hm_flag'           => $data['hm_flag'] ?? null,
                'add_date'          => $data['add_date'] ?? null,
                'dt_score'          => $l->dt_score ?? null,
                'authority_verified' => $l->authority_verified ?? false,
                'risk_level'        => $l->risk_level ?? null,
            ];

            if (isset($data['distance_in_miles'])) {
                $item['distance_in_miles'] = round((float) $data['distance_in_miles'], 2);
            } elseif ($this->isRadiusSearch) {
                $zip5 = substr(preg_replace('/[^0-9]/', '', (string) ($data['phy_zip'] ?? '')), 0, 5);
                $item['distance_in_miles'] = $this->zipDistances[$zip5] ?? null;
            }

            $out[] = $item;
        }

        return $out;
    }

    /* ======================================================================
     | DT Score
     |
     | Scoring is expensive per carrier: the engine reads roughly a dozen
     | relations plus inspection and crash aggregates. That cost is bounded
     | here by only ever scoring the page actually being returned - at most
     | 100 rows, 10 by default - never the matched set, which can run to
     | hundreds of thousands.
     |
     | CSV export deliberately gets none of this. streamCsv() below is left
     | alone and its dt_score stays null: an export can be tens of thousands
     | of rows, and scoring each one would reintroduce exactly the per-row
     | cost this file spent so long removing.
     ====================================================================== */

    /**
     * DT scores for one page of carriers, keyed by DOT number.
     *
     * Same cache key as the carrier profile (`carrier_dt_score:{dot}`), so a
     * score computed here and one computed by opening that carrier's profile
     * are one stored value, not two that can disagree.
     *
     * Never throws. A search that returns results without scores is a far
     * better outcome than a search that 500s because one carrier had odd
     * data, so every failure path degrades: live score -> last score this
     * company saw -> null.
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, int>
     */
    private function scoreCurrentPage(array $dots): array
    {
        $dots = array_values(array_filter(array_unique(array_map(
            fn ($d) => (string) $d,
            $dots
        )), fn ($d) => $d !== ''));

        if (empty($dots)) {
            return [];
        }

        $scores = [];
        $pending = [];

        foreach ($dots as $dot) {
            $cached = Cache::get('carrier_dt_score:'.$dot);

            if ($cached !== null) {
                $scores[$dot] = (int) $cached;
                continue;
            }

            $pending[] = $dot;
        }

        if (! empty($pending)) {
            try {
                $scores += $this->computePageScores($pending);
            } catch (\Throwable $e) {
                // Logged, not raised - see the method docblock.
                Log::warning('[CarrierSearch] DT scoring failed for page: '.$e->getMessage());
            }
        }

        $unscored = array_values(array_filter(
            $dots,
            fn ($dot) => ! array_key_exists($dot, $scores)
        ));

        return $unscored
            ? $scores + $this->lastKnownScores($unscored)
            : $scores;
    }

    /**
     * Compute (and cache) scores for carriers that had no cached value.
     *
     * A fixed number of statements regardless of page size: one to load the
     * carriers with their relations, one for inspection aggregates, one for
     * crash aggregates. Everything after that runs against data already in
     * memory.
     *
     * @param  array<int, string>  $dots
     * @return array<int|string, int>
     */
    private function computePageScores(array $dots): array
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

        foreach ($carriers as $carrier) {
            $dot = (string) $carrier->dot_number;

            try {
                $score = $this->trustScoreForRow(
                    $carrier,
                    $inspectionStats[$carrier->dot_number] ?? [],
                    $crashStats[$carrier->dot_number] ?? [],
                );
            } catch (\Throwable $e) {
                // One carrier's bad data must not cost the other nine their
                // scores, so this is caught per row rather than per page.
                Log::warning("[CarrierSearch] DT scoring failed for DOT {$dot}: ".$e->getMessage());
                continue;
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
     * The last score this company was shown for these carriers.
     *
     * A stale-but-real number beats an empty column when live scoring could
     * not answer. Scoped to the viewing company because that is how
     * search_histories is keyed - one company's history is not another's.
     *
     * @param  array<int, string>  $dots
     * @return array<int|string, int>
     */
    private function lastKnownScores(array $dots): array
    {
        $companyId = auth()->user()->company_id ?? null;

        if (! $companyId || empty($dots)) {
            return [];
        }

        try {
            return DB::table('search_histories')
                ->where('company_id', $companyId)
                ->whereIn('carrier_id', $dots)
                ->whereNotNull('dt_score')
                ->pluck('dt_score', 'carrier_id')
                ->map(fn ($score) => (int) $score)
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[CarrierSearch] DT score fallback lookup failed: '.$e->getMessage());

            return [];
        }
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

        return isset($trustScore['overall_score'])
            ? (int) $trustScore['overall_score']
            : null;
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
     * started running search-page logic instead of its own.
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

    private function streamCsv(Request $request, Builder $query)
    {
        @set_time_limit(0);
        $fileName = 'carrier_export_' . date('Y-m-d_H-i-s') . '.csv';

        $seekQuery = (clone $query)->reorder('carriers.dot_number', 'asc');

        return response()->streamDownload(function () use ($seekQuery) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'Company Name', 'DOT Number', 'MC Number', 'DUNS', 'Fleet Size',
                'Mileage', 'Safety Rating', 'Active Authority', 'Insurance Current',
                'Phone', 'Email', 'Address', 'Distance (mi)', 'DT Score', 'Risk Level',
            ]);

            $lastId = 0;

            do {
                $rows = (clone $seekQuery)
                    ->where('carriers.dot_number', '>', $lastId)
                    ->limit(self::EXPORT_CHUNK)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $lastId = (int) $rows->last()->dot_number;

                foreach ($this->hydrateRows($rows->all()) as $item) {
                    fputcsv($file, [
                        $item['company_name'],
                        $item['dot_number'] ?? 'N/A',
                        $item['mc_number'] ?? 'N/A',
                        $item['duns'] ?? 'N/A',
                        $item['fleet_size'],
                        $item['mileage'],
                        $item['safety_rating'],
                        $item['active_authority'] ? 'Yes' : 'No',
                        $item['insurance_current'] ? 'Yes' : 'No',
                        $item['phone'],
                        $item['email'],
                        $item['address'],
                        $item['distance_in_miles'] ?? '',
                        $item['dt_score'] ?? 0,
                        $item['risk_level'] ?? 'Pending',
                    ]);
                }

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

            } while ($rows->count() === self::EXPORT_CHUNK);

            fclose($file);
        }, $fileName, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'X-Accel-Buffering'   => 'no',
        ]);
    }

    private function emptyCsv()
    {
        $fileName = 'carrier_export_' . date('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Company Name', 'DOT Number', 'MC Number', 'DUNS', 'Fleet Size',
                'Mileage', 'Safety Rating', 'Active Authority', 'Insurance Current',
                'Phone', 'Email', 'Address', 'Distance (mi)', 'DT Score', 'Risk Level',
            ]);
            fclose($file);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    private function setStatementTimeout(int $ms): void
    {
        try {
            $this->conn()->statement('SET SESSION MAX_EXECUTION_TIME = ' . (int) $ms);
        } catch (\Throwable $e) {
        }
    }

    private function timeoutResponse(\Throwable $e)
    {
        if (str_contains($e->getMessage(), 'maximum statement execution time')) {
            Log::warning('[CarrierSearch] query exceeded execution budget');

            return response()->json([
                'message' => 'This search is too broad to complete. Add a state, radius or fleet-size filter and try again.',
                'code'    => 'SEARCH_TIMEOUT',
            ], 503);
        }

        throw $e;
    }

    private function emptyPayload(Request $request): array
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 10)));

        return (new LengthAwarePaginator([], 0, $perPage, 1, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]))->toArray() + ['count_capped' => false];
    }
}

class EmptyResultException extends \RuntimeException
{
}
