<?php

namespace App\Services\Carrier;

use App\Models\Carriers\Carrier;
use App\Models\Carriers\CarrierDetail;
use App\Models\Carriers\SmsMeasure;
use App\Support\Fmcsa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Risk factors and the reliability (strengths / weaknesses) view derived from
 * them, ported from the carrier platform's own scoring.
 *
 * Where the original loads whole relations into memory to test a condition,
 * this asks the database instead — a carrier with 15k inspections should not
 * hydrate 15k models to answer "any inspection in the last 12 months?".
 */
class CarrierRiskService
{
    protected const META = [
        'active_usdot_status' => ['authority', true,  'Active USDOT registration', 'USDOT registration inactive', 'critical', true],
        'no_active_authority' => ['authority', false, 'Holds active operating authority', 'No active operating authority', 'critical', true],
        'not_authorized_for_hire' => ['authority', false, 'Authorized for hire', 'Not authorized for-hire', 'critical', true],
        'dot_out_of_service' => ['authority', false, 'No out-of-service orders', 'Under federal out-of-service order', 'critical', true],
        'consecutive_authority' => ['authority', true,  'Uninterrupted authority history', 'Authority has lapsed or been interrupted', 'warning', true],
        'revocation_last_thirtysix_mo' => ['authority', false, 'No revocations in last 36 months', 'Authority revoked within last 36 months', 'critical', true],
        'sixty_mo_in_business' => ['authority', true,  '5+ years in business', 'In business under 5 years', 'info', true],
        'mcs150_filed_last_24_months' => ['authority', true,  'MCS-150 filing current', 'MCS-150 filing overdue (24+ months)', 'warning', true],
        'boc3_on_file' => ['authority', true,  'BOC-3 process agent on file', 'No BOC-3 process agent on file', 'warning', true],
        'carrier_w_brokerage_authority' => ['identity',  false, null, 'Holds both carrier and broker authority (double-broker risk)', 'warning', true],
        'safety_rating_unsatisfactory_conditional' => ['safety', false, 'Satisfactory safety rating', 'Unsatisfactory or Conditional safety rating', 'critical', true],
        'basic_alert_flag' => ['safety', false, 'No BASIC alerts', 'BASIC score above alert threshold', 'warning', true],
        'basic_ac_indicator_flag' => ['safety', false, 'No acute/critical violations', 'Acute/Critical violation on record', 'critical', true], // LIVE: sms_measures.*_ac
        'violations_severe_flag' => ['safety', false, 'No severe violations', 'Severe violations on record', 'warning', true],
        'fatal_crashes_flag' => ['safety', false, 'No fatal crashes', 'Fatal crash on record', 'critical', true],
        'oos_below_industry_average' => ['safety', true,  'Out-of-service rate below industry average', 'Out-of-service rate above industry average', 'warning', true],
        'zero_inspections_last_twelve_mo' => ['safety', false, 'Inspection activity in last 12 months', 'No inspections in last 12 months', 'warning', true],
        'driver_only_inspections' => ['safety', false, null, 'Inspections predominantly driver-only', 'info', true],
        'interstate_carrier_single_state_inspection' => ['operations', false, null, 'Interstate authority but inspected in only one state', 'warning', true],
        'bipd_insurance_above_minimum' => ['insurance', true,  'Liability coverage above required minimum', null, null, true],
        'bipd_insurance_below_requirement' => ['insurance', false, null, 'Liability coverage below federal requirement', 'critical', true],
        'cargo_insurance_on_file' => ['insurance', true,  'Cargo insurance on file', 'No cargo insurance on file', 'warning', true],
        'pending_insurance_cancellation' => ['insurance', false, 'No pending insurance cancellation', 'Insurance cancellation pending', 'critical', true],
        'ins_rrg_active' => ['insurance', false, null, 'Insured through a Risk Retention Group', 'info', true],
        'ins_rrg_hist' => ['insurance', false, null, 'Historic Risk Retention Group insurance', 'info', true],
        'stability_insurance_history' => ['insurance', true,  'Stable insurance history', 'Frequent insurance cancellations or lapses', 'warning', true],
        'indicator_network_graph_phone' => ['identity', false, 'Phone unique to this carrier', 'Phone shared with other carriers', 'warning', true],
        'indicator_network_graph_address' => ['identity', false, 'Address unique to this carrier', 'Address shared with other carriers', 'warning', true],
        'indicator_network_graph_email' => ['identity', false, 'Email unique to this carrier', 'Email shared with other carriers', 'warning', true],
        'indicator_network_graph_ein' => ['identity', false, null, 'EIN shared with other carriers', 'critical', false], // no EIN column yet
        'indicator_network_graph_duns' => ['identity', false, null, 'DUNS shared with other carriers', 'warning', true],  // carrier_details.dun_bradstreet_no
        'high_shared_power_units' => ['identity', false, null, 'Equipment (VINs) shared across carriers', 'warning', true],
        'virtual_physical_mailing_address' => ['identity', false, 'Address verified as non-virtual', 'Address matches a mail-drop service', 'warning', true],
        'phone_number_area_codes_match_address_state' => ['identity', true, 'Phone area code matches business state', "Area code doesn't match business state", 'info', true],
        'primary_contact_info_missing' => ['identity', false, 'Primary contact info complete', 'Primary contact information missing', 'warning', true],
        'secondary_contact_info_provided' => ['identity', true,  'Secondary contact on file', null, null, true],
        'indicator_benchmark_inspected_power_units_ratio' => ['operations', false, null, 'Inspected units inconsistent with fleet size', 'warning', true],
        'indicator_benchmark_inspection_mileage_ratio' => ['operations', false, null, 'Inspections inconsistent with mileage', 'warning', true],
        'indicator_benchmark_power_unit_mileage_ratio' => ['operations', false, null, 'Fleet size inconsistent with mileage', 'warning', true],
        'multi_cargo_classification' => ['operations', false, null, 'Unusually broad cargo classifications', 'info', true],
        'hazardous_material' => ['operations', true, 'Hazmat-authorized', null, null, true],
        'phmsa_flag' => ['operations', true, 'PHMSA hazmat registration', null, null, false],
        'smartway_flag' => ['operations', true, 'EPA SmartWay partner', null, null, false],
        'carbtru_flag' => ['operations', true, 'CARB TRU compliant', null, null, false],
        'stability_name_history' => ['stability', true, 'Legal name stable', 'Frequent legal name changes', 'warning', false],
        'stability_email_history' => ['stability', true, 'Email stable', 'Frequent email changes', 'warning', false],
        'stability_phone_history' => ['stability', true, 'Phone number stable', 'Frequent phone changes', 'warning', false],
        'stability_address_history' => ['stability', true, 'Address stable', 'Frequent address changes', 'warning', false],
        'stability_contact_history' => ['stability', true, 'Contact details stable', 'Frequent contact changes', 'warning', false],
    ];

    protected const MAIL_DROP_PATTERNS = [
        'UPS STORE', 'REGUS', 'WEWORK', 'PMB ', 'POSTAL ANNEX',
        'MAIL BOXES ETC', 'MAILBOX', 'REGISTERED AGENT', 'VIRTUAL OFFICE', 'SUITE #',
    ];

    protected const AREA_CODES = [
        'AL' => '205,251,256,334,659,938', 'AK' => '907', 'AZ' => '480,520,602,623,928', 'AR' => '479,501,870',
        'CA' => '209,213,279,310,323,341,350,408,415,424,442,510,530,559,562,619,626,628,650,657,661,669,707,714,747,760,805,818,820,831,840,858,909,916,925,949,951',
        'CO' => '303,719,720,970,983', 'CT' => '203,475,860,959', 'DE' => '302', 'DC' => '202,771',
        'FL' => '239,305,321,352,386,407,448,561,656,689,727,754,772,786,813,850,863,904,941,954',
        'GA' => '229,404,470,478,678,706,762,770,912,943', 'HI' => '808', 'ID' => '208,986',
        'IL' => '217,224,309,312,331,447,464,618,630,708,773,779,815,847,872', 'IN' => '219,260,317,463,574,765,812,930',
        'IA' => '319,515,563,641,712', 'KS' => '316,620,785,913', 'KY' => '270,364,502,606,859',
        'LA' => '225,318,337,504,985', 'ME' => '207', 'MD' => '240,301,410,443,667',
        'MA' => '339,351,413,508,617,774,781,857,978', 'MI' => '231,248,269,313,517,586,616,679,734,810,906,947,989',
        'MN' => '218,320,507,612,651,763,952', 'MS' => '228,601,662,769', 'MO' => '314,417,557,573,636,660,816,975',
        'MT' => '406', 'NE' => '308,402,531', 'NV' => '702,725,775', 'NH' => '603',
        'NJ' => '201,551,609,640,732,848,856,862,908,973', 'NM' => '505,575',
        'NY' => '212,315,332,347,363,516,518,585,607,631,646,680,716,718,838,845,914,917,929,934',
        'NC' => '252,336,472,704,743,828,910,919,980,984', 'ND' => '701',
        'OH' => '216,220,234,283,326,330,380,419,440,513,567,614,740,937', 'OK' => '405,539,572,580,918',
        'OR' => '458,503,541,971', 'PA' => '215,223,267,272,412,445,484,570,582,610,717,724,814,835,878',
        'RI' => '401', 'SC' => '803,821,839,843,854,864', 'SD' => '605',
        'TN' => '423,615,629,731,865,901,931', 'TX' => '210,214,254,281,325,346,361,409,430,432,469,512,682,713,726,737,806,817,830,832,903,915,936,940,945,956,972,979',
        'UT' => '385,435,801', 'VT' => '802', 'VA' => '276,434,540,571,703,757,804,826,948',
        'WA' => '206,253,360,425,509,564', 'WV' => '304,681', 'WI' => '262,274,414,534,608,715,920', 'WY' => '307',
    ];

    /**
     * Every factor the platform scores. Booleans where the answer is known,
     * null where the source data does not exist yet (those land in
     * "monitoring" rather than counting as a pass).
     */
    protected function computeFactors(string $dot): ?array
    {
        $carrier = Carrier::query()->where('dot_number', $dot)->first();

        if (! $carrier) {
            return null;
        }

        $detail = CarrierDetail::query()->where('dot_number', $dot)->first();
        $sms = SmsMeasure::query()->where('dot_number', $dot)->first();

        $authority = $this->authorityFlags($dot);
        $insurance = $this->insuranceFlags($dot, $authority['bipd_required']);
        $activity = $this->activityFlags($dot);
        $network = $this->networkFlags($carrier, $detail, $dot);
        $benchmarks = $this->benchmarks();

        $powerUnits = (int) ($carrier->nbr_power_unit ?? 0);
        $mileage = (int) ($carrier->mcs150_mileage ?: 0);

        $addDate = $this->parseFmcsaDate($carrier->add_date);
        $mcs150Date = $this->parseFmcsaDate($carrier->mcs150_date);

        // BASIC alert: any measure at or above the national 90th percentile.
        $basicAlert = false;

        if ($sms) {
            foreach ([
                'unsafe_driv_measure', 'hos_driv_measure', 'driv_fit_measure',
                'contr_subst_measure', 'veh_maint_measure',
            ] as $measure) {
                $p90 = $benchmarks["p90_{$measure}"] ?? null;

                if ($sms->$measure !== null && $p90 !== null && (float) $sms->$measure >= $p90) {
                    $basicAlert = true;
                    break;
                }
            }
        }

        $oosBelowAverage = null;

        if (($sms?->vehicle_insp_total ?? 0) >= 5) {
            $oosBelowAverage = ($sms->vehicle_oos_insp_total / max(1, $sms->vehicle_insp_total))
                < $benchmarks['natl_vehicle_oos'];
        }

        // Benchmark ratios — null when the inputs are missing, so the factor
        // shows as monitoring instead of a false pass.
        $inspectedPowerUnitRatio = ($powerUnits > 0 && $activity['inspected_units'] > 0)
            ? ($activity['inspected_units'] / $powerUnits) > 3.0
            : null;

        $inspectionMileageRatio = null;

        if ($mileage > 0 && ($sms?->insp_total ?? 0) > 0 && $benchmarks['im_lo'] !== null) {
            $ratio = $sms->insp_total / $mileage;
            $inspectionMileageRatio = $ratio < $benchmarks['im_lo'] || $ratio > $benchmarks['im_hi'];
        }

        $powerUnitMileageRatio = null;

        if ($mileage > 0 && $powerUnits > 0 && $benchmarks['pum_lo'] !== null) {
            $ratio = $powerUnits / $mileage;
            $powerUnitMileageRatio = $ratio < $benchmarks['pum_lo'] || $ratio > $benchmarks['pum_hi'];
        }

        $street = strtoupper(($carrier->phy_street ?? '').' | '.($carrier->mailing_street ?? ''));

        $cargoCount = collect(self::CARGO_FIELDS)
            ->filter(fn ($field) => strtoupper($detail?->$field ?? '') === 'X')
            ->count();

        return [
            // authority
            'active_usdot_status' => $detail ? ($detail->status_code === 'A') : null,
            'no_active_authority' => ! $authority['has_active'],
            // Stored as the strings 'true' / 'false' since the Motus load, so
            // the old 'Y'/'X'/'1' check flagged every carrier.
            'not_authorized_for_hire' => ! Fmcsa::flag($carrier->authorized_for_hire),
            'dot_out_of_service' => $authority['out_of_service'],
            'consecutive_authority' => $authority['consecutive'],
            'revocation_last_thirtysix_mo' => $authority['revocation_last_36mo'],
            'sixty_mo_in_business' => $addDate ? $addDate->lte(now()->subMonths(60)) : null,
            'carrier_w_brokerage_authority' => $authority['has_broker'] && $authority['has_carrier'],
            'mcs150_filed_last_24_months' => $mcs150Date ? $mcs150Date->gt(now()->subMonths(24)) : null,

            // safety
            'safety_rating_unsatisfactory_conditional' => $detail
                ? in_array($detail->safety_rating, ['U', 'C', 'UNSATISFACTORY', 'CONDITIONAL'], true)
                : null,
            'basic_alert_flag' => $basicAlert,
            'basic_ac_indicator_flag' => $sms
                ? ((bool) $sms->unsafe_driv_ac || (bool) $sms->hos_driv_ac || (bool) $sms->driv_fit_ac
                    || (bool) $sms->contr_subst_ac || (bool) $sms->veh_maint_ac)
                : null,
            'violations_severe_flag' => $activity['severe_violations'],
            'fatal_crashes_flag' => $activity['fatal_crashes'],
            'oos_below_industry_average' => $oosBelowAverage,
            'zero_inspections_last_twelve_mo' => $activity['zero_inspections_12mo'],
            'driver_only_inspections' => $activity['driver_only'],

            // operations
            'interstate_carrier_single_state_inspection' => $carrier->carrier_operation === 'A'
                && $activity['inspected_states'] === 1,
            'indicator_benchmark_inspected_power_units_ratio' => $inspectedPowerUnitRatio,
            'indicator_benchmark_inspection_mileage_ratio' => $inspectionMileageRatio,
            'indicator_benchmark_power_unit_mileage_ratio' => $powerUnitMileageRatio,
            'multi_cargo_classification' => $cargoCount > 3,
            'hazardous_material' => Fmcsa::flag($carrier->hm_flag) || Fmcsa::flag($detail?->hm_ind),
            'phmsa_flag' => null,
            'smartway_flag' => null,
            'carbtru_flag' => null,

            // insurance
            'bipd_insurance_above_minimum' => $insurance['bipd_on_file'] !== null
                && $insurance['bipd_on_file'] > $authority['bipd_required'],
            'bipd_insurance_below_requirement' => $authority['has_active']
                && ($insurance['bipd_on_file'] ?? 0) < $authority['bipd_required'],
            'cargo_insurance_on_file' => $insurance['cargo_on_file'],
            'pending_insurance_cancellation' => $insurance['pending_cancellation'],
            'ins_rrg_active' => $insurance['rrg_active'],
            'ins_rrg_hist' => $insurance['rrg_history'],
            'stability_insurance_history' => $insurance['stable_history'],
            'boc3_on_file' => $network['boc3_on_file'],

            // identity / network
            'indicator_network_graph_phone' => $network['phone'],
            'indicator_network_graph_address' => $network['address'],
            'indicator_network_graph_email' => $network['email'],
            'indicator_network_graph_duns' => $network['duns'],
            'indicator_network_graph_ein' => null,
            'high_shared_power_units' => $network['shared_vins'],
            'virtual_physical_mailing_address' => collect(self::MAIL_DROP_PATTERNS)
                ->contains(fn ($pattern) => str_contains($street, $pattern)),
            'phone_number_area_codes_match_address_state' => $this->areaCodeMatches($carrier),
            'primary_contact_info_missing' => empty($detail?->company_officer_1) || empty($carrier->email_address),
            'secondary_contact_info_provided' => $detail
                ? (! empty($detail->company_officer_2) || ! empty($detail->cell_phone))
                : null,

            // not yet sourced
            'stability_name_history' => null,
            'stability_email_history' => null,
            'stability_phone_history' => null,
            'stability_address_history' => null,
            'stability_contact_history' => null,
        ];
    }

    /** Cached risk factors for a carrier. */
    public function factors(string $dot): ?array
    {
        return Cache::remember(
            "carrier:risk:{$dot}",
            (int) config('carriers.profile_cache_ttl', 900),
            fn () => $this->computeFactors($dot)
        );
    }

    /**
     * Strengths / weaknesses / monitoring view over the factor set.
     */
    public function reliability(string $dot): ?array
    {
        $factors = $this->factors($dot);

        if ($factors === null) {
            return null;
        }

        $strengths = $weaknesses = $monitoring = [];

        foreach (self::META as $key => [$category, $good, $strengthLabel, $weaknessLabel, $severity, $live]) {
            $value = $factors[$key] ?? null;

            // Not yet computable (no source column) — surfaced as "monitoring"
            // so the UI can show it as pending rather than passing.
            if (! $live || $value === null) {
                $monitoring[] = [
                    'key' => $key,
                    'label' => $strengthLabel ?? $weaknessLabel,
                    'category' => $category,
                ];

                continue;
            }

            if ($value === $good) {
                if ($strengthLabel !== null) {
                    $strengths[] = [
                        'key' => $key,
                        'label' => $strengthLabel,
                        'category' => $category,
                        'value' => $value,
                    ];
                }
            } elseif ($weaknessLabel !== null) {
                $weaknesses[] = [
                    'key' => $key,
                    'label' => $weaknessLabel,
                    'category' => $category,
                    'severity' => $severity,
                    'value' => $value,
                ];
            }
        }

        $rank = ['critical' => 1, 'warning' => 2, 'info' => 3];

        usort($weaknesses, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return [
            'dot_number' => $dot,
            'factors' => $factors,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'monitoring' => $monitoring,
            'summary' => [
                'strength_count' => count($strengths),
                'weakness_count' => count($weaknesses),
                'critical_count' => count(array_filter($weaknesses, fn ($w) => $w['severity'] === 'critical')),
                'monitoring_count' => count($monitoring),
            ],
        ];
    }

    /** VINs sampled for the equipment-sharing signal. */
    protected const VIN_SAMPLE = 200;

    protected const CARGO_FIELDS = [
        'crgo_genfreight', 'crgo_household', 'crgo_metalsheet', 'crgo_motoveh', 'crgo_drivetow',
        'crgo_logpole', 'crgo_bldgmat', 'crgo_mobilehome', 'crgo_machlrg', 'crgo_produce', 'crgo_liqgas',
        'crgo_intermodal', 'crgo_oilfield', 'crgo_livestock', 'crgo_grainfeed', 'crgo_coalcoke', 'crgo_meat',
        'crgo_garbage', 'crgo_usmail', 'crgo_chem', 'crgo_drybulk', 'crgo_coldfood', 'crgo_beverages',
        'crgo_paperprod', 'crgo_utility', 'crgo_farmsupp', 'crgo_construct', 'crgo_waterwell',
    ];

    /**
     * Authority, revocation and out-of-service state in one round trip.
     */
    protected function authorityFlags(string $dot): array
    {
        $row = DB::connection('external_db')->selectOne("
            SELECT
                (SELECT COUNT(*) FROM carrier_authorities
                  WHERE dot_number = ? AND (common_stat = 'A' OR contract_stat = 'A' OR broker_stat = 'A')) AS active_any,
                (SELECT COUNT(*) FROM carrier_authorities WHERE dot_number = ? AND broker_stat = 'A') AS active_broker,
                (SELECT COUNT(*) FROM carrier_authorities
                  WHERE dot_number = ? AND (common_stat = 'A' OR contract_stat = 'A')) AS active_carrier,
                (SELECT min_cov_amount FROM carrier_authorities WHERE dot_number = ? ORDER BY id DESC LIMIT 1) AS min_cov_amount,
                (SELECT COUNT(*) FROM carrier_authority_orders WHERE dot_number = ?) AS order_count,
                (SELECT COUNT(*) FROM carrier_oos_orders WHERE dot_number = ? AND (rescind_date IS NULL OR rescind_date = '')) AS active_oos,
                (SELECT COUNT(*) FROM carrier_contacts WHERE dot_number = ?) AS contact_count
        ", array_fill(0, 7, $dot));

        $hasActive = (int) $row->active_any > 0;

        // Revocation dates are FMCSA-formatted strings, so the 36-month test
        // still has to happen in PHP — but only over the order rows.
        $revocation = false;

        if ((int) $row->order_count > 0) {
            $orders = DB::connection('external_db')
                ->table('carrier_authority_orders')
                ->where('dot_number', $dot)
                ->pluck('order2_effective_date');

            $revocation = $orders->contains(function ($date) {
                $parsed = $this->parseFmcsaDate($date);

                return $parsed && $parsed->gt(now()->subMonths(36));
            });
        }

        return [
            'has_active' => $hasActive,
            'has_broker' => (int) $row->active_broker > 0,
            'has_carrier' => (int) $row->active_carrier > 0,
            'bipd_required' => $row->min_cov_amount !== null ? (float) $row->min_cov_amount : 750000.0,
            'consecutive' => (int) $row->order_count === 0 && $hasActive,
            'revocation_last_36mo' => $revocation,
            'out_of_service' => (int) $row->active_oos > 0,
            'boc3_on_file' => (int) $row->contact_count > 0,
        ];
    }

    protected function insuranceFlags(string $dot, float $bipdRequired): array
    {
        $filings = DB::connection('external_db')
            ->table('insurance_filings')
            ->where('dot_number', $dot)
            ->get(['ins_form_code', 'ins_type_desc', 'ins_type_ind', 'max_cov_amount', 'cancl_effective_date']);

        $history = DB::connection('external_db')
            ->table('insurance_filings_history')
            ->where('dot_number', $dot)
            ->get(['ins_type_desc', 'cancl_effective_date']);

        $liveOrFuture = function ($filing) {
            if (empty($filing->cancl_effective_date)) {
                return true;
            }

            $date = $this->parseFmcsaDate($filing->cancl_effective_date);

            return $date && $date->isFuture();
        };

        // Form codes in the feed: 91 / 91X are BIPD (ins_type_desc
        // 'BIPD/Primary', 'BIPD/Excess'), 34 is cargo. These two had it
        // backwards — BIPD explicitly excluded 91X, the code nearly every BIPD
        // filing carries, while cargo matched 91X and so read BIPD filings.
        $isBipd = function ($f) {
            $code = strtoupper(trim((string) ($f->ins_form_code ?? '')));

            return in_array($code, ['91', '91X'], true)
                || str_starts_with(strtoupper((string) ($f->ins_type_desc ?? '')), 'BIPD');
        };

        $isCargo = function ($f) {
            $code = strtoupper(trim((string) ($f->ins_form_code ?? '')));

            return $code === '34'
                || str_contains(strtoupper((string) ($f->ins_type_desc ?? '')), 'CARGO');
        };

        $bipd = $filings
            ->filter($isBipd)
            ->filter($liveOrFuture)
            ->max('max_cov_amount');

        return [
            'bipd_on_file' => $bipd !== null ? (float) $bipd : null,
            'cargo_on_file' => $filings
                ->filter($isCargo)
                ->contains($liveOrFuture),
            'pending_cancellation' => $filings->contains(function ($f) {
                if (empty($f->cancl_effective_date)) {
                    return false;
                }

                $date = $this->parseFmcsaDate($f->cancl_effective_date);

                return $date && $date->isFuture();
            }),
            'rrg_active' => $filings
                ->filter(fn ($f) => str_contains(strtoupper($f->ins_type_desc ?? ''), 'RISK RETENTION')
                    || strtoupper($f->ins_type_ind ?? '') === 'RRG')
                ->contains($liveOrFuture),
            'rrg_history' => $history->contains(
                fn ($h) => str_contains(strtoupper($h->ins_type_desc ?? ''), 'RISK RETENTION')
            ),
            'stable_history' => ! $history->contains(function ($h) {
                if (empty($h->cancl_effective_date)) {
                    return false;
                }

                $date = $this->parseFmcsaDate($h->cancl_effective_date);

                return $date && $date->gt(now()->subMonths(36));
            }),
        ];
    }

    /**
     * Inspection, violation and crash conditions as SQL predicates. The
     * original pulled every inspection row for this.
     */
    protected function activityFlags(string $dot): array
    {
        $row = DB::connection('external_db')->selectOne("
            SELECT
                (SELECT COUNT(*) FROM inspections WHERE dot_number = ? AND insp_date >= ?) AS insp_12mo,
                (SELECT COUNT(DISTINCT county_code_state) FROM inspections WHERE dot_number = ?) AS states,
                (SELECT COUNT(DISTINCT vin) FROM inspections WHERE dot_number = ? AND vin IS NOT NULL AND vin <> '') AS inspected_units,
                (SELECT COUNT(*) FROM inspections WHERE dot_number = ? AND insp_date >= ?) AS insp_24mo,
                (SELECT COUNT(*) FROM inspections WHERE dot_number = ? AND insp_date >= ? AND insp_level_id = 3) AS insp_24mo_lvl3,
                (SELECT COUNT(*) FROM violation_details WHERE dot_number = ? AND severity_weight >= 8) AS severe_violations,
                (SELECT COUNT(*) FROM crashes WHERE dot_number = ? AND fatalities > 0) AS fatal_crashes
        ", [
            $dot, now()->subMonths(12)->toDateString(),
            $dot,
            $dot,
            $dot, now()->subMonths(24)->toDateString(),
            $dot, now()->subMonths(24)->toDateString(),
            $dot,
            $dot,
        ]);

        $insp24mo = (int) $row->insp_24mo;

        return [
            'zero_inspections_12mo' => (int) $row->insp_12mo === 0,
            'inspected_states' => (int) $row->states,
            'inspected_units' => (int) $row->inspected_units,
            'severe_violations' => (int) $row->severe_violations > 0,
            'fatal_crashes' => (int) $row->fatal_crashes > 0,
            // Needs a meaningful sample before the ratio says anything.
            'driver_only' => $insp24mo >= 5
                ? ((int) $row->insp_24mo_lvl3 / $insp24mo) > 0.8
                : null,
        ];
    }

    /**
     * Shared identifiers across carriers — the double-brokering signals.
     */
    protected function networkFlags(Carrier $carrier, ?CarrierDetail $detail, string $dot): array
    {
        $row = DB::connection('external_db')->selectOne("
            SELECT
                (SELECT COUNT(*) FROM carriers WHERE telephone = ? AND telephone <> '' AND dot_number <> ?) AS phone,
                (SELECT COUNT(*) FROM carriers WHERE email_address = ? AND email_address <> '' AND dot_number <> ?) AS email,
                (SELECT COUNT(*) FROM carriers
                  WHERE phy_state = ? AND phy_city = ? AND phy_street = ? AND dot_number <> ?) AS address,
                (SELECT COUNT(*) FROM carrier_details WHERE dun_bradstreet_no = ? AND dun_bradstreet_no <> '' AND dot_number <> ?) AS duns,
                (SELECT COUNT(*) FROM carrier_contacts WHERE dot_number = ?) AS boc3
        ", [
            $carrier->telephone, $dot,
            $carrier->email_address, $dot,
            $carrier->phy_state, $carrier->phy_city, $carrier->phy_street, $dot,
            $detail?->dun_bradstreet_no, $dot,
            $dot,
        ]);

        // Equipment sharing: 3+ other carriers inspected on the same VINs.
        // Sampled over the most recent VINs — this is a yes/no signal, and a
        // large fleet would otherwise scan tens of thousands of rows for it.
        $sharedVins = DB::connection('external_db')->selectOne('
            SELECT COUNT(DISTINCT i.dot_number) AS sharing_dots
            FROM inspections i
            JOIN (
                SELECT vin FROM inspections
                WHERE dot_number = ? AND vin IS NOT NULL AND CHAR_LENGTH(vin) = 17
                GROUP BY vin
                ORDER BY MAX(insp_date) DESC
                LIMIT '.self::VIN_SAMPLE.'
            ) v ON v.vin = i.vin
            WHERE i.dot_number <> ?
        ', [$dot, $dot]);

        return [
            'phone' => ! empty($carrier->telephone) && (int) $row->phone > 0,
            'email' => ! empty($carrier->email_address) && (int) $row->email > 0,
            'address' => ! empty($carrier->phy_street)
                && strlen(trim($carrier->phy_street)) > 5
                && (int) $row->address > 0,
            'duns' => ! empty($detail?->dun_bradstreet_no)
                && strlen(trim($detail->dun_bradstreet_no)) > 3
                && (int) $row->duns > 0,
            'shared_vins' => (int) $sharedVins->sharing_dots >= 3,
            'boc3_on_file' => (int) $row->boc3 > 0,
        ];
    }

    protected function areaCodeMatches(Carrier $carrier): ?bool
    {
        $digits = preg_replace('/[^0-9]/', '', (string) ($carrier->telephone ?? ''));
        $code = strlen($digits) >= 10 ? substr($digits, -10, 3) : null;

        if (! $code || ! $carrier->phy_state || ! isset(self::AREA_CODES[$carrier->phy_state])) {
            return null;
        }

        return in_array($code, explode(',', self::AREA_CODES[$carrier->phy_state]), true);
    }

    /**
     * National benchmarks. Cached for an hour — these are aggregates over the
     * whole census and do not move intraday.
     */
    protected function benchmarks(): array
    {
        return Cache::remember('carrier:risk:benchmarks', 3600, function () {
            $benchmarks = [];

            $benchmarks['natl_vehicle_oos'] = (float) (SmsMeasure::query()
                ->where('vehicle_insp_total', '>=', 5)
                ->selectRaw('AVG(vehicle_oos_insp_total / NULLIF(vehicle_insp_total,0)) AS avg_v_oos')
                ->value('avg_v_oos') ?? 0.208);

            // Ten statements — a COUNT plus an OFFSET walk over 694k unindexed
            // rows for each of the five measures — against a database a
            // ~300ms round trip away. One sampled window query gives the same
            // cut-points.
            $p90 = DB::connection('external_db')->selectOne('
                SELECT
                    MAX(CASE WHEN r0 <= 0.9 THEN m0 END) AS unsafe_driv_measure,
                    MAX(CASE WHEN r1 <= 0.9 THEN m1 END) AS hos_driv_measure,
                    MAX(CASE WHEN r2 <= 0.9 THEN m2 END) AS driv_fit_measure,
                    MAX(CASE WHEN r3 <= 0.9 THEN m3 END) AS contr_subst_measure,
                    MAX(CASE WHEN r4 <= 0.9 THEN m4 END) AS veh_maint_measure
                FROM (
                    SELECT
                        unsafe_driv_measure  AS m0, PERCENT_RANK() OVER (ORDER BY unsafe_driv_measure)  AS r0,
                        hos_driv_measure     AS m1, PERCENT_RANK() OVER (ORDER BY hos_driv_measure)     AS r1,
                        driv_fit_measure     AS m2, PERCENT_RANK() OVER (ORDER BY driv_fit_measure)     AS r2,
                        contr_subst_measure  AS m3, PERCENT_RANK() OVER (ORDER BY contr_subst_measure)  AS r3,
                        veh_maint_measure    AS m4, PERCENT_RANK() OVER (ORDER BY veh_maint_measure)    AS r4
                    FROM sms_measures WHERE insp_total > 0 AND (id % 16) = 0
                ) t
            ');

            foreach ([
                'unsafe_driv_measure', 'hos_driv_measure', 'driv_fit_measure',
                'contr_subst_measure', 'veh_maint_measure',
            ] as $measure) {
                $cut = (float) ($p90->$measure ?? 0);

                // A cut-point of zero means most carriers sit at zero for this
                // BASIC; treating it as a threshold would alert on everyone.
                $benchmarks["p90_{$measure}"] = $cut > 0 ? $cut : null;
            }

            $ratios = [
                'pum' => Carrier::query()
                    ->where('nbr_power_unit', '>', 0)
                    ->whereRaw("CAST(NULLIF(mcs150_mileage,'') AS UNSIGNED) > 0")
                    ->selectRaw("nbr_power_unit / NULLIF(CAST(NULLIF(mcs150_mileage,'') AS UNSIGNED),0) AS r"),
                'im' => SmsMeasure::query()
                    ->join('carriers', 'carriers.dot_number', '=', 'sms_measures.dot_number')
                    ->where('sms_measures.insp_total', '>', 0)
                    ->whereRaw("CAST(NULLIF(carriers.mcs150_mileage,'') AS UNSIGNED) > 0")
                    ->selectRaw("sms_measures.insp_total / NULLIF(CAST(NULLIF(carriers.mcs150_mileage,'') AS UNSIGNED),0) AS r"),
            ];

            foreach ($ratios as $key => $builder) {
                $count = DB::connection('external_db')->query()->fromSub($builder, 't')->count();

                if ($count > 100) {
                    $benchmarks["{$key}_lo"] = (float) DB::connection('external_db')->query()
                        ->fromSub($builder, 't')->orderBy('r')->skip((int) floor($count * 0.05))->value('r');
                    $benchmarks["{$key}_hi"] = (float) DB::connection('external_db')->query()
                        ->fromSub($builder, 't')->orderBy('r')->skip((int) floor($count * 0.95))->value('r');
                } else {
                    $benchmarks["{$key}_lo"] = $benchmarks["{$key}_hi"] = null;
                }
            }

            return $benchmarks;
        });
    }

    /** FMCSA writes dates like 01-JUN-74. */
    protected function parseFmcsaDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($value))) {
                $year = substr($value, -2);
                $century = $year > date('y') ? '19' : '20';

                return Carbon::createFromFormat('d-M-Y', strtoupper(substr($value, 0, -2).$century.$year));
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
