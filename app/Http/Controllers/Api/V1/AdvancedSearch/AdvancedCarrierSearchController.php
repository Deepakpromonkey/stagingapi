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
        // =========================================================================
        // 1. BASE QUERY & EXTERNAL DB SUBQUERIES (MC, DUNS, Safety Rating)
        // =========================================================================
        $query = DB::connection('external_db')
            ->table('carriers')
            ->select('carriers.*');

        $query->addSelect([
            'census_duns' => DB::connection('external_db')
                ->table('company_census_file')
                ->whereColumn('company_census_file.dot_number', 'carriers.dot_number')
                ->select('dun_bradstreet_no')->limit(1),
                
            'census_mc_prefix' => DB::connection('external_db')
                ->table('company_census_file')
                ->whereColumn('company_census_file.dot_number', 'carriers.dot_number')
                ->select('docket1prefix')->limit(1),
                
            'census_mc_num' => DB::connection('external_db')
                ->table('company_census_file')
                ->whereColumn('company_census_file.dot_number', 'carriers.dot_number')
                ->select('docket1')->limit(1),
                
            'census_safety_rating' => DB::connection('external_db')
                ->table('company_census_file')
                ->whereColumn('company_census_file.dot_number', 'carriers.dot_number')
                ->select('safety_rating')->limit(1),

            'has_active_authority' => DB::connection('external_db')
                ->table('carrier_all_with_history')
                ->whereColumn('carrier_all_with_history.dot_number', 'carriers.dot_number')
                ->where(function($q) {
                    $q->where('common_stat', 'A')->orWhere('contract_stat', 'A')->orWhere('broker_stat', 'A');
                })
                ->select(DB::raw(1))->limit(1),

            'has_active_insurance' => DB::connection('external_db')
                ->table('actpendinsur_all_with_history')
                ->whereColumn('actpendinsur_all_with_history.dot_number', 'carriers.dot_number')
                ->select(DB::raw(1))->limit(1)
        ]);

        // =========================================================================
        // 2. GLOBAL SEARCH (Text Query / Entity Type)
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
            $entityType = strtolower(trim($request->input('entity_type')));
            if ($entityType === 'brokers') $query->where('carriers.carrier_operation', 'B');
            elseif ($entityType === 'carriers') $query->whereIn('carriers.carrier_operation', ['A', 'C']);
            elseif (in_array($entityType, ['both', 'all companies'])) $query->whereIn('carriers.carrier_operation', ['A', 'B', 'C']);
        }

        // =========================================================================
        // 3. THE "DO OUR BEST" SMART LOCATION & RADIUS SEARCH
        // =========================================================================
        $isRadiusSearch = false;
        if ($request->filled('location')) {
            $loc = trim($request->input('location'));
            $radius = $request->filled('radius_miles') ? (float) $request->input('radius_miles') : null;
            $lat = null;
            $lng = null;

            if ($radius && $radius > 0) {
                if (preg_match('/^\d{5}$/', $loc)) {
                    $origin = DB::connection('external_db')->table('zip_centroids')->where('zip', $loc)->first(['lat', 'lng']);
                    if ($origin) { $lat = $origin->lat; $lng = $origin->lng; }
                }
                elseif (strlen($loc) === 2) {
                    $origin = DB::connection('external_db')->table('zip_centroids')->where('state', strtoupper($loc))->selectRaw('AVG(lat) as lat, AVG(lng) as lng')->first();
                    if ($origin && $origin->lat !== null) { $lat = $origin->lat; $lng = $origin->lng; }
                }
                elseif (str_contains($loc, ',')) {
                    $parts = explode(',', $loc);
                    $origin = DB::connection('external_db')->table('zip_centroids')->where('state', strtoupper(trim($parts[1])))->where(DB::raw('LOWER(city)'), strtolower(trim($parts[0])))->selectRaw('AVG(lat) as lat, AVG(lng) as lng')->first();
                    if ($origin && $origin->lat !== null) { $lat = $origin->lat; $lng = $origin->lng; }
                }
                else {
                    $origin = DB::connection('external_db')->table('zip_centroids')->where(DB::raw('LOWER(city)'), strtolower($loc))->selectRaw('AVG(lat) as lat, AVG(lng) as lng')->first();
                    if ($origin && $origin->lat !== null) { $lat = $origin->lat; $lng = $origin->lng; }
                }

                if ($lat !== null && $lng !== null) $isRadiusSearch = true;
            }

            if ($isRadiusSearch) {
                $query->join('zip_centroids as zc', DB::raw('LEFT(REGEXP_REPLACE(carriers.phy_zip, "[^0-9]", ""), 5)'), '=', 'zc.zip');
                $haversine = "(3958.7613 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(zc.lat - {$lat}) / 2), 2) + COS(RADIANS({$lat})) * COS(RADIANS(zc.lat)) * POWER(SIN(RADIANS(zc.lng - {$lng}) / 2), 2))))";
                $query->addSelect(DB::raw("{$haversine} AS distance_in_miles"));
                $latFudge = $radius / 69.0;
                $lngFudge = $radius / (69.0 * cos(deg2rad($lat)));
                $query->whereBetween('zc.lat', [$lat - $latFudge, $lat + $latFudge])->whereBetween('zc.lng', [$lng - $lngFudge, $lng + $lngFudge]);
                $query->whereRaw("{$haversine} <= ?", [$radius]);
                $query->orderBy('distance_in_miles', 'ASC'); // Order by closest first
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
        // 4. AUTHORITY (Common, Contract, Broker)
        // =========================================================================
        if ($request->filled('authority') && is_array($request->input('authority'))) {
            $authorities = $request->input('authority'); 
            $query->whereExists(function ($sub) use ($authorities) {
                $sub->select(DB::raw(1))->from('carrier_all_with_history as auth_hist')->whereColumn('auth_hist.dot_number', 'carriers.dot_number')->where(function ($authSub) use ($authorities) {
                    foreach ($authorities as $auth) {
                        $normalized = strtolower(trim($auth));
                        if (str_contains($normalized, 'broker')) $authSub->orWhere('auth_hist.broker_stat', 'A');
                        if (str_contains($normalized, 'common')) $authSub->orWhere('auth_hist.common_stat', 'A');
                        if (str_contains($normalized, 'contract')) $authSub->orWhere('auth_hist.contract_stat', 'A');
                    }
                });
            });
        }

        // =========================================================================
        // 5. AUTHORITY AGE (Min / Max Months)
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
        // 6. OPERATION TYPE (Interstate, Intrastate, Hazmat)
        // =========================================================================
        if ($request->filled('operations') && is_array($request->input('operations'))) {
            $ops = array_map(function($op) { return strtolower(trim($op)); }, $request->input('operations'));
            if (in_array('interstate', $ops)) $query->where('carriers.carrier_operation', 'A');
            if (in_array('intrastate', $ops)) $query->where('carriers.carrier_operation', 'C');
            if (in_array('hazmat', $ops)) $query->where('carriers.hm_flag', 1);
        }

        // =========================================================================
        // 7. FLEET SIZE
        // =========================================================================
        if ($request->filled('min_fleet')) $query->where('carriers.nbr_power_unit', '>=', (int) $request->input('min_fleet'));
        if ($request->filled('max_fleet')) $query->where('carriers.nbr_power_unit', '<=', (int) $request->input('max_fleet'));
        
        if ($request->filled('fleet_bracket')) {
            $bracket = strtoupper(trim($request->input('fleet_bracket')));
            $bracketMap = [
                'A' => [1, 1], 'B' => [2, 3], 'C' => [4, 6], 'D' => [7, 8], 'E' => [9, 11], 'F' => [12, 14], 'G' => [15, 17], 'H' => [18, 19],
                'I' => [20, 23], 'J' => [24, 28], 'K' => [29, 32], 'L' => [33, 38], 'M' => [39, 44], 'N' => [45, 55], 'O' => [56, 75], 'P' => [76, 100],
                'Q' => [101, 200], 'R' => [201, 300], 'S' => [301, 400], 'T' => [401, 550], 'U' => [551, 999], 'V' => [1000, 2000], 'W' => [2001, 3000], 'X' => [3001, 4000], 'Y' => [4001, 5000], 'Z' => [5001, 999999],
            ];
            if (isset($bracketMap[$bracket])) $query->whereBetween('carriers.nbr_power_unit', $bracketMap[$bracket]);
        }

        // =========================================================================
        // 8. INSURANCE (Minimum BIPD on File)
        // =========================================================================
        if ($request->filled('min_bipd')) {
            $minBipd = (float) $request->input('min_bipd');
            $query->whereExists(function ($sub) use ($minBipd) {
                $sub->select(DB::raw(1))->from('actpendinsur_all_with_history as ins')->whereColumn('ins.dot_number', 'carriers.dot_number')->where(function ($typeSub) {
                    $typeSub->where('ins.mod_col_1', 'LIKE', '%BIPD%')->orWhere('ins.ins_form_code', 'LIKE', '91%'); 
                })->where('ins.max_cov_amount', '>=', $minBipd);
            });
        }

        // =========================================================================
        // 9. SAFETY RATING
        // =========================================================================
        if ($request->filled('safety_rating') && is_array($request->input('safety_rating'))) {
            $ratings = $request->input('safety_rating');
            $ratingMap = ['satisfactory' => 'S', 'conditional' => 'C', 'unsatisfactory' => 'U'];
            $mappedCodes = [];
            $includeNone = false;

            foreach ($ratings as $r) {
                $cleanR = strtolower(trim($r));
                if (isset($ratingMap[$cleanR])) $mappedCodes[] = $ratingMap[$cleanR];
                elseif ($cleanR === 'none') $includeNone = true;
            }

            if (!empty($mappedCodes) || $includeNone) {
                $query->whereExists(function ($sub) use ($mappedCodes, $includeNone) {
                    $sub->select(DB::raw(1))->from('company_census_file as census')->whereColumn('census.dot_number', 'carriers.dot_number')->where(function ($censusSub) use ($mappedCodes, $includeNone) {
                        if (!empty($mappedCodes)) $censusSub->whereIn('census.safety_rating', $mappedCodes);
                        if ($includeNone) $censusSub->orWhereNull('census.safety_rating')->orWhere('census.safety_rating', '')->orWhere('census.safety_rating', 'N');
                    });
                });
            }
        }

       // =========================================================================
        // 10. CARGO CARRIED & EQUIPMENT
        // =========================================================================
        if ($request->filled('cargo') && is_array($request->input('cargo'))) {
            $cargoList = $request->input('cargo');
            $cargoMap = [
                'agricultural/farm supplies' => 'crgo_farmsupp',   'beverages'                  => 'crgo_beverages',
                'building materials'         => 'crgo_bldgmat',    'chemicals'                  => 'crgo_chem',
                'coal/coke'                  => 'crgo_coalcoke',   'commodities dry bulk'       => 'crgo_drybulk',
                'construction'               => 'crgo_construct',  'drive/tow away'             => 'crgo_drivetow',
                'fresh produce'              => 'crgo_produce',    'garbage/refuse'             => 'crgo_garbage',
                'general freight'            => 'crgo_genfreight', 'grain/feed/hay'             => 'crgo_grainfeed',
                'household goods'            => 'crgo_household',  'intermodal containers'      => 'crgo_intermodal',
                'liquids/gases'              => 'crgo_liqgas',     'livestock'                  => 'crgo_livestock',
                'logs/poles/beams/lumber'    => 'crgo_logpole',    'machinery/large objects'    => 'crgo_machlrg',
                'meat'                       => 'crgo_meat',       'metal: sheets/coils/rolls'  => 'crgo_metalsheet',
                'mobile homes'               => 'crgo_mobilehome', 'motor vehicles'             => 'crgo_motoveh',
                'other'                      => 'crgo_cargoothr',  'oilfield equipment'         => 'crgo_oilfield',
                'paper products'             => 'crgo_paperprod',  'passengers'                 => 'crgo_passengers',
                'refrigerated food'          => 'crgo_coldfood',   'water well'                 => 'crgo_waterwell',
                'u.s. mail'                  => 'crgo_usmail',     'utilities'                  => 'crgo_utility',
                'dry van'                    => 'crgo_genfreight', 'reefer'                     => 'crgo_coldfood',
                'flatbed'                    => 'crgo_bldgmat',    'tanker'                     => 'crgo_liqgas',
                'auto carrier'               => 'crgo_motoveh',    'container'                  => 'crgo_intermodal',
                'dump trailer'               => 'crgo_drybulk',
            ];

            $validColumns = [];
            foreach ($cargoList as $item) {
                $cleanItem = strtolower(trim($item));
                if (isset($cargoMap[$cleanItem])) $validColumns[] = $cargoMap[$cleanItem];
            }
            $validColumns = array_unique($validColumns);

            if (!empty($validColumns)) {
                $query->whereExists(function ($sub) use ($validColumns) {
                    $sub->select(DB::raw(1))->from('company_census_file as census_cargo')->whereColumn('census_cargo.dot_number', 'carriers.dot_number')->where(function ($cargoSub) use ($validColumns) {
                        foreach ($validColumns as $column) {
                            $cargoSub->orWhere("census_cargo.{$column}", 'X')->orWhere("census_cargo.{$column}", 'Y')->orWhere("census_cargo.{$column}", 1);
                        }
                    });
                });
            }
        }

        // =========================================================================
        // EXECUTE: CSV STREAM EXPORT OR NORMAL PAGINATION
        // =========================================================================
        
        // IF THE FRONTEND REQUESTS A CSV DOWNLOAD
        if ($request->boolean('export_csv')) {
            $fileName = 'carrier_export_' . date('Y-m-d_H-i-s') . '.csv';

            // Ensure we have a consistent order for chunking if it's not a radius search
            if (!$isRadiusSearch) {
                $query->orderBy('carriers.dot_number', 'ASC');
            }

            return response()->streamDownload(function () use ($query) {
                $file = fopen('php://output', 'w');
                
                // Write CSV Header Row
                fputcsv($file, [
                    'Company Name', 'DOT Number', 'MC Number', 'DUNS', 'Fleet Size', 
                    'Mileage', 'Safety Rating', 'Active Authority', 'Insurance Current', 
                    'Phone', 'Email', 'Address', 'DT Score', 'Risk Level'
                ]);

                // Pull data in chunks of 500 so your server memory never crashes
                $query->chunk(500, function ($carriers) use ($file) {
                    // Fetch Local DB Data for this specific 500 chunk
                    $dotNumbers = $carriers->pluck('dot_number')->filter()->toArray();
                    $localMetrics = [];
                    if (!empty($dotNumbers)) {
                        $localMetrics = DB::table('carriers')->whereIn('dot_number', $dotNumbers)->get()->keyBy('dot_number');
                    }

                    foreach ($carriers as $carrier) {
                        $data = (array) $carrier;
                        $local = $localMetrics->get($data['dot_number']);

                        // Clean fields for CSV
                        $address = trim(($data['phy_street'] ?? '') . ', ' . ($data['phy_city'] ?? '') . ', ' . ($data['phy_state'] ?? '') . ' ' . ($data['phy_zip'] ?? ''), ', ');
                        $mcPrefix = $data['census_mc_prefix'] ?? '';
                        $mcNum    = $data['census_mc_num'] ?? '';
                        $builtMc  = trim($mcPrefix . $mcNum);

                        // Write this carrier's row to the CSV file
                        fputcsv($file, [
                            $data['legal_name'] ?? 'UNKNOWN',
                            $data['dot_number'] ?? 'N/A',
                            $builtMc !== '' ? $builtMc : 'N/A',
                            $data['census_duns'] ?? 'N/A',
                            $data['nbr_power_unit'] ?? 0,
                            $data['mcs150_mileage'] ?? 0,
                            $data['census_safety_rating'] ?? 'Not Rated',
                            !empty($data['has_active_authority']) ? 'Yes' : 'No',
                            !empty($data['has_active_insurance']) ? 'Yes' : 'No',
                            $data['telephone'] ?? 'N/A',
                            $data['email_address'] ?? 'N/A',
                            $address !== '' ? $address : 'N/A',
                            $local->dt_score ?? 0,
                            $local->risk_level ?? 'Pending'
                        ]);
                    }
                });
                
                fclose($file);
            }, $fileName, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ]);
        }

        // =========================================================================
        // IF NOT EXPORTING: RETURN NORMAL PAGINATED JSON
        // =========================================================================
        $perPage = (int) $request->input('per_page', 10);
        $paginated = $query->paginate($perPage);

        $dotNumbers = collect($paginated->items())->pluck('dot_number')->filter()->toArray();
        $localMetrics = [];
        if (!empty($dotNumbers)) {
            $localMetrics = DB::table('carriers')->whereIn('dot_number', $dotNumbers)->get()->keyBy('dot_number');
        }

        $paginated->through(function ($carrier) use ($localMetrics) {
            $data = (array) $carrier;
            $local = $localMetrics->get($data['dot_number']);

            $address = trim(($data['phy_street'] ?? '') . ', ' . ($data['phy_city'] ?? '') . ', ' . ($data['phy_state'] ?? '') . ' ' . ($data['phy_zip'] ?? ''), ', ');
            $data['address'] = $address !== '' ? $address : 'N/A';

            $data['carrier_id']   = $data['id'] ?? null;
            $data['company_name'] = $data['legal_name'] ?? 'UNKNOWN';
            $data['fleet_size']   = $data['nbr_power_unit'] ?? 0;
            $data['mileage']      = $data['mcs150_mileage'] ?? 0;
            $data['phone']        = $data['telephone'] ?? 'N/A';
            $data['email']        = $data['email_address'] ?? 'N/A';

            $mcPrefix = $data['census_mc_prefix'] ?? '';
            $mcNum    = $data['census_mc_num'] ?? '';
            $builtMc  = trim($mcPrefix . $mcNum);
            $data['mc_number'] = $builtMc !== '' ? $builtMc : null;

            $data['duns']          = $data['census_duns'] ?? null;
            $data['safety_rating'] = $data['census_safety_rating'] ?? 'Not Rated'; 
            $data['active_authority']  = !empty($data['has_active_authority']); 
            $data['insurance_current'] = !empty($data['has_active_insurance']); 

            // Local App Scores
            $data['dt_score']           = $local->dt_score ?? null; 
            $data['authority_verified'] = $local->authority_verified ?? false;
            $data['risk_level']         = $local->risk_level ?? null;

            unset(
                $data['id'], $data['legal_name'], $data['nbr_power_unit'], $data['mcs150_mileage'], 
                $data['telephone'], $data['email_address'], $data['census_duns'], $data['census_mc_prefix'], 
                $data['census_mc_num'], $data['has_active_authority'], $data['has_active_insurance'], $data['census_safety_rating']
            );

            if (isset($data['distance_in_miles'])) {
                $data['distance_in_miles'] = round($data['distance_in_miles'], 2);
            }

            return $data;
        });
        
        return response()->json($paginated);
    }
}