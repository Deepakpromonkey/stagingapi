<?php

namespace App\Http\Controllers\Api\V1\AdvancedSearch;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdvancedCarrierSearchController extends Controller
{
    /**
     * Advanced Filter Search for Carriers, Brokers, and Shippers.
     */
    public function filter(Request $request)
    {
        // 1. Base Query on the external carrier database
        $query = DB::connection('external_db')
            ->table('carriers')
            ->select('carriers.*');

        // =========================================================================
        // 1. GLOBAL SEARCH (Text Query / Entity Type)
        // =========================================================================
        if ($request->filled('query')) {
            $searchTerm = trim($request->input('query'));
            $query->where(function ($sub) use ($searchTerm) {
                $sub->where('carriers.legal_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('carriers.dba_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('carriers.dot_number', $searchTerm);
            });
        }

        if ($request->filled('entity_type')) {
            // BULLETPROOF: Lowercase and trim the input
            $entityType = strtolower(trim($request->input('entity_type')));
            
            if ($entityType === 'brokers') {
                $query->where('carriers.carrier_operation', 'B');
            } elseif ($entityType === 'carriers') {
                $query->whereIn('carriers.carrier_operation', ['A', 'C']);
            }
        }

        // =========================================================================
        // 2. THE "DO OUR BEST" SMART LOCATION & RADIUS SEARCH
        // =========================================================================
        if ($request->filled('location')) {
            $loc = trim($request->input('location'));
            $radius = $request->filled('radius_miles') ? (float) $request->input('radius_miles') : null;

            $lat = null;
            $lng = null;
            $isRadiusSearch = false;

            if ($radius && $radius > 0) {
                // Scenario A: 5-digit ZIP code
                if (preg_match('/^\d{5}$/', $loc)) {
                    $origin = DB::connection('external_db')
                        ->table('zip_centroids')
                        ->where('zip', $loc)
                        ->first(['lat', 'lng']);
                        
                    if ($origin) {
                        $lat = $origin->lat;
                        $lng = $origin->lng;
                    }
                }
                // Scenario B: 2-letter State
                elseif (strlen($loc) === 2) {
                    $origin = DB::connection('external_db')
                        ->table('zip_centroids')
                        ->where('state', strtoupper($loc))
                        ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')
                        ->first();

                    if ($origin && $origin->lat !== null) {
                        $lat = $origin->lat;
                        $lng = $origin->lng;
                    }
                }
                // Scenario C: City and State
                elseif (str_contains($loc, ',')) {
                    $parts = explode(',', $loc);
                    $city = trim($parts[0]);
                    $state = trim($parts[1]);
                    
                    $origin = DB::connection('external_db')
                        ->table('zip_centroids')
                        ->where('state', strtoupper($state))
                        ->where(DB::raw('LOWER(city)'), strtolower($city))
                        ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')
                        ->first();

                    if ($origin && $origin->lat !== null) {
                        $lat = $origin->lat;
                        $lng = $origin->lng;
                    }
                }
                // Scenario D: City Name Only
                else {
                    $origin = DB::connection('external_db')
                        ->table('zip_centroids')
                        ->where(DB::raw('LOWER(city)'), strtolower($loc))
                        ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')
                        ->first();

                    if ($origin && $origin->lat !== null) {
                        $lat = $origin->lat;
                        $lng = $origin->lng;
                    }
                }

                if ($lat !== null && $lng !== null) {
                    $isRadiusSearch = true;
                }
            }

            // APPLY THE FILTER: Radius Math OR Standard Text Search
            if ($isRadiusSearch) {
                $query->join('zip_centroids as zc', DB::raw('LEFT(REGEXP_REPLACE(carriers.phy_zip, "[^0-9]", ""), 5)'), '=', 'zc.zip');

                $haversine = "(3958.7613 * 2 * ASIN(SQRT(
                    POWER(SIN(RADIANS(zc.lat - {$lat}) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(zc.lat)) *
                    POWER(SIN(RADIANS(zc.lng - {$lng}) / 2), 2)
                )))";

                $query->addSelect(DB::raw("{$haversine} AS distance_in_miles"));

                $latFudge = $radius / 69.0;
                $lngFudge = $radius / (69.0 * cos(deg2rad($lat)));

                $query->whereBetween('zc.lat', [$lat - $latFudge, $lat + $latFudge])
                      ->whereBetween('zc.lng', [$lng - $lngFudge, $lng + $lngFudge]);

                $query->whereRaw("{$haversine} <= ?", [$radius]);
                $query->orderBy('distance_in_miles', 'ASC');
            } 
            else {
                $query->where(function ($sub) use ($loc) {
                    $sub->where('carriers.phy_city', 'LIKE', "%{$loc}%")
                        ->orWhere('carriers.phy_state', strtoupper($loc))
                        ->orWhere('carriers.phy_zip', 'LIKE', "{$loc}%");
                });
            }
        }

        // =========================================================================
        // 3. AUTHORITY (Common, Contract, Broker)
        // =========================================================================
        if ($request->filled('authority') && is_array($request->input('authority'))) {
            $authorities = $request->input('authority'); 
            
            $query->whereExists(function ($sub) use ($authorities) {
                $sub->select(DB::raw(1))
                    ->from('carrier_all_with_history as auth_hist')
                    ->whereColumn('auth_hist.dot_number', 'carriers.dot_number')
                    ->where(function ($authSub) use ($authorities) {
                        foreach ($authorities as $auth) {
                            // BULLETPROOF: Already using strtolower here
                            $normalized = strtolower(trim($auth));
                            
                            if (str_contains($normalized, 'broker')) {
                                $authSub->orWhere('auth_hist.broker_stat', 'A');
                            }
                            if (str_contains($normalized, 'common')) {
                                $authSub->orWhere('auth_hist.common_stat', 'A');
                            }
                            if (str_contains($normalized, 'contract')) {
                                $authSub->orWhere('auth_hist.contract_stat', 'A');
                            }
                        }
                    });
            });
        }

        // =========================================================================
        // 4. AUTHORITY AGE (Min / Max Months)
        // =========================================================================
        if ($request->filled('min_months')) {
            $minDate = Carbon::now()->subMonths((int) $request->input('min_months'))->format('Y-m-d');
            $query->where('carriers.add_date', '<=', $minDate);
        }
        
        if ($request->filled('max_months')) {
            $maxDate = Carbon::now()->subMonths((int) $request->input('max_months'))->format('Y-m-d');
            $query->where('carriers.add_date', '>=', $maxDate);
        }

        // =========================================================================
        // 5. OPERATION TYPE (Interstate, Intrastate, Hazmat)
        // =========================================================================
        if ($request->filled('operations') && is_array($request->input('operations'))) {
            // BULLETPROOF: Convert frontend array into clean lowercase strings
            $ops = array_map(function($op) {
                return strtolower(trim($op));
            }, $request->input('operations'));
            
            if (in_array('interstate', $ops)) {
                $query->where('carriers.carrier_operation', 'A');
            }
            if (in_array('intrastate', $ops)) {
                $query->where('carriers.carrier_operation', 'C');
            }
            if (in_array('hazmat', $ops)) {
                $query->where('carriers.hm_flag', 1);
            }
        }

        // =========================================================================
        // 6. FLEET SIZE
        // =========================================================================
        if ($request->filled('min_fleet')) {
            $query->where('carriers.nbr_power_unit', '>=', (int) $request->input('min_fleet'));
        }
        
        if ($request->filled('max_fleet')) {
            $query->where('carriers.nbr_power_unit', '<=', (int) $request->input('max_fleet'));
        }

        if ($request->filled('fleet_bracket')) {
            // BULLETPROOF: strtoupper catches 'a' or 'A'
            $bracket = strtoupper(trim($request->input('fleet_bracket')));
            $bracketMap = [
                'A' => [1, 1],       'B' => [2, 3],       'C' => [4, 6], 
                'D' => [7, 8],       'E' => [9, 11],      'F' => [12, 14], 
                'G' => [15, 17],     'H' => [18, 19],     'I' => [20, 23], 
                'J' => [24, 28],     'K' => [29, 32],     'L' => [33, 38],
                'M' => [39, 44],     'N' => [45, 55],     'O' => [56, 75], 
                'P' => [76, 100],    'Q' => [101, 200],   'R' => [201, 300], 
                'S' => [301, 400],   'T' => [401, 550],   'U' => [551, 999], 
                'V' => [1000, 2000], 'W' => [2001, 3000], 'X' => [3001, 4000], 
                'Y' => [4001, 5000], 'Z' => [5001, 999999],
            ];
            
            if (isset($bracketMap[$bracket])) {
                $query->whereBetween('carriers.nbr_power_unit', $bracketMap[$bracket]);
            }
        }

        // =========================================================================
        // 7. INSURANCE (Minimum BIPD on File)
        // =========================================================================
        if ($request->filled('min_bipd')) {
            $minBipd = (float) $request->input('min_bipd');
            
            $query->whereExists(function ($sub) use ($minBipd) {
                $sub->select(DB::raw(1))
                    ->from('actpendinsur_all_with_history as ins')
                    ->whereColumn('ins.dot_number', 'carriers.dot_number')
                    ->where(function ($typeSub) {
                        $typeSub->where('ins.mod_col_1', 'LIKE', '%BIPD%')
                                ->orWhere('ins.ins_form_code', 'LIKE', '91%'); 
                    })
                    ->where('ins.max_cov_amount', '>=', $minBipd);
            });
        }

        // =========================================================================
        // 8. SAFETY RATING
        // =========================================================================
        if ($request->filled('safety_rating') && is_array($request->input('safety_rating'))) {
            $ratings = $request->input('safety_rating');
            
            // BULLETPROOF: Keys changed to strict lowercase
            $ratingMap = [
                'satisfactory'   => 'S', 
                'conditional'    => 'C', 
                'unsatisfactory' => 'U'
            ];
            
            $mappedCodes = [];
            $includeNone = false;

            foreach ($ratings as $r) {
                // Clean the incoming frontend string
                $cleanR = strtolower(trim($r));
                
                if (isset($ratingMap[$cleanR])) {
                    $mappedCodes[] = $ratingMap[$cleanR];
                } elseif ($cleanR === 'none') {
                    $includeNone = true;
                }
            }

            if (!empty($mappedCodes) || $includeNone) {
                $query->whereExists(function ($sub) use ($mappedCodes, $includeNone) {
                    $sub->select(DB::raw(1))
                        ->from('company_census_file as census')
                        ->whereColumn('census.dot_number', 'carriers.dot_number')
                        ->where(function ($censusSub) use ($mappedCodes, $includeNone) {
                            
                            if (!empty($mappedCodes)) {
                                $censusSub->whereIn('census.safety_rating', $mappedCodes);
                            }
                            
                            if ($includeNone) {
                                $censusSub->orWhereNull('census.safety_rating')
                                          ->orWhere('census.safety_rating', '')
                                          ->orWhere('census.safety_rating', 'N');
                            }
                        });
                });
            }
        }

       // =========================================================================
        // 9. CARGO CARRIED & EQUIPMENT
        // =========================================================================
        if ($request->filled('cargo') && is_array($request->input('cargo'))) {
            $cargoList = $request->input('cargo');
            
            // BULLETPROOF: Every single key changed to strict lowercase
            $cargoMap = [
                'agricultural/farm supplies' => 'crgo_farmsupp', 
                'beverages'                  => 'crgo_beverages',
                'building materials'         => 'crgo_bldgmat', 
                'chemicals'                  => 'crgo_chem',
                'coal/coke'                  => 'crgo_coalcoke', 
                'commodities dry bulk'       => 'crgo_drybulk',
                'construction'               => 'crgo_construct', 
                'drive/tow away'             => 'crgo_drivetow',
                'fresh produce'              => 'crgo_produce', 
                'garbage/refuse'             => 'crgo_garbage',
                'general freight'            => 'crgo_genfreight', 
                'grain/feed/hay'             => 'crgo_grainfeed',
                'household goods'            => 'crgo_household', 
                'intermodal containers'      => 'crgo_intermodal',
                'liquids/gases'              => 'crgo_liqgas', 
                'livestock'                  => 'crgo_livestock',
                'logs/poles/beams/lumber'    => 'crgo_logpole', 
                'machinery/large objects'    => 'crgo_machlrg',
                'meat'                       => 'crgo_meat', 
                'metal: sheets/coils/rolls'  => 'crgo_metalsheet',
                'mobile homes'               => 'crgo_mobilehome', 
                'motor vehicles'             => 'crgo_motoveh',
                'other'                      => 'crgo_cargoothr', 
                'oilfield equipment'         => 'crgo_oilfield',
                'paper products'             => 'crgo_paperprod', 
                'passengers'                 => 'crgo_passengers',
                'refrigerated food'          => 'crgo_coldfood', 
                'water well'                 => 'crgo_waterwell',
                'u.s. mail'                  => 'crgo_usmail', 
                'utilities'                  => 'crgo_utility',
                
                // Equipment mappings
                'dry van'                    => 'crgo_genfreight', 
                'reefer'                     => 'crgo_coldfood',
                'flatbed'                    => 'crgo_bldgmat', 
                'tanker'                     => 'crgo_liqgas',
                'auto carrier'               => 'crgo_motoveh', 
                'container'                  => 'crgo_intermodal',
                'dump trailer'               => 'crgo_drybulk',
            ];

            $validColumns = [];
            foreach ($cargoList as $item) {
                // Clean the incoming frontend string to match our lowercase map
                $cleanItem = strtolower(trim($item));
                
                if (isset($cargoMap[$cleanItem])) {
                    $validColumns[] = $cargoMap[$cleanItem];
                }
            }
            
            $validColumns = array_unique($validColumns);

            if (!empty($validColumns)) {
                $query->whereExists(function ($sub) use ($validColumns) {
                    $sub->select(DB::raw(1))
                        ->from('company_census_file as census_cargo')
                        ->whereColumn('census_cargo.dot_number', 'carriers.dot_number')
                        ->where(function ($cargoSub) use ($validColumns) {
                            foreach ($validColumns as $column) {
                                $cargoSub->orWhere("census_cargo.{$column}", 'X')
                                         ->orWhere("census_cargo.{$column}", 'Y')
                                         ->orWhere("census_cargo.{$column}", 1);
                            }
                        });
                });
            }
        }

        // =========================================================================
        // EXECUTE & PAGINATE
        // =========================================================================
        $perPage = (int) $request->input('per_page', 10);
        
        return response()->json($query->paginate($perPage));
    }
}