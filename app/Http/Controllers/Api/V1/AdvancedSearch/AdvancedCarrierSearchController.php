<?php

namespace App\Http\Controllers\Api\V1\AdvancedSearch;

use App\Http\Controllers\Controller;
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
 * OPTIMIZATION SUMMARY (vs. the original implementation)
 * ------------------------------------------------------
 * 1.  Killed all 6 correlated `addSelect()` subqueries. They executed once per returned
 *     row (and once per row of a 250k-row CSV export). They are now 3 batched
 *     `whereIn(dot_number)` lookups executed once per page / per export chunk.
 * 2.  Killed the non-sargable radius JOIN
 *     `LEFT(REGEXP_REPLACE(carriers.phy_zip,'[^0-9]',''),5) = zc.zip`.
 *     A function on the left side of a join predicate makes an index impossible, so
 *     MySQL had to read all ~1.5M carrier rows and run REGEXP_REPLACE on each one.
 *     We now resolve the radius against `zip_centroids` FIRST (a tiny table), drop the
 *     matching zips into a MEMORY temp table, and join carriers on an indexed column.
 *     Trig runs ~40k times instead of 1.5M times, and the join becomes an index ref.
 * 3.  Merged the two `company_census_file` EXISTS clauses (safety rating + cargo) into
 *     a single semi-join, so census is probed once instead of twice.
 * 4.  Replaced `->paginate()` with a bounded, cached COUNT. The default paginator runs
 *     `SELECT COUNT(*)` over the entire filtered set on every single page request —
 *     on this dataset that alone can be the 504.
 * 5.  Replaced `chunk(500)` in the CSV export with keyset (seek) pagination.
 *     `chunk()` uses OFFSET, so chunk #400 makes MySQL walk and discard 200,000 rows.
 *     Export cost was O(n^2); it is now O(n).
 * 6.  Explicit column list instead of `carriers.*` (less IO, allows covering indexes).
 * 7.  Statement-level `MAX_EXECUTION_TIME` so a pathological query returns a clean 503
 *     instead of holding an nginx worker until it 504s.
 * 8.  Bug fixes: `operations` used AND between interstate/intrastate (always 0 rows);
 *     `$localMetrics` was initialised as `[]` then called as a Collection; radius
 *     lat/lng were string-interpolated into SQL.
 *
 * REQUIRED DDL: see carrier_search_indexes.sql — this controller is fast *because of*
 * those indexes. Without them it is only moderately better than the original.
 */
class AdvancedCarrierSearchController extends Controller
{
    /** External FMCSA connection name. */
    private const CONN = 'external_db';

    /** Flip to true ONLY after creating FULLTEXT ft_carriers_names (legal_name, dba_name). */
    private const USE_FULLTEXT_NAME_SEARCH = false;

    /** Abort runaway SELECTs instead of letting nginx 504 (milliseconds, MySQL 5.7.8+). */
    private const QUERY_TIMEOUT_MS = 25000;

    /** Rows pulled per keyset page during CSV export. */
    private const EXPORT_CHUNK = 2000;

    /** Hard ceiling on zips resolved for a radius search (protects the MEMORY temp table). */
    private const MAX_RADIUS_ZIPS = 40000;

    /** COUNT(*) is bounded to this many rows, then reported as "N+". Keeps counts O(1)-ish. */
    private const COUNT_CAP = 10000;

    /** How long an identical filter-set's total is cached. */
    private const COUNT_CACHE_TTL = 300;

    private const TMP_ZIP_TABLE = 'tmp_zip_radius';

    /**
     * Explicit projection. `carriers.*` forced MySQL to hit the clustered index for every
     * candidate row; a narrow list lets covering indexes do the work.
     * Add columns here if the frontend needs more — do not go back to `*`.
     */
    private const CARRIER_COLUMNS = [
        'carriers.id',
        'carriers.dot_number',
        'carriers.legal_name',
        'carriers.dba_name',
        'carriers.phy_street',
        'carriers.phy_city',
        'carriers.phy_state',
        'carriers.phy_zip',
        'carriers.telephone',
        'carriers.email_address',
        'carriers.nbr_power_unit',
        'carriers.mcs150_mileage',
        'carriers.carrier_operation',
        'carriers.hm_flag',
        'carriers.add_date',
    ];

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

    /** zip => distance-in-miles map, populated only for radius searches. */
    private array $zipDistances = [];

    private bool $isRadiusSearch = false;

    // =====================================================================================
    // ENTRY POINT
    // =====================================================================================

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

        $isExport = $request->boolean('export_csv');

        // A capped execution time turns a runaway plan into a fast, catchable failure
        // rather than an nginx 504. Exports intentionally run without the cap.
        $this->setStatementTimeout($isExport ? 0 : self::QUERY_TIMEOUT_MS);

        try {
            $query = $this->buildQuery($request);
        } catch (EmptyResultException $e) {
            // e.g. radius search whose origin resolved to nothing — short-circuit,
            // never send a doomed query to a 1.5M-row table.
            return $isExport
                ? $this->emptyCsv()
                : response()->json($this->emptyPayload($request));
        }

        return $isExport
            ? $this->streamCsv($request, $query)
            : $this->respondPaginated($request, $query);
    }

    // =====================================================================================
    // QUERY CONSTRUCTION
    // =====================================================================================

    private function conn()
    {
        return DB::connection(self::CONN);
    }

    /**
     * Builds the filtered carrier query. NOTE: no correlated subqueries live in the SELECT
     * any more — enrichment happens in hydrateRows() once the row set is small.
     */
    private function buildQuery(Request $request): Builder
    {
        $query = $this->conn()->table('carriers')->select(self::CARRIER_COLUMNS);

        $this->applyTextSearch($query, $request);
        $this->applyEntityType($query, $request);
        $this->applyLocation($query, $request);
        $this->applyAuthority($query, $request);
        $this->applyAuthorityAge($query, $request);
        $this->applyOperations($query, $request);
        $this->applyFleet($query, $request);
        $this->applyInsurance($query, $request);
        $this->applyCensusFilters($query, $request); // safety rating + cargo, one semi-join

        return $query;
    }

    // -------------------------------------------------------------------------------------
    // 1. TEXT SEARCH
    // -------------------------------------------------------------------------------------

    /**
     * `LIKE '%term%'` cannot use an index — it is a guaranteed full scan of 1.5M rows.
     * Strategy, cheapest first:
     *   - all digits            -> exact dot_number hit (primary/unique key, ~0ms)
     *   - FULLTEXT available    -> MATCH ... AGAINST in boolean mode
     *   - otherwise             -> prefix LIKE 'term%' (uses idx on legal_name / dba_name)
     * A true "contains anywhere" search is only run when the client explicitly asks for it
     * via loose_match=1, so it can never be the accidental default.
     */
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

    // -------------------------------------------------------------------------------------
    // 2. ENTITY TYPE
    // -------------------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------------------
    // 3. LOCATION + RADIUS  (the single biggest win in this file)
    // -------------------------------------------------------------------------------------

    /**
     * Old plan: JOIN zip_centroids ON LEFT(REGEXP_REPLACE(carriers.phy_zip,...),5) = zc.zip,
     * then evaluate a Haversine expression per carrier row. Because the join key was wrapped
     * in functions, MySQL could not use any index — it scanned all carriers, ran a regex on
     * each, then ran 6 trig calls on each. That is the 504.
     *
     * New plan:
     *   a) resolve origin lat/lng (cached)
     *   b) bounding-box + Haversine against zip_centroids only (~42k rows, indexed on lat/lng)
     *   c) push the surviving zips into a MEMORY temp table keyed by zip
     *   d) INNER JOIN carriers on the indexed phy_zip5 column — a plain ref lookup
     * Distance comes back pre-computed from the temp table, so ORDER BY distance stays in SQL
     * and costs nothing extra.
     */
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
                    throw new EmptyResultException(); // nothing within the radius, don't touch carriers
                }

                $this->zipDistances  = $zips;
                $this->isRadiusSearch = true;

                $zipColumn = $this->zipJoinColumn();

                if ($this->createZipTempTable($zips)) {
                    $query->join(self::TMP_ZIP_TABLE . ' as zr', 'zr.zip5', '=', $zipColumn)
                          ->addSelect('zr.miles as distance_in_miles')
                          ->orderBy('zr.miles', 'asc');
                } else {
                    // Fallback if the DB user cannot create temp tables. Still index-driven;
                    // distance is mapped in PHP and the page is sorted after fetch.
                    $query->whereIn($zipColumn, array_keys($zips));
                }

                return;
            }
            // Origin unresolved -> silently degrade to the plain text location match below.
        }

        // Non-radius location match. `phy_city LIKE '%x%'` was another full scan; a prefix
        // match on (phy_city, phy_state) and an exact state match are both index-friendly.
        $upper = strtoupper($loc);

        $query->where(function (Builder $sub) use ($loc, $upper) {
            if (strlen($loc) === 2) {
                $sub->where('carriers.phy_state', $upper);
                return;
            }
            if (preg_match('/^\d{5}/', $loc)) {
                $sub->where('carriers.phy_zip', 'LIKE', substr($loc, 0, 5) . '%');
                return;
            }
            if (str_contains($loc, ',')) {
                [$city, $state] = array_pad(explode(',', $loc, 2), 2, '');
                $sub->where('carriers.phy_city', 'LIKE', addcslashes(trim($city), '%_\\') . '%')
                    ->where('carriers.phy_state', strtoupper(trim($state)));
                return;
            }
            $sub->where('carriers.phy_city', 'LIKE', addcslashes($loc, '%_\\') . '%');
        });
    }

    /**
     * Prefer the STORED generated column (see DDL). Falls back to the raw column, which is
     * still indexed but will miss rows stored as ZIP+4.
     */
    private function zipJoinColumn(): string
    {
        $hasZip5 = Cache::remember('carriers.has_phy_zip5', 86400, function () {
            try {
                return Schema::connection(self::CONN)->hasColumn('carriers', 'phy_zip5');
            } catch (\Throwable $e) {
                return false;
            }
        });

        return $hasZip5 ? 'carriers.phy_zip5' : 'carriers.phy_zip';
    }

    /** Resolve a zip / state / "city, ST" / city into a centroid. Cached for a day. */
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
                    ->where('city', trim($city)) // collation is case-insensitive; LOWER() killed the index
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

    /**
     * Returns [zip => miles]. Bounding box first (index range scan on (lat,lng)), Haversine
     * only on survivors. Results are cached: the same "50 miles from 60007" is asked for
     * constantly and the answer never changes.
     */
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

            $rows = $this->conn()->table('zip_centroids')
                ->select('zip')
                ->selectRaw("{$haversine} AS miles", [$lat, $lat, $lng])
                ->whereBetween('lat', [$lat - $latFudge, $lat + $latFudge])
                ->whereBetween('lng', [$lng - $lngFudge, $lng + $lngFudge])
                ->havingRaw('miles <= ?', [$radius])
                ->orderBy('miles')
                ->limit(self::MAX_RADIUS_ZIPS)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $zip = str_pad((string) $row->zip, 5, '0', STR_PAD_LEFT);
                $out[$zip] = round((float) $row->miles, 2);
            }

            return $out;
        });
    }

    private function createZipTempTable(array $zips): bool
    {
        try {
            $this->conn()->statement('DROP TEMPORARY TABLE IF EXISTS ' . self::TMP_ZIP_TABLE);
            $this->conn()->statement(
                'CREATE TEMPORARY TABLE ' . self::TMP_ZIP_TABLE . ' (
                    zip5  CHAR(5)      NOT NULL,
                    miles DECIMAL(7,2) NOT NULL,
                    PRIMARY KEY (zip5)
                ) ENGINE=MEMORY'
            );

            foreach (array_chunk($zips, 2000, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $zip => $miles) {
                    $rows[] = ['zip5' => $zip, 'miles' => $miles];
                }
                $this->conn()->table(self::TMP_ZIP_TABLE)->insert($rows);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[CarrierSearch] temp zip table unavailable, using IN() fallback: ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------------------
    // 4. AUTHORITY
    // -------------------------------------------------------------------------------------

    /**
     * One EXISTS against carrier_all_with_history. The OR-ed status columns are evaluated
     * on a row already located by the dot_number index, so the OR costs nothing — what
     * matters is that (dot_number, common_stat, contract_stat, broker_stat) is indexed so
     * this resolves as a covering index lookup with no table access at all.
     */
    private function applyAuthority(Builder $query, Request $request): void
    {
        $authorities = $request->input('authority');
        if (!$request->filled('authority') || !is_array($authorities)) {
            return;
        }

        $columns = [];
        foreach ($authorities as $auth) {
            $n = strtolower(trim($auth));
            if (str_contains($n, 'broker'))   $columns[] = 'broker_stat';
            if (str_contains($n, 'common'))   $columns[] = 'common_stat';
            if (str_contains($n, 'contract')) $columns[] = 'contract_stat';
        }
        $columns = array_unique($columns);

        if (empty($columns)) {
            return;
        }

        $query->whereExists(function ($sub) use ($columns) {
            $sub->select(DB::raw(1))
                ->from('carrier_all_with_history as auth_hist')
                ->whereColumn('auth_hist.dot_number', 'carriers.dot_number')
                ->where(function ($s) use ($columns) {
                    foreach ($columns as $col) {
                        $s->orWhere("auth_hist.{$col}", 'A');
                    }
                })
                ->limit(1);
        });
    }

    // -------------------------------------------------------------------------------------
    // 5. AUTHORITY AGE
    // -------------------------------------------------------------------------------------

    private function applyAuthorityAge(Builder $query, Request $request): void
    {
        if ($request->filled('min_months')) {
            $query->where(
                'carriers.add_date', '<=',
                Carbon::now()->subMonths((int) $request->input('min_months'))->format('Y-m-d')
            );
        }

        if ($request->filled('max_months')) {
            $query->where(
                'carriers.add_date', '>=',
                Carbon::now()->subMonths((int) $request->input('max_months'))->format('Y-m-d')
            );
        }
    }

    // -------------------------------------------------------------------------------------
    // 6. OPERATION TYPE  (bug fix)
    // -------------------------------------------------------------------------------------

    /**
     * The original ANDed the interstate and intrastate predicates, so selecting both
     * produced `carrier_operation = 'A' AND carrier_operation = 'C'` — always zero rows.
     * They are alternatives, so they belong in an IN(); hazmat is a genuine AND.
     */
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
            $query->where('carriers.hm_flag', 1);
        }
    }

    // -------------------------------------------------------------------------------------
    // 7. FLEET SIZE
    // -------------------------------------------------------------------------------------

    private function applyFleet(Builder $query, Request $request): void
    {
        if ($request->filled('min_fleet')) {
            $query->where('carriers.nbr_power_unit', '>=', (int) $request->input('min_fleet'));
        }

        if ($request->filled('max_fleet')) {
            $query->where('carriers.nbr_power_unit', '<=', (int) $request->input('max_fleet'));
        }

        if ($request->filled('fleet_bracket')) {
            $bracket = strtoupper(trim($request->input('fleet_bracket')));
            if (isset(self::FLEET_BRACKETS[$bracket])) {
                $query->whereBetween('carriers.nbr_power_unit', self::FLEET_BRACKETS[$bracket]);
            }
        }
    }

    // -------------------------------------------------------------------------------------
    // 8. INSURANCE
    // -------------------------------------------------------------------------------------

    /**
     * `mod_col_1 LIKE '%BIPD%'` can never use an index, so the amount predicate has to be
     * the selective one: index (dot_number, max_cov_amount) locates the candidate rows and
     * the LIKE only filters what is already in hand.
     */
    private function applyInsurance(Builder $query, Request $request): void
    {
        if (!$request->filled('min_bipd')) {
            return;
        }

        $minBipd = (float) $request->input('min_bipd');

        $query->whereExists(function ($sub) use ($minBipd) {
            $sub->select(DB::raw(1))
                ->from('actpendinsur_all_with_history as ins')
                ->whereColumn('ins.dot_number', 'carriers.dot_number')
                ->where('ins.max_cov_amount', '>=', $minBipd)
                ->where(function ($s) {
                    $s->where('ins.mod_col_1', 'LIKE', '%BIPD%')
                      ->orWhere('ins.ins_form_code', 'LIKE', '91%');
                })
                ->limit(1);
        });
    }

    // -------------------------------------------------------------------------------------
    // 9 + 10. SAFETY RATING AND CARGO — merged into one census semi-join
    // -------------------------------------------------------------------------------------

    /**
     * The original hit company_census_file with two separate EXISTS blocks, meaning two
     * independent probes of a 1.5M-row table per candidate carrier. Census is 1:1 on
     * dot_number, so both predicate sets belong in a single semi-join.
     *
     * If you deploy the normalised `carrier_cargo` table from the DDL file, swap the cargo
     * block for a `whereIn('cargo_code', ...)` EXISTS — that turns the OR-ed column scan
     * into a pure index range scan and is the single best upgrade for cargo-heavy searches.
     */
    private function applyCensusFilters(Builder $query, Request $request): void
    {
        // --- safety ratings ---
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

        // --- cargo ---
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

        $query->whereExists(function ($sub) use ($mappedCodes, $includeNone, $cargoColumns, $needsSafety, $needsCargo) {
            $sub->select(DB::raw(1))
                ->from('company_census_file as census')
                ->whereColumn('census.dot_number', 'carriers.dot_number');

            if ($needsSafety) {
                $sub->where(function ($s) use ($mappedCodes, $includeNone) {
                    if (!empty($mappedCodes)) {
                        $s->whereIn('census.safety_rating', $mappedCodes);
                    }
                    if ($includeNone) {
                        $s->orWhereNull('census.safety_rating')
                          ->orWhereIn('census.safety_rating', ['', 'N']);
                    }
                });
            }

            if ($needsCargo) {
                $sub->where(function ($s) use ($cargoColumns) {
                    foreach ($cargoColumns as $column) {
                        // Column names come from the whitelist above — never from user input.
                        $s->orWhereIn("census.{$column}", ['X', 'Y', '1']);
                    }
                });
            }

            $sub->limit(1);
        });
    }

    // =====================================================================================
    // JSON RESPONSE
    // =====================================================================================

    private function respondPaginated(Request $request, Builder $query)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 10)));
        $page    = max(1, (int) $request->input('page', LengthAwarePaginator::resolveCurrentPage()));

        // Deterministic tie-breaker: without one, MySQL is free to return different rows for
        // the same page on repeated requests once you paginate a filtered set.
        $rowQuery = (clone $query)->orderBy('carriers.id', 'asc');

        try {
            $rows = $rowQuery->forPage($page, $perPage)->get();
        } catch (\Throwable $e) {
            return $this->timeoutResponse($e);
        }

        $items = $this->hydrateRows($rows->all());

        if ($this->isRadiusSearch && empty($this->zipDistances) === false) {
            usort($items, fn ($a, $b) => ($a['distance_in_miles'] ?? PHP_INT_MAX) <=> ($b['distance_in_miles'] ?? PHP_INT_MAX));
        }

        [$total, $capped] = $this->boundedCount($query, $request, $page, $perPage, count($items));

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]);

        $payload = $paginator->toArray();
        $payload['count_capped'] = $capped; // frontend can render "10,000+ results"

        return response()->json($payload);
    }

    /**
     * `->paginate()` runs an unbounded COUNT(*) over the whole filtered set on EVERY page
     * request — with these EXISTS clauses that is frequently slower than fetching the rows.
     * Here the count is (a) bounded by a LIMIT inside a derived table so its cost has a
     * ceiling, and (b) cached against a hash of the filter set, so paging through results
     * costs one count, not one per page.
     */
    private function boundedCount(Builder $query, Request $request, int $page, int $perPage, int $returned): array
    {
        // Short-circuit: a partial final page tells us the exact total for free.
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

                return (int) $this->conn()
                    ->table(DB::raw('(' . $inner->toSql() . ') as bounded'))
                    ->mergeBindings($inner)
                    ->count();
            } catch (\Throwable $e) {
                Log::warning('[CarrierSearch] bounded count failed: ' . $e->getMessage());
                return self::COUNT_CAP + 1;
            }
        });

        return [min($total, self::COUNT_CAP), $total > self::COUNT_CAP];
    }

    /**
     * Batch enrichment. This replaces the six correlated subqueries that used to sit in the
     * SELECT list — those ran once per row, so a 500-row chunk meant 3,000 extra queries
     * inside MySQL. This is a fixed 4 queries regardless of row count.
     */
    private function hydrateRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $dots = array_values(array_unique(array_filter(array_map(
            fn ($r) => ((array) $r)['dot_number'] ?? null,
            $rows
        ))));

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

            $authority = $this->conn()->table('carrier_all_with_history')
                ->whereIn('dot_number', $dots)
                ->where(function ($q) {
                    $q->where('common_stat', 'A')
                      ->orWhere('contract_stat', 'A')
                      ->orWhere('broker_stat', 'A');
                })
                ->distinct()
                ->pluck('dot_number')
                ->flip()
                ->all();

            $insurance = $this->conn()->table('actpendinsur_all_with_history')
                ->whereIn('dot_number', $dots)
                ->distinct()
                ->pluck('dot_number')
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

    // =====================================================================================
    // CSV EXPORT
    // =====================================================================================

    /**
     * `chunk()` paginates with LIMIT/OFFSET. On chunk 400 of a 200k-row export MySQL has to
     * generate and discard 200,000 rows before returning 500 — the export was quadratic and
     * re-ran every EXISTS clause each time. Keyset ("seek") pagination on the primary key
     * makes every chunk cost the same as the first.
     */
    private function streamCsv(Request $request, Builder $query)
    {
        @set_time_limit(0);
        $fileName = 'carrier_export_' . date('Y-m-d_H-i-s') . '.csv';

        $seekQuery = (clone $query)->reorder('carriers.id', 'asc');

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
                    ->where('carriers.id', '>', $lastId)
                    ->limit(self::EXPORT_CHUNK)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $lastId = (int) $rows->last()->id;

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

                // Push bytes to the client so the browser sees progress and nginx does not
                // time the response out while we are still working.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

            } while ($rows->count() === self::EXPORT_CHUNK);

            fclose($file);
        }, $fileName, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'X-Accel-Buffering'   => 'no', // stops nginx buffering the whole export in RAM
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

    // =====================================================================================
    // HELPERS
    // =====================================================================================

    private function setStatementTimeout(int $ms): void
    {
        try {
            $this->conn()->statement('SET SESSION MAX_EXECUTION_TIME = ' . (int) $ms);
        } catch (\Throwable $e) {
            // MariaDB / older MySQL: ignore, everything else still works.
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

/** Thrown when a filter proves the result set is empty before we ever touch `carriers`. */
class EmptyResultException extends \RuntimeException
{
}