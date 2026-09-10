<?php

namespace App\Services;

use App\Models\Carriers\Carrier;
use App\Models\Carriers\Crash;
use App\Models\Carriers\CrashDetail;
use App\Models\Carriers\Inspection;
use App\Models\Carriers\ViolationDetail;
use App\Support\Fmcsa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds the full carrier profile from the EC2 carrier database.
 *
 * Aggregates (inspection counts, crash totals, unique VINs, per-state
 * breakdowns) are computed in SQL rather than by loading whole relations into
 * memory — a carrier with 40k inspections would otherwise hydrate 40k models
 * just to call ->count() on them.
 */
class CarrierProfileService
{
    /** Number of recent rows returned for the large child collections. */
    protected const RECENT_LIMIT = 25;

    /** FMCSA national out-of-service averages. */
    protected const NATIONAL_OOS_VEHICLE = 0.108;

    protected const NATIONAL_OOS_DRIVER = 0.045;

    /** Unsafe Driving BASIC alert threshold. */
    protected const BASIC_ALERT_THRESHOLD = 65;

    /**
     * The vocabulary inspections actually uses. 'TRUCK' and 'TRACTOR' were in
     * this list and are never emitted by the feed, while 'MOTOR COACH',
     * 'PASSENGER VAN' and 'LIMOUSINE' were missing.
     */
    protected const UNIT_TYPES_POWER = [
        'TRUCK TRACTOR', 'STRAIGHT TRUCK', 'BUS', 'SCHOOL BUS',
        'MOTOR COACH', 'PASSENGER VAN', 'LIMOUSINE',
    ];

    protected const FREE_EMAIL_PROVIDERS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
        'aol.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com',
        'zoho.com', 'ymail.com', 'rocketmail.com', 'mail.com', 'gmx.com', 'gmx.net',
        'rediffmail.com', 'att.net', 'verizon.net', 'comcast.net', 'cox.net', 'sbcglobal.net',
    ];

    protected const CARGO_MAP = [
        'crgo_genfreight' => 'General Freight', 'crgo_household' => 'Household Goods',
        'crgo_metalsheet' => 'Metal/Sheet', 'crgo_motoveh' => 'Motor Vehicles',
        'crgo_drivetow' => 'Drive/Tow Away', 'crgo_logpole' => 'Logs/Poles',
        'crgo_bldgmat' => 'Building Materials', 'crgo_mobilehome' => 'Mobile Homes',
        'crgo_machlrg' => 'Machinery/Large', 'crgo_produce' => 'Fresh Produce',
        'crgo_liqgas' => 'Liquids/Gases', 'crgo_intermodal' => 'Intermodal',
        'crgo_passengers' => 'Passengers', 'crgo_oilfield' => 'Oilfield Equipment',
        'crgo_livestock' => 'Livestock', 'crgo_grainfeed' => 'Grain/Feed',
        'crgo_coalcoke' => 'Coal/Coke', 'crgo_meat' => 'Meat',
        'crgo_garbage' => 'Garbage/Refuse', 'crgo_usmail' => 'U.S. Mail',
        'crgo_chem' => 'Chemicals', 'crgo_drybulk' => 'Dry Bulk',
        'crgo_coldfood' => 'Refrigerated Food', 'crgo_beverages' => 'Beverages',
        'crgo_paperprod' => 'Paper Products', 'crgo_utility' => 'Utility',
        'crgo_farmsupp' => 'Farm Supplies', 'crgo_construct' => 'Construction',
        'crgo_waterwell' => 'Water Well',
    ];

    /**
     * @param  string  $identifier  DOT number or row_id (UUID)
     */
    /**
     * @param  array  $include  Optional heavy sections: inspections, crashes,
     *                          contacts, authority_orders, insurance_pending.
     *                          Each one costs a round trip to EC2.
     */
    public function profile(string $identifier, bool $withFmcsa = false, array $include = []): ?array
    {
        $ttl = (int) config('carriers.profile_cache_ttl', 900);

        sort($include);

        $key = 'carrier:profile:'.$identifier.($include ? ':'.implode(',', $include) : '');

        $profile = Cache::remember(
            $key,
            $ttl,
            // Plain arrays only: Laravel restricts allowed_classes when
            // unserializing the cache, so Eloquent models and collections come
            // back as __PHP_Incomplete_Class.
            fn () => $this->toPlainArray($this->build($identifier, $include))
        );

        if ($profile === null) {
            Cache::forget($key);

            return null;
        }

        // Fetched separately so a slow third party never delays the profile,
        // and so it can be cached on its own schedule.
        if ($withFmcsa) {
            $profile['fmcsa_data'] = $this->fmcsaSnapshot($profile['dot_number']);
        }

        return $profile;
    }

    protected function build(string $identifier, array $include = []): ?array
    {
        $carrier = Carrier::query()
            ->where(
                // row_id is a UUID; anything else is treated as a DOT number.
                str_contains($identifier, '-') ? 'row_id' : 'dot_number',
                $identifier
            )
            ->with(array_merge(
                [
                    // Always needed: one row each, and computed fields depend
                    // on them.
                    'authority',
                    'smsMeasures',
                    'carrierDetail',
                    'oosOrders',
                    'authorityHistory',
                ],
                in_array('contacts', $include, true) ? ['contacts'] : [],
                in_array('authority_orders', $include, true) ? ['authorityOrders'] : [],
                in_array('insurance_pending', $include, true) ? ['insuranceFilingsPending'] : [],
            ))
            ->first();

        if (! $carrier) {
            return null;
        }

        $dot = $carrier->dot_number;
        $detail = $carrier->carrierDetail;
        $sms = $carrier->smsMeasures;
        $authority = $carrier->authority;

        // Observed-equipment and per-state figures are derived from the
        // inspection table. They are slow on EC2 (non-covering indexes) but
        // they are core profile data, so they are always computed.
        $heavy = true;

        $stats = $this->stats($dot, $heavy);
        $inspectionStats = $stats;
        $crashStats = $stats;
        $violationStats = $stats;

        $stateCounts = collect(json_decode($stats->state_counts ?? '{}', true) ?: [])
            ->sortDesc();

        $heavyValue = fn ($value) => $heavy ? $value : null;

        $insurance = $this->insurance($carrier);

        $vehicleInspTotal = (int) ($sms?->vehicle_insp_total ?? 0);
        $vehicleOosTotal = (int) ($sms?->vehicle_oos_insp_total ?? 0);
        $driverInspTotal = (int) ($sms?->driver_insp_total ?? 0);
        $driverOosTotal = (int) ($sms?->driver_oos_insp_total ?? 0);
        // sms_measures has never carried hazmat totals; they come off the
        // inspection rows, which stats() now aggregates.
        $hazmatInspTotal = (int) ($inspectionStats->hazmat_insp_total ?? 0);
        $hazmatOosTotal = (int) ($inspectionStats->hazmat_oos_total ?? 0);

        $vehicleOosPct = $vehicleInspTotal > 0 ? round($vehicleOosTotal / $vehicleInspTotal * 100, 2) : null;
        $driverOosPct = $driverInspTotal > 0 ? round($driverOosTotal / $driverInspTotal * 100, 2) : null;

        $riskScore = (float) ($sms?->unsafe_driv_measure ?? 0)
            + (float) ($sms?->hos_driv_measure ?? 0)
            + (float) ($sms?->veh_maint_measure ?? 0);

        $totalInspections = (int) $inspectionStats->insp_total;
        $recentInspections = (int) $inspectionStats->last_120_days;

        return [
            // ── Core identity ───────────────────────────────────────────
            'id' => $carrier->id,
            'row_id' => $carrier->row_id,
            'dot_number' => $dot,
            'company_name' => $carrier->legal_name,
            'dba_name' => $carrier->dba_name,
            'carrier_operation' => $carrier->carrier_operation,
            'mc_number' => $authority?->docket_number,
            'phone' => $carrier->telephone,
            'fax' => $carrier->fax,
            'email' => $carrier->email_address,
            'duns' => $detail?->dun_bradstreet_no,
            'crash_rate' => $detail?->recordable_crash_rate ?? 0,

            // ── Fleet & drivers ─────────────────────────────────────────
            'power_unit' => $detail?->power_units ?? $carrier->nbr_power_unit ?? 0,
            'driver_total' => $detail?->total_drivers ?? $carrier->driver_total ?? 0,

            // ── Mileage (as reported on the MCS-150) ────────────────────
            'mcs150_mileage' => $carrier->mcs150_mileage,
            'mcs150_mileage_year' => $carrier->mcs150_mileage_year,
            'recent_mileage' => $carrier->recent_mileage,
            'recent_mileage_year' => $carrier->recent_mileage_year,

            'recent_activity' => [
                'percentage' => $totalInspections > 0
                    ? round($recentInspections * 100 / $totalInspections, 1)
                    : 0,
                'recent_inspections' => $recentInspections,
                'total_inspections' => $totalInspections,
            ],

            // ── Addresses ───────────────────────────────────────────────
            'physical_address' => [
                'street' => $carrier->phy_street,
                'city' => $carrier->phy_city,
                'state' => $carrier->phy_state,
                'zip' => $carrier->phy_zip,
                'country' => $carrier->phy_country,
            ],
            'mailing_address' => [
                'street' => $carrier->mailing_street,
                'city' => $carrier->mailing_city,
                'state' => $carrier->mailing_state,
                'zip' => $carrier->mailing_zip,
                'country' => $carrier->mailing_country,
            ],

            // ── Inspection summary ──────────────────────────────────────
            'inspection_summary' => [
                'vehicle' => [
                    'inspections' => $vehicleInspTotal,
                    'oos_inspections' => $vehicleOosTotal,
                    'oos_pct' => $vehicleOosPct,
                ],
                'driver' => [
                    'inspections' => $driverInspTotal,
                    'oos_inspections' => $driverOosTotal,
                    'oos_pct' => $driverOosPct,
                ],
                'hazmat' => [
                    'inspections' => $hazmatInspTotal,
                    'oos_inspections' => $hazmatOosTotal,
                    'oos_pct' => $hazmatInspTotal > 0
                        ? round($hazmatOosTotal / $hazmatInspTotal * 100, 2)
                        : null,
                ],
            ],

            // ── Authority & contacts ────────────────────────────────────
            'authority' => $authority,
            'oos_orders' => $carrier->oosOrders,
            'authority_history' => $carrier->authorityHistory,

            'contacts' => $carrier->relationLoaded('contacts') ? $carrier->contacts : null,
            'contact_name_counts' => $carrier->relationLoaded('contacts')
                ? $carrier->contacts
                    ->groupBy('attn_to_or_title')
                    ->map(fn ($items, $name) => ['name' => $name, 'count' => $items->count()])
                    ->values()
                : null,
            'authority_orders' => $carrier->relationLoaded('authorityOrders') ? $carrier->authorityOrders : null,

            // ── SMS / risk ──────────────────────────────────────────────
            'sms_measures' => array_merge(
                $sms?->toArray() ?? [],
                ['risk_score' => round($riskScore, 2)]
            ),
            'risk_level' => match ($detail?->safety_rating) {
                'S' => 'Satisfactory',
                'C' => 'Conditional',
                'U' => 'Unsatisfactory',
                default => 'Not Rated',
            },

            // ── Recent activity samples ─────────────────────────────────
            // Full history is paginated behind its own endpoints; returning
            // every row here can mean tens of thousands of records.
            'inspections' => $this->recentInspections($dot),
            'violation_details' => $this->recentViolationDetails($dot),
            'crashes' => $this->recentCrashes($dot),
            'crash_details' => $this->recentCrashDetails($dot),

            // ── Insurance ───────────────────────────────────────────────
            'insurance_filings' => $insurance['filings'],
            'insurance_summary' => [
                'bipd_coverage_total_amount' => $insurance['bipd'],
                'cargo_insurance_total_amount' => $insurance['cargo'],
                'bond_total_amount' => $insurance['bond'],
            ],
            'insurance_filings_pending' => $carrier->relationLoaded('insuranceFilingsPending')
                ? $carrier->insuranceFilingsPending
                : null,

            // ── Company snapshot ────────────────────────────────────────
            'company_snapshot' => [
                'authorized_for_hire' => $carrier->authorized_for_hire,
                'exempt_for_hire' => $carrier->exempt_for_hire,
                'private_property' => $carrier->private_property,
                'private_passenger_business' => $carrier->private_passenger_business,
                'private_passenger_nonbusiness' => $carrier->private_passenger_nonbusiness,
                'migrant' => $carrier->migrant,
            ],

            'carrier_detail' => $detail,

            // ── Computed ────────────────────────────────────────────────
            'computed' => [
                'dot_age' => $this->dotAge($carrier->add_date ?? $detail?->add_date),
                'mcs150_year' => $this->year($carrier->mcs150_date),
                'status_code' => match (strtoupper($detail?->status_code ?? '')) {
                    'A' => 'Active',
                    'I' => 'Inactive',
                    default => null,
                },
                'authority_age_common' => $this->authorityAge($carrier, 'common'),
                'authority_age_contract' => $this->authorityAge($carrier, 'contract'),
                'authority_age_broker' => $this->authorityAge($carrier, 'broker'),
                'web_presence' => $this->webPresence($carrier->email_address),
                'email_domain' => $this->emailDomain($carrier->email_address),
                'snapshot_date' => now()->toDateString(),
                'out_of_service_flag' => $carrier->oosOrders->contains(
                    fn ($order) => strtolower($order->status ?? '') === 'active' && empty($order->rescind_date)
                ),
                'ownership_profile' => $this->ownershipProfile($detail),
                'cargo_carried' => $this->cargoCarried($detail) ?: null,
                'docket' => ($detail?->docket1prefix && $detail?->docket1)
                    ? $detail->docket1prefix.$detail->docket1
                    : null,
                'indicator_authority' => in_array('A', [
                    $authority?->common_stat,
                    $authority?->contract_stat,
                    $authority?->broker_stat,
                ], true),

                'observed_units' => $heavyValue((int) $inspectionStats->power_unit_vins),
                'observed_trailers' => $heavyValue((int) $inspectionStats->trailer_vins),
                'observed_units_status' => $heavyValue(((int) $inspectionStats->power_unit_vins) > 0 ? 'observed' : 'not_observed'),
                'observed_trailers_status' => $heavyValue(((int) $inspectionStats->trailer_vins) > 0 ? 'observed' : 'not_observed'),
                'inspected_power_units' => (int) $inspectionStats->ec2_power_units,
                'inspected_trailers' => (int) $inspectionStats->ec2_trailers,
                'inspected_states' => $heavyValue((int) $inspectionStats->states),

                'inspections_vehicle_out_of_service_pct' => $vehicleOosPct,
                'inspections_driver_out_of_service_pct' => $driverOosPct,
                'oos_alert_vehicle' => $vehicleInspTotal > 0
                    && ($vehicleOosTotal / $vehicleInspTotal) > self::NATIONAL_OOS_VEHICLE,
                'oos_alert_driver' => $driverInspTotal > 0
                    && ($driverOosTotal / $driverInspTotal) > self::NATIONAL_OOS_DRIVER,
                'basic_roadside_alert_unsafe_driving' => (float) ($sms?->unsafe_driv_measure ?? 0) > self::BASIC_ALERT_THRESHOLD,

                'last_inspection_date' => $this->date($inspectionStats->insp_last_date),
                'last_violation_date' => $heavyValue($this->date($violationStats->violation_last_date)),
                'last_crash_date' => $this->date($crashStats->crash_last_date),

                'crashes_total' => (int) $crashStats->crash_total,
                'crash_fatalities' => (int) $crashStats->fatalities,
                'crash_injuries' => (int) $crashStats->injuries,
                'crashes_tow_away' => (int) $crashStats->tow_away,
                'violations_total' => $heavyValue((int) $violationStats->violation_total),

                'preferred_lanes' => $this->preferredLanes($stateCounts),
                'state_inspection_counts' => $stateCounts->all(),
            ],
        ];
    }

    /**
     * Inspection, crash and violation aggregates in a single statement.
     *
     * Each subquery is an index range scan on dot_number and costs under a
     * millisecond server-side, whereas every extra statement costs a full
     * network round trip to the EC2 host — which dominates everything here.
     */
    protected function stats(string $dot, bool $heavy = false): object
    {
        $powerTypes = "'".implode("','", self::UNIT_TYPES_POWER)."'";
        $towedTypes = 'TRAILER|CHASSIS|DOLLY';
        $since = now()->subDays(120)->toDateString();

        // insp_date / report_date are varchars in the feed's own '24-APR-24'
        // format, so MAX() over them compares text ('31-AUG-19' beats
        // '01-JAN-25') and `>= '2026-04-20'` compares a date against a string
        // that never sorts the way it looks. Both need parsing in SQL.
        $inspDate = "STR_TO_DATE(insp_date, '%d-%b-%y')";
        $reportDate = "STR_TO_DATE(report_date, '%d-%b-%y')";

        if (! $heavy) {
            return DB::connection('external_db')->selectOne("
                SELECT i.*, c.*
                FROM
                    (SELECT
                        COUNT(*) AS insp_total,
                        MAX({$inspDate}) AS insp_last_date,
                        SUM(CASE WHEN {$inspDate} >= ? THEN 1 ELSE 0 END) AS last_120_days,
                        COALESCE(SUM(total_hazmat_sent), 0) AS hazmat_insp_total,
                        COALESCE(SUM(hazmat_oos_total), 0) AS hazmat_oos_total,
                        NULL AS states, NULL AS power_unit_vins, NULL AS trailer_vins,
                        NULL AS violation_total, NULL AS violation_last_date, NULL AS state_counts
                     FROM inspections WHERE dot_number = ?) i,

                    (SELECT
                        COUNT(*) AS crash_total,
                        MAX({$reportDate}) AS crash_last_date,
                        COALESCE(SUM(fatalities), 0) AS fatalities,
                        COALESCE(SUM(injuries), 0) AS injuries,
                        COALESCE(SUM(CASE WHEN tow_away THEN 1 ELSE 0 END), 0) AS tow_away
                     FROM crashes WHERE dot_number = ?) c
            ", [$since, $dot, $dot]);
        }

        return DB::connection('external_db')->selectOne("
            SELECT i.*, c.*, v.*, s.*
            FROM
                (SELECT
                    COUNT(*) AS insp_total,
                    MAX({$inspDate}) AS insp_last_date,
                    COUNT(DISTINCT county_code_state) AS states,
                    SUM(CASE WHEN {$inspDate} >= ? THEN 1 ELSE 0 END) AS last_120_days,
                    COALESCE(SUM(total_hazmat_sent), 0) AS hazmat_insp_total,
                    COALESCE(SUM(hazmat_oos_total), 0) AS hazmat_oos_total,
                    COUNT(DISTINCT CASE WHEN UPPER(unit_type_desc) IN ({$powerTypes})
                                     AND vin NOT REGEXP '^0+$' THEN vin END) AS power_unit_vins,
                    (SELECT COUNT(*) FROM (
                        SELECT vin AS v FROM inspections
                         WHERE dot_number = ? AND UPPER(unit_type_desc) REGEXP '{$towedTypes}' AND vin <> '' AND vin NOT REGEXP '^0+$'
                        UNION
                        SELECT vin2 AS v FROM inspections
                         WHERE dot_number = ? AND UPPER(unit_type_desc2) REGEXP '{$towedTypes}' AND vin2 <> '' AND vin2 NOT REGEXP '^0+$'
                    ) t) AS trailer_vins,
                    -- These two matched 'truck' / 'tractor', which the feed
                    -- never writes — it uses 'TRUCK TRACTOR', 'STRAIGHT TRUCK'
                    -- and so on — so both always came back zero.
                    (SELECT COUNT(DISTINCT vin) FROM inspections
                      WHERE dot_number = ?
                        AND UPPER(unit_type_desc) IN ({$powerTypes}) AND vin <> '') AS ec2_power_units,
                    (SELECT COUNT(DISTINCT vin) FROM inspections
                      WHERE dot_number = ?
                        AND UPPER(unit_type_desc) REGEXP '{$towedTypes}' AND vin <> '') AS ec2_trailers
                 FROM inspections WHERE dot_number = ?) i,

                (SELECT
                    COUNT(*) AS crash_total,
                    MAX({$reportDate}) AS crash_last_date,
                    COALESCE(SUM(fatalities), 0) AS fatalities,
                    COALESCE(SUM(injuries), 0) AS injuries,
                    COALESCE(SUM(CASE WHEN tow_away THEN 1 ELSE 0 END), 0) AS tow_away
                 FROM crashes WHERE dot_number = ?) c,

                (SELECT
                    COUNT(*) AS violation_total,
                    MAX({$inspDate}) AS violation_last_date
                 FROM violation_details WHERE dot_number = ?) v,

                -- Per-state breakdown folded in as JSON rather than paying
                -- another round trip for a GROUP BY.
                (SELECT JSON_OBJECTAGG(state, total) AS state_counts FROM (
                    SELECT county_code_state AS state, COUNT(*) AS total
                    FROM inspections
                    WHERE dot_number = ? AND county_code_state IS NOT NULL
                    GROUP BY county_code_state
                    ORDER BY total DESC
                ) grouped) s
        ", [$since, $dot, $dot, $dot, $dot, $dot, $dot, $dot, $dot]);
    }

    protected function recentInspections(string $dot)
    {
        return Inspection::query()
            ->where('dot_number', $dot)
            // Nested exactly as the carrier platform returns them.
            ->with(['units', 'citations', 'violationDetails'])
            ->orderByDesc('insp_date')
            ->limit($this->limit())
            ->get()
            ->map(function ($inspection) {
                $data = $inspection->toArray();

                $data['insp_date'] = $this->date($inspection->insp_date);

                return $data;
            });
    }

    protected function recentCrashes(string $dot)
    {
        return Crash::query()
            ->where('dot_number', $dot)
            ->with('detail')
            ->orderByDesc('report_date')
            ->limit($this->limit())
            ->get();
    }

    protected function recentCrashDetails(string $dot)
    {
        return CrashDetail::query()
            ->where('dot_number', $dot)
            ->limit($this->limit())
            ->get();
    }

    protected function recentViolationDetails(string $dot)
    {
        return ViolationDetail::query()
            ->where('dot_number', $dot)
            ->orderByDesc('insp_date')
            ->limit($this->limit())
            ->get();
    }

    /**
     * How many rows each child collection returns. The platform returns every
     * row; a carrier with 15k inspections makes that unusable over the wire,
     * so it is capped — the true totals are in `computed`.
     */
    protected function limit(): int
    {
        return (int) config('carriers.recent_limit', self::RECENT_LIMIT);
    }

    /**
     * Insurance filings are per-carrier and few, so they are loaded in full.
     */
    protected function insurance(Carrier $carrier): array
    {
        $filings = $carrier->insuranceFilings()
            ->orderByDesc('effective_date')
            ->get();

        $latestAmount = function (string $type) use ($filings) {
            $filing = $filings->first(
                fn ($item) => stripos($item->ins_type_desc ?? '', $type) !== false
            );

            return $filing ? (float) $filing->max_cov_amount * 1000 : 0;
        };

        $bond = $latestAmount('Surety') ?: $latestAmount('Bond');

        return [
            'bipd' => $latestAmount('BIPD'),
            'cargo' => $latestAmount('Cargo'),
            'bond' => $bond,
            'filings' => $filings->map(fn ($item) => [
                'id' => $item->id,
                'dot_number' => $item->dot_number,
                'docket_number' => $item->docket_number,
                'effective_date' => $this->date($item->effective_date),
                'cancl_effective_date' => $this->date($item->cancl_effective_date),
                'ins_form_code' => $item->ins_form_code,
                'ins_type_desc' => $item->ins_type_desc,
                'ins_class_code' => $item->ins_class_code,
                'policy_no' => $item->policy_no,
                'name_company' => $item->name_company,
                'cancl_method' => $item->cancl_method,
                'min_cov_amount' => number_format((float) $item->min_cov_amount * 1000, 2, '.', ''),
                'max_cov_amount' => number_format((float) $item->max_cov_amount * 1000, 2, '.', ''),
                'underl_lim_amount' => number_format((float) $item->underl_lim_amount * 1000, 2, '.', ''),
            ]),
        ];
    }

    protected function preferredLanes($stateCounts)
    {
        $total = $stateCounts->sum();

        return $stateCounts->take(6)->map(fn ($count, $state) => [
            'state' => $state,
            'inspection_count' => (int) $count,
            'percentage' => $total > 0 ? round($count / $total * 100) : 0,
        ])->values();
    }

    /**
     * @param  string  $type  a key from Fmcsa::AUTHORITY_TYPES
     *
     * carrier_authority_history has no `mod_col_1` column — the authority type
     * is `op_auth_type`, and it holds either the long FMCSA description or a
     * short form depending on the row.
     */
    protected function authorityAge(Carrier $carrier, string $type): ?int
    {
        $types = Fmcsa::authorityType($type);

        $granted = $carrier->authorityHistory
            ->filter(fn ($h) => in_array(strtoupper((string) $h->op_auth_type), $types, true)
                && strtoupper((string) $h->original_action_desc) === 'GRANTED')
            // These dates are '24-APR-24' strings; sorting them as text picks
            // the wrong row.
            ->sortBy(fn ($h) => Fmcsa::dateKey($h->orig_served_date) ?: PHP_INT_MAX)
            ->first();

        $served = Fmcsa::date($granted?->orig_served_date);

        return $served ? (int) $served->diffInYears(now()) : null;
    }

    /**
     * FMCSA writes dates like 01-JUN-74, which Carbon cannot parse directly.
     */
    protected function dotAge(?string $addDate): ?int
    {
        if (! $addDate) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($addDate))) {
                $year = substr($addDate, -2);
                $century = $year > date('y') ? '19' : '20';
                $addDate = substr($addDate, 0, -2).$century.$year;

                return (int) Carbon::createFromFormat('d-M-Y', strtoupper($addDate))->diffInYears(now());
            }

            return (int) Carbon::parse($addDate)->diffInYears(now());
        } catch (\Throwable) {
            return null;
        }
    }

    protected function ownershipProfile($detail): string
    {
        $owned = (int) ($detail?->owntruck ?? 0) + (int) ($detail?->owntract ?? 0);
        $terminal = (int) ($detail?->trmtruck ?? 0) + (int) ($detail?->trmtract ?? 0);
        $trip = (int) ($detail?->trptruck ?? 0) + (int) ($detail?->trptract ?? 0);

        return match (true) {
            $owned > ($terminal + $trip) => 'owned_fleet',
            $trip > 0 => 'trip_leased',
            default => 'terminal_leased',
        };
    }

    protected function cargoCarried($detail): string
    {
        if (! $detail) {
            return '';
        }

        $cargo = collect(self::CARGO_MAP)
            ->filter(fn ($label, $column) => ! empty($detail->{$column}))
            ->values();

        if (! empty($detail->crgo_cargoothr)) {
            $cargo->push($detail->crgo_cargoothr_desc ?? 'Other');
        }

        return $cargo->implode(', ');
    }

    protected function emailDomain(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        return strtolower(trim(explode('@', $email)[1])) ?: null;
    }

    /**
     * A carrier on a free mail provider has no inferable website.
     */
    protected function webPresence(?string $email): ?string
    {
        $domain = $this->emailDomain($email);

        return $domain && ! in_array($domain, self::FREE_EMAIL_PROVIDERS, true)
            ? 'https://'.$domain
            : null;
    }

    protected function date($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function year($value): ?int
    {
        if (! $value) {
            return null;
        }

        try {
            return (int) Carbon::parse($value)->year;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Live FMCSA snapshot. Cached for a day and never allowed to break the
     * profile response — the upstream service is slow and often unavailable.
     */
    protected function fmcsaSnapshot(string $dot): ?array
    {
        $webKey = config('carriers.fmcsa_web_key');

        if (! $webKey) {
            return null;
        }

        return Cache::remember("carrier:fmcsa:{$dot}", (int) config('carriers.fmcsa_cache_ttl', 86400), function () use ($dot, $webKey) {
            try {
                $response = Http::timeout((int) config('carriers.fmcsa_timeout', 8))
                    ->acceptJson()
                    ->get("https://mobile.fmcsa.dot.gov/qc/services/carriers/{$dot}", ['webKey' => $webKey]);

                return $response->successful()
                    ? ($response->json()['content']['carrier'] ?? null)
                    : null;
            } catch (\Throwable $e) {
                Log::warning('FMCSA lookup failed', ['dot' => $dot, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Reduce a payload to scalars and arrays so it survives the cache
     * round trip. Mirrors exactly what the API serialises to JSON anyway.
     */
    protected function toPlainArray(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return json_decode(json_encode($payload), true);
    }
}
