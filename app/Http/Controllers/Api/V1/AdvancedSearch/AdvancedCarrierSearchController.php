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
    private const CONN = 'external_db';
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

        // Converted from a correlated whereExists() to an independent
        // whereIn(subquery). This question - "which DOT numbers have this
        // authority active" - doesn't actually depend on each candidate
        // carrier row, so it can be answered ONCE. MySQL can materialize
        // that answer into a small indexed list and reuse it for every
        // candidate, instead of running a separate check per candidate row
        // (which is what a correlated EXISTS does, and what caused the
        // authority-filter timeouts even after removing the stray LIMIT 1).
        $query->whereIn('carriers.dot_number', function ($sub) use ($columns) {
            $sub->select('auth_hist.dot_int')
                ->distinct()
                ->from('carrier_all_with_history as auth_hist')
                ->where(function ($s) use ($columns) {
                    foreach ($columns as $col) {
                        $s->orWhere("auth_hist.{$col}", 'A');
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

            // NOTE: carrier_all_with_history.dot_number is a zero-padded
            // string (e.g. "04516637"), while our carrier list has plain
            // numbers (e.g. "4516637") - those never match as text. dot_int
            // is the real, unpadded number, so we match on that instead
            // (same fix already applied in applyAuthority() for filtering).
            $authority = $this->conn()->table('carrier_all_with_history')
                ->whereIn('dot_int', $dots)
                ->where(function ($q) {
                    $q->where('common_stat', 'A')
                      ->orWhere('contract_stat', 'A')
                      ->orWhere('broker_stat', 'A');
                })
                ->distinct()
                ->pluck('dot_int')
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