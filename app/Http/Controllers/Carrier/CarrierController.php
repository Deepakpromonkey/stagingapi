<?php

namespace App\Http\Controllers\Carrier;

use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Models\Carriers\CarrierAuthority;
use App\Models\Carriers\CarrierAuthorityOrder;
use App\Models\Carriers\CarrierContact;
use App\Models\Carriers\CarrierDetail;
use App\Models\Carriers\CarrierOosOrder;
use App\Models\Carriers\Crash;
use App\Models\Carriers\Inspection;
use App\Models\Carriers\InsuranceFiling;
use App\Models\Carriers\InsuranceFilingHistory;
use App\Models\Carriers\SmsMeasure;
use App\Models\Carriers\ViolationDetail;
use App\Models\CarrierShortlist;
use App\Models\Connect\CarrierConnectRequestsModel;
use App\Models\Customers\CustomersQuestionsModel;
use App\Models\SearchHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarrierController extends Controller
{
    private function getCompanyAssociations(Carrier $carrier)
    {
        $matches = collect();

        /*
        |--------------------------------------------------------------------------
        | Email
        |--------------------------------------------------------------------------
        */

        if ($carrier->email_address) {

            Carrier::where('email_address', $carrier->email_address)
                ->where('dot_number', '!=', $carrier->dot_number)
                ->get()
                ->each(function ($c) use ($matches) {

                    $matches->push([
                        'match_type' => 'Email',
                        'carrier' => $c,
                    ]);

                });

        }

        /*
        |--------------------------------------------------------------------------
        | Phone
        |--------------------------------------------------------------------------
        */

        if ($carrier->telephone) {

            Carrier::where('telephone', $carrier->telephone)
                ->where('dot_number', '!=', $carrier->dot_number)
                ->get()
                ->each(function ($c) use ($matches) {

                    $matches->push([
                        'match_type' => 'Phone',
                        'carrier' => $c,
                    ]);

                });

        }

        /*
        |--------------------------------------------------------------------------
        | Fax
        |--------------------------------------------------------------------------
        */

        if ($carrier->fax) {

            Carrier::where('fax', $carrier->fax)
                ->where('dot_number', '!=', $carrier->dot_number)
                ->get()
                ->each(function ($c) use ($matches) {

                    $matches->push([
                        'match_type' => 'Fax',
                        'carrier' => $c,
                    ]);

                });

        }

        /*
        |--------------------------------------------------------------------------
        | Physical Address
        |--------------------------------------------------------------------------
        */

        Carrier::where('phy_street', $carrier->phy_street)
            ->where('phy_city', $carrier->phy_city)
            ->where('phy_state', $carrier->phy_state)
            ->where('phy_zip', $carrier->phy_zip)
            ->where('dot_number', '!=', $carrier->dot_number)
            ->get()
            ->each(function ($c) use ($matches) {

                $matches->push([
                    'match_type' => 'Physical Address',
                    'carrier' => $c,
                ]);

            });

        /*
        |--------------------------------------------------------------------------
        | Mailing Address
        |--------------------------------------------------------------------------
        */

        Carrier::where('mailing_street', $carrier->mailing_street)
            ->where('mailing_city', $carrier->mailing_city)
            ->where('mailing_state', $carrier->mailing_state)
            ->where('mailing_zip', $carrier->mailing_zip)
            ->where('dot_number', '!=', $carrier->dot_number)
            ->get()
            ->each(function ($c) use ($matches) {

                $matches->push([
                    'match_type' => 'Mailing Address',
                    'carrier' => $c,
                ]);

            });

        return $matches
            ->groupBy(fn ($x) => $x['carrier']->dot_number)
            ->map(function ($items) {

                $carrier = $items->first()['carrier'];

                return [

                    'dot_number' => $carrier->dot_number,

                    'company_name' => $carrier->legal_name,

                    'phone' => $carrier->telephone,

                    'email' => $carrier->email_address,

                    'matched_on' => $items->pluck('match_type')->unique()->values(),

                ];

            })
            ->values();
    }

    private function calculateCarrierTrustScore(
        $carrier,
        $detail,
        $sms,
        $auth,
        $vehicleOosPct,
        $driverOosPct,
        $authorityAgeCommon,
        $authorityAgeContract,
        $authorityAgeBroker,
        $dotAge,
        $mcs150Year,
        $observedUnits,
        $observedTrailers,
        $crashesTotal,
        $crashFatalities,
        $crashInjuries,
        $crashesTowAway
    ) {
        /*
        |--------------------------------------------------------------------------
        | Knockout Rules
        |--------------------------------------------------------------------------
        */

        $knockout = $this->checkKnockout(
            $carrier,
            $detail,
            $auth
        );

        if ($knockout['triggered']) {

            return [

                'overall_score' => 18,

                'grade' => 'F',

                'status' => 'Rejected',

                'knockout' => $knockout,

                'pillars' => [],

            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate Every Pillar
        |--------------------------------------------------------------------------
        */

        $safety = $this->calculateSafety(
            $sms,
            $detail,
            $vehicleOosPct,
            $driverOosPct,
            $carrier
        );

        $identity = $this->calculateIdentity(
            $carrier,
            $detail
        );

        $insurance = $this->calculateInsurance(
            $carrier,
            $auth
        );

        $authority = $this->calculateAuthority(
            $carrier,
            $auth,
            $authorityAgeCommon,
            $authorityAgeContract,
            $authorityAgeBroker
        );

        $crash = $this->calculateCrash(
            $carrier,
            $crashesTotal,
            $crashFatalities,
            $crashInjuries,
            $crashesTowAway
        );

        $inspection = $this->calculateInspection(
            $carrier,
            $sms,
            $vehicleOosPct,
            $driverOosPct
        );

        $operations = $this->calculateOperations(
            $carrier,
            $detail,
            $dotAge,
            $mcs150Year,
            $observedUnits,
            $observedTrailers
        );

        /*
        |--------------------------------------------------------------------------
        | Final Score
        |--------------------------------------------------------------------------
        */

        $overallScore =
            $safety['score'] +
            $identity['score'] +
            $insurance['score'] +
            $authority['score'] +
            $crash['score'] +
            $inspection['score'] +
            $operations['score'];

        return [

            'overall_score' => round($overallScore),

            'grade' => $this->getGrade($overallScore),

            'status' => $overallScore >= 80
                ? 'Approved'
                : ($overallScore >= 60
                    ? 'Review'
                    : 'High Risk'),

            'knockout' => $knockout,

            'pillars' => [

                'safety_roadside' => $safety,

                'identity_fraud' => $identity,

                'insurance_financial' => $insurance,

                'authority_compliance' => $authority,

                'crash_history' => $crash,

                'inspection_quality' => $inspection,

                'operations_experience' => $operations,

            ],

        ];
    }

    private function checkKnockout($carrier, $detail, $auth)
    {
        $triggered = false;
        $reasons = [];

        /*
        |--------------------------------------------------------------------------
        | Authority Inactive
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($auth?->common_stat ?? '') !== 'ACTIVE' &&
            strtoupper($auth?->contract_stat ?? '') !== 'ACTIVE'
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'AUTHORITY_INACTIVE',
                'message' => 'Common and Contract Authority are both inactive.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | DOT Inactive
        |--------------------------------------------------------------------------
        */

        if (
            empty($carrier?->dot_number) ||
            strtoupper($detail?->status_code ?? '') == 'I'
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'DOT_INACTIVE',
                'message' => 'DOT Number is inactive.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Unsatisfactory Safety Rating
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($detail?->safety_rating ?? '') == 'U' ||
            strtolower($detail?->safety_rating ?? '') == 'unsatisfactory'
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'UNSATISFACTORY_RATING',
                'message' => 'Carrier has an Unsatisfactory Safety Rating.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Missing BIPD Insurance
        |--------------------------------------------------------------------------
        */

        if (empty($auth?->bipd_file)) {

            $triggered = true;

            $reasons[] = [
                'code' => 'NO_BIPD',
                'message' => 'No active BIPD insurance filing.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Cargo Insurance Missing
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($auth?->cargo_req ?? '') == 'Y' &&
            empty($auth?->cargo_file)
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'NO_CARGO_INSURANCE',
                'message' => 'Cargo Insurance required but not on file.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Bond Required
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($auth?->bond_req ?? '') == 'Y' &&
            empty($auth?->bond_file)
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'NO_BOND',
                'message' => 'Bond required but not on file.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Out Of Service Order
        |--------------------------------------------------------------------------
        */

        $activeOOS = $carrier->oosOrders
            ->whereNull('rescind_date')
            ->where('status', 'ACTIVE')
            ->count();

        if ($activeOOS > 0) {

            $triggered = true;

            $reasons[] = [
                'code' => 'OUT_OF_SERVICE',
                'message' => 'Carrier currently has an active Out Of Service Order.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Internal Block
        |--------------------------------------------------------------------------
        | Future Database Field
        */

        if (
            property_exists($carrier, 'blocked_internally') &&
            $carrier->blocked_internally
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'BLOCKED',
                'message' => 'Carrier is internally blocked.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Fraud Reports
        |--------------------------------------------------------------------------
        | Future Table
        */

        if (
            property_exists($carrier, 'incident_reports_fraud') &&
            $carrier->incident_reports_fraud >= 2
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'FRAUD_REPORTS',
                'message' => 'Multiple fraud reports found.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Freight Validate
        |--------------------------------------------------------------------------
        | Future Field
        */

        if (
            property_exists($carrier, 'freightvalidate_status') &&
            strtoupper($carrier->freightvalidate_status) == 'INVALID'
        ) {

            $triggered = true;

            $reasons[] = [
                'code' => 'FREIGHT_VALIDATE',
                'message' => 'FreightValidate status is Invalid.',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Shared Identity Checks
        |--------------------------------------------------------------------------
        | Add once network graph is ready
        */

        /*
        if($carrier->shared_phone_count >= 3){}
        if($carrier->shared_email_count >= 3){}
        if($carrier->shared_address_count >= 3){}
        */

        return [

            'triggered' => $triggered,

            'cap_score' => $triggered ? 18 : null,

            'reasons' => $reasons,

        ];
    }

    private function calculateSafety(
        $sms,
        $detail,
        $vehicleOosPct,
        $driverOosPct,
        $carrier
    ) {
        $score = 24;

        $deductions = [];

        /*
        |--------------------------------------------------------------------------
        | Safety Rating
        |--------------------------------------------------------------------------
        */

        switch (strtoupper($detail?->safety_rating ?? '')) {

            case 'U':
            case 'UNSATISFACTORY':
                $score -= 10;
                $deductions[] = 'Unsatisfactory Safety Rating';
                break;

            case 'C':
            case 'CONDITIONAL':
                $score -= 5;
                $deductions[] = 'Conditional Safety Rating';
                break;

            case 'S':
            case 'SATISFACTORY':
                break;

            default:
                $score -= 2;
                $deductions[] = 'No Safety Rating';
        }

        /*
        |--------------------------------------------------------------------------
        | Unsafe Driving BASIC
        |--------------------------------------------------------------------------
        */

        $unsafe = (float) ($sms?->unsafe_driv_pct ?? 0);

        if ($unsafe >= 90) {
            $score -= 5;
            $deductions[] = 'Unsafe Driving Percentile >90';
        } elseif ($unsafe >= 75) {
            $score -= 4;
            $deductions[] = 'Unsafe Driving Percentile >75';
        } elseif ($unsafe >= 50) {
            $score -= 2;
            $deductions[] = 'Unsafe Driving Percentile >50';
        }

        /*
        |--------------------------------------------------------------------------
        | HOS BASIC
        |--------------------------------------------------------------------------
        */

        $hos = (float) ($sms?->hos_driv_pct ?? 0);

        if ($hos >= 90) {
            $score -= 4;
            $deductions[] = 'HOS Percentile >90';
        } elseif ($hos >= 75) {
            $score -= 3;
            $deductions[] = 'HOS Percentile >75';
        } elseif ($hos >= 50) {
            $score -= 2;
            $deductions[] = 'HOS Percentile >50';
        }

        /*
        |--------------------------------------------------------------------------
        | Vehicle Maintenance
        |--------------------------------------------------------------------------
        */

        $maintenance = (float) ($sms?->veh_maint_pct ?? 0);

        if ($maintenance >= 90) {
            $score -= 5;
            $deductions[] = 'Vehicle Maintenance Percentile >90';
        } elseif ($maintenance >= 75) {
            $score -= 4;
            $deductions[] = 'Vehicle Maintenance Percentile >75';
        } elseif ($maintenance >= 50) {
            $score -= 2;
            $deductions[] = 'Vehicle Maintenance Percentile >50';
        }

        /*
        |--------------------------------------------------------------------------
        | Driver Fitness
        |--------------------------------------------------------------------------
        */

        $fitness = (float) ($sms?->driv_fit_pct ?? 0);

        if ($fitness >= 90) {
            $score -= 3;
            $deductions[] = 'Driver Fitness Percentile >90';
        } elseif ($fitness >= 75) {
            $score -= 2;
            $deductions[] = 'Driver Fitness Percentile >75';
        }

        /*
        |--------------------------------------------------------------------------
        | Controlled Substance
        |--------------------------------------------------------------------------
        */

        $substance = (float) ($sms?->contr_subst_pct ?? 0);

        if ($substance >= 90) {
            $score -= 5;
            $deductions[] = 'Controlled Substance Percentile >90';
        } elseif ($substance >= 75) {
            $score -= 3;
            $deductions[] = 'Controlled Substance Percentile >75';
        }

        /*
        |--------------------------------------------------------------------------
        | Vehicle OOS %
        |--------------------------------------------------------------------------
        */

        if ($vehicleOosPct >= 30) {

            $score -= 3;

            $deductions[] = 'Vehicle OOS Rate High';

        } elseif ($vehicleOosPct >= 20) {

            $score -= 2;

        } elseif ($vehicleOosPct >= 10) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Driver OOS %
        |--------------------------------------------------------------------------
        */

        if ($driverOosPct >= 15) {

            $score -= 3;

            $deductions[] = 'Driver OOS Rate High';

        } elseif ($driverOosPct >= 10) {

            $score -= 2;

        } elseif ($driverOosPct >= 5) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | BASIC Alerts
        |--------------------------------------------------------------------------
        */

        if ($sms?->unsafe_driv_basic_alert == 'Y') {
            $score -= 2;
            $deductions[] = 'Unsafe Driving BASIC Alert';
        }

        if ($sms?->hos_driv_basic_alert == 'Y') {
            $score -= 2;
            $deductions[] = 'HOS BASIC Alert';
        }

        if ($sms?->veh_maint_basic_alert == 'Y') {
            $score -= 2;
            $deductions[] = 'Vehicle Maintenance BASIC Alert';
        }

        if ($sms?->driv_fit_basic_alert == 'Y') {
            $score -= 2;
            $deductions[] = 'Driver Fitness BASIC Alert';
        }

        if ($sms?->contr_subst_basic_alert == 'Y') {
            $score -= 2;
            $deductions[] = 'Controlled Substance BASIC Alert';
        }

        /*
        |--------------------------------------------------------------------------
        | Roadside Alerts
        |--------------------------------------------------------------------------
        */

        if ($sms?->unsafe_driv_rd_alert == 'Y') {
            $score--;
        }

        if ($sms?->hos_driv_rd_alert == 'Y') {
            $score--;
        }

        if ($sms?->veh_maint_rd_alert == 'Y') {
            $score--;
        }

        if ($sms?->driv_fit_rd_alert == 'Y') {
            $score--;
        }

        if ($sms?->contr_subst_rd_alert == 'Y') {
            $score--;
        }

        /*
        |--------------------------------------------------------------------------
        | Inspection Volume
        |--------------------------------------------------------------------------
        */

        $inspectionCount = $carrier->inspections->count();

        if ($inspectionCount == 0) {

            $score -= 4;

            $deductions[] = 'No Inspection History';

        } elseif ($inspectionCount < 5) {

            $score -= 2;

        }

        /*
        |--------------------------------------------------------------------------
        | Limits
        |--------------------------------------------------------------------------
        */

        $score = max(0, min(24, round($score)));

        /*
        |--------------------------------------------------------------------------
        | Grade
        |--------------------------------------------------------------------------
        */

        if ($score >= 22) {

            $status = 'Excellent';

        } elseif ($score >= 18) {

            $status = 'Good';

        } elseif ($score >= 14) {

            $status = 'Average';

        } elseif ($score >= 8) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 24,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'unsafe_driv_pct' => $sms?->unsafe_driv_pct,

                'hos_driv_pct' => $sms?->hos_driv_pct,

                'veh_maint_pct' => $sms?->veh_maint_pct,

                'driv_fit_pct' => $sms?->driv_fit_pct,

                'contr_subst_pct' => $sms?->contr_subst_pct,

                'vehicle_oos_pct' => $vehicleOosPct,

                'driver_oos_pct' => $driverOosPct,

                'inspection_count' => $inspectionCount,

                'safety_rating' => $detail?->safety_rating,

            ],

        ];
    }

    private function calculateIdentity(
        $carrier,
        $detail
    ) {
        $score = 20;

        $deductions = [];

        /*
        |--------------------------------------------------------------------------
        | Temporary Values
        | Replace these with Network Graph queries later
        |--------------------------------------------------------------------------
        */

        $sharedPhoneCount = 0;

        $sharedEmailCount = 0;

        $sharedAddressCount = 0;

        $sharedEquipmentPct = 0;

        $addressChangeCount = 0;

        $addressLastChangedDays = null;

        /*
        |--------------------------------------------------------------------------
        | Prior Revocation
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($detail?->prior_revoke_flag ?? '') == 'Y'
        ) {

            $score -= 8;

            $deductions[] = 'Prior Revoked Authority';

        }

        /*
        |--------------------------------------------------------------------------
        | Missing DUNS
        |--------------------------------------------------------------------------
        */

        if (
            empty($detail?->dun_bradstreet_no)
        ) {

            $score -= 2;

            $deductions[] = 'Missing DUNS Number';

        }

        /*
        |--------------------------------------------------------------------------
        | Generic Email
        |--------------------------------------------------------------------------
        */

        if (! empty($carrier->email_address)) {

            $domain = strtolower(substr(strrchr($carrier->email_address, '@'), 1));

            $freeDomains = [

                'gmail.com',
                'yahoo.com',
                'hotmail.com',
                'outlook.com',
                'icloud.com',
                'aol.com',
                'live.com',
                'msn.com',
                'protonmail.com',

            ];

            if (in_array($domain, $freeDomains)) {

                $score -= 2;

                $deductions[] = 'Free Email Provider';

            }

        } else {

            $score -= 2;

            $deductions[] = 'Missing Email';

        }

        /*
        |--------------------------------------------------------------------------
        | Missing Phone
        |--------------------------------------------------------------------------
        */

        if (empty($carrier->telephone)) {

            $score -= 2;

            $deductions[] = 'Missing Phone Number';

        }

        /*
        |--------------------------------------------------------------------------
        | Missing Address
        |--------------------------------------------------------------------------
        */

        if (
            empty($carrier->phy_street) ||
            empty($carrier->phy_city)
        ) {

            $score -= 2;

            $deductions[] = 'Incomplete Physical Address';

        }

        /*
        |--------------------------------------------------------------------------
        | Shared Phone
        |--------------------------------------------------------------------------
        */

        if ($sharedPhoneCount >= 10) {

            $score -= 8;

            $deductions[] = 'Phone Shared Across Multiple DOTs';

        } elseif ($sharedPhoneCount >= 5) {

            $score -= 5;

        } elseif ($sharedPhoneCount >= 3) {

            $score -= 3;

        }

        /*
        |--------------------------------------------------------------------------
        | Shared Email
        |--------------------------------------------------------------------------
        */

        if ($sharedEmailCount >= 10) {

            $score -= 8;

            $deductions[] = 'Email Shared Across Multiple DOTs';

        } elseif ($sharedEmailCount >= 5) {

            $score -= 5;

        } elseif ($sharedEmailCount >= 3) {

            $score -= 3;

        }

        /*
        |--------------------------------------------------------------------------
        | Shared Address
        |--------------------------------------------------------------------------
        */

        if ($sharedAddressCount >= 10) {

            $score -= 8;

            $deductions[] = 'Address Shared Across Multiple DOTs';

        } elseif ($sharedAddressCount >= 5) {

            $score -= 5;

        } elseif ($sharedAddressCount >= 3) {

            $score -= 3;

        }

        /*
        |--------------------------------------------------------------------------
        | Shared Equipment
        |--------------------------------------------------------------------------
        */

        if ($sharedEquipmentPct >= 70) {

            $score -= 5;

        } elseif ($sharedEquipmentPct >= 40) {

            $score -= 3;

        } elseif ($sharedEquipmentPct >= 20) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Address Changes
        |--------------------------------------------------------------------------
        */

        if ($addressChangeCount >= 5) {

            $score -= 4;

        } elseif ($addressChangeCount >= 3) {

            $score -= 2;

        }

        /*
        |--------------------------------------------------------------------------
        | Recent Address Change
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($addressLastChangedDays) &&
            $addressLastChangedDays <= 30
        ) {

            $score -= 2;

            $deductions[] = 'Recent Address Change';

        }

        /*
        |--------------------------------------------------------------------------
        | Clamp
        |--------------------------------------------------------------------------
        */

        $score = max(0, min(20, round($score)));

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($score >= 18) {

            $status = 'Excellent';

        } elseif ($score >= 15) {

            $status = 'Good';

        } elseif ($score >= 10) {

            $status = 'Average';

        } elseif ($score >= 5) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 20,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'duns' => $detail?->dun_bradstreet_no,

                'prior_revoke_flag' => $detail?->prior_revoke_flag,

                'phone' => $carrier->telephone,

                'email' => $carrier->email_address,

                'shared_phone_count' => $sharedPhoneCount,

                'shared_email_count' => $sharedEmailCount,

                'shared_address_count' => $sharedAddressCount,

                'shared_equipment_pct' => $sharedEquipmentPct,

                'address_change_count' => $addressChangeCount,

                'address_last_changed_days' => $addressLastChangedDays,

            ],

        ];
    }

    private function calculateInsurance($carrier, $auth)
    {
        $score = 18;

        $deductions = [];

        $activePolicies = $carrier->insuranceFilings;

        $pendingPolicies = $carrier->insuranceFilingsPending;

        $historyPolicies = $carrier->insuranceFilingsHistory;

        /*
        |--------------------------------------------------------------------------
        | Active Insurance
        |--------------------------------------------------------------------------
        */

        if ($activePolicies->count() == 0) {

            $score -= 10;

            $deductions[] = 'No Active Insurance Filing';

        }

        /*
        |--------------------------------------------------------------------------
        | BIPD Insurance
        |--------------------------------------------------------------------------
        */

        if (empty($auth?->bipd_file)) {

            $score -= 5;

            $deductions[] = 'No BIPD Insurance';

        }

        /*
        |--------------------------------------------------------------------------
        | Cargo Insurance
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($auth?->cargo_req ?? '') == 'Y' &&
            empty($auth?->cargo_file)
        ) {

            $score -= 5;

            $deductions[] = 'Cargo Insurance Required';

        }

        /*
        |--------------------------------------------------------------------------
        | Bond
        |--------------------------------------------------------------------------
        */

        if (
            strtoupper($auth?->bond_req ?? '') == 'Y' &&
            empty($auth?->bond_file)
        ) {

            $score -= 4;

            $deductions[] = 'Bond Required';

        }

        /*
        |--------------------------------------------------------------------------
        | Minimum Coverage
        |--------------------------------------------------------------------------
        */

        $coverage = (float) ($auth?->min_cov_amount ?? 0);

        if ($coverage == 0) {

            $score -= 3;

            $deductions[] = 'Coverage Amount Missing';

        } elseif ($coverage < 750000) {

            $score -= 2;

        } elseif ($coverage >= 1000000) {

            $score += 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Pending Insurance
        |--------------------------------------------------------------------------
        */

        if ($pendingPolicies->count() > 0) {

            $score -= 2;

            $deductions[] = 'Pending Insurance Filing';

        }

        /*
        |--------------------------------------------------------------------------
        | Rejected Pending Filings
        |--------------------------------------------------------------------------
        */

        $rejected = $pendingPolicies
            ->filter(fn ($x) => ! empty($x->rej_date))
            ->count();

        if ($rejected >= 3) {

            $score -= 4;

        } elseif ($rejected >= 1) {

            $score -= 2;

        }

        /*
        |--------------------------------------------------------------------------
        | Insurance Company Changes
        |--------------------------------------------------------------------------
        */

        $companyChanges = $historyPolicies
            ->pluck('name_company')
            ->filter()
            ->unique()
            ->count();

        if ($companyChanges >= 8) {

            $score -= 4;

            $deductions[] = 'Frequent Insurance Company Changes';

        } elseif ($companyChanges >= 5) {

            $score -= 2;

        }

        /*
        |--------------------------------------------------------------------------
        | Policy Changes
        |--------------------------------------------------------------------------
        */

        $policyChanges = $historyPolicies
            ->pluck('policy_no')
            ->filter()
            ->unique()
            ->count();

        if ($policyChanges >= 10) {

            $score -= 4;

        } elseif ($policyChanges >= 5) {

            $score -= 2;

        }

        /*
        |--------------------------------------------------------------------------
        | Insurance History
        |--------------------------------------------------------------------------
        */

        if ($historyPolicies->count() == 0) {

            $score -= 2;

            $deductions[] = 'No Insurance History';

        }

        /*
        |--------------------------------------------------------------------------
        | Clamp
        |--------------------------------------------------------------------------
        */

        $score = max(0, min(18, round($score)));

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($score >= 16) {

            $status = 'Excellent';

        } elseif ($score >= 13) {

            $status = 'Good';

        } elseif ($score >= 9) {

            $status = 'Average';

        } elseif ($score >= 5) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 18,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'active_filings' => $activePolicies->count(),

                'pending_filings' => $pendingPolicies->count(),

                'history_filings' => $historyPolicies->count(),

                'bipd_file' => $auth?->bipd_file,

                'cargo_required' => $auth?->cargo_req,

                'cargo_file' => $auth?->cargo_file,

                'bond_required' => $auth?->bond_req,

                'bond_file' => $auth?->bond_file,

                'minimum_coverage' => $coverage,

                'insurance_company_changes' => $companyChanges,

                'policy_changes' => $policyChanges,

                'rejected_filings' => $rejected,

            ],

        ];
    }

    private function calculateAuthority(
        $carrier,
        $auth,
        $authorityAgeCommon,
        $authorityAgeContract,
        $authorityAgeBroker
    ) {
        $score = 12;

        $deductions = [];

        $history = $carrier->authorityHistory;

        $orders = $carrier->authorityOrders;

        /*
        |--------------------------------------------------------------------------
        | Common Authority
        |--------------------------------------------------------------------------
        */

        if (strtoupper($auth?->common_stat ?? '') == 'ACTIVE') {

            $score += 1;

        } else {

            $score -= 2;

            $deductions[] = 'Common Authority Inactive';

        }

        /*
        |--------------------------------------------------------------------------
        | Contract Authority
        |--------------------------------------------------------------------------
        */

        if (strtoupper($auth?->contract_stat ?? '') == 'ACTIVE') {

            $score += 1;

        } else {

            $score -= 2;

            $deductions[] = 'Contract Authority Inactive';

        }

        /*
        |--------------------------------------------------------------------------
        | Broker Authority
        |--------------------------------------------------------------------------
        */

        if (strtoupper($auth?->broker_stat ?? '') == 'ACTIVE') {

            $score += 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Pending Applications
        |--------------------------------------------------------------------------
        */

        if (! empty($auth?->common_app_pend)) {

            $score -= 1;

            $deductions[] = 'Common Authority Pending';

        }

        if (! empty($auth?->contract_app_pend)) {

            $score -= 1;

            $deductions[] = 'Contract Authority Pending';

        }

        if (! empty($auth?->broker_app_pend)) {

            $score -= 1;

            $deductions[] = 'Broker Authority Pending';

        }

        /*
        |--------------------------------------------------------------------------
        | Revocation Pending
        |--------------------------------------------------------------------------
        */

        if (! empty($auth?->common_rev_pend)) {

            $score -= 3;

            $deductions[] = 'Common Revocation Pending';

        }

        if (! empty($auth?->contract_rev_pend)) {

            $score -= 3;

            $deductions[] = 'Contract Revocation Pending';

        }

        if (! empty($auth?->broker_rev_pend)) {

            $score -= 3;

            $deductions[] = 'Broker Revocation Pending';

        }

        /*
        |--------------------------------------------------------------------------
        | Revocation History
        |--------------------------------------------------------------------------
        */

        $revocations = $history
            ->filter(function ($item) {

                return stripos(
                    $item->disp_action_desc,
                    'REVOK'
                ) !== false;

            })
            ->count();

        if ($revocations >= 5) {

            $score -= 5;

            $deductions[] = 'Multiple Revocations';

        } elseif ($revocations >= 3) {

            $score -= 3;

        } elseif ($revocations >= 1) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Suspension Orders
        |--------------------------------------------------------------------------
        */

        $suspensions = $orders
            ->filter(function ($item) {

                return stripos(
                    $item->order2_type_desc,
                    'SUSPEND'
                ) !== false;

            })
            ->count();

        if ($suspensions >= 3) {

            $score -= 3;

            $deductions[] = 'Authority Suspended';

        } elseif ($suspensions >= 1) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Authority Age
        |--------------------------------------------------------------------------
        */

        $ages = array_filter([
            $authorityAgeCommon,
            $authorityAgeContract,
            $authorityAgeBroker,
        ]);

        if (count($ages)) {

            $oldestAuthority = max($ages);

            if ($oldestAuthority >= 15) {

                $score += 2;

            } elseif ($oldestAuthority >= 10) {

                $score += 1;

            } elseif ($oldestAuthority < 2) {

                $score -= 2;

                $deductions[] = 'New Authority';

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Authority Orders
        |--------------------------------------------------------------------------
        */

        if ($orders->count() >= 10) {

            $score -= 2;

        } elseif ($orders->count() >= 5) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Clamp
        |--------------------------------------------------------------------------
        */

        $score = max(0, min(12, round($score)));

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($score >= 11) {

            $status = 'Excellent';

        } elseif ($score >= 9) {

            $status = 'Good';

        } elseif ($score >= 6) {

            $status = 'Average';

        } elseif ($score >= 3) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 12,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'common_authority' => $auth?->common_stat,

                'contract_authority' => $auth?->contract_stat,

                'broker_authority' => $auth?->broker_stat,

                'common_pending' => $auth?->common_app_pend,

                'contract_pending' => $auth?->contract_app_pend,

                'broker_pending' => $auth?->broker_app_pend,

                'common_revocation' => $auth?->common_rev_pend,

                'contract_revocation' => $auth?->contract_rev_pend,

                'broker_revocation' => $auth?->broker_rev_pend,

                'authority_age_common' => $authorityAgeCommon,

                'authority_age_contract' => $authorityAgeContract,

                'authority_age_broker' => $authorityAgeBroker,

                'revocation_count' => $revocations,

                'suspension_orders' => $suspensions,

            ],

        ];
    }

    public function search(Request $request)
    {
        $search = trim($request->query('query', ''));
        $searchedBy = $request->query('searched_by', 'dot_number');
        $sort = $request->query('sort', '');
        $sortDir = $sort === 'sortByNameDesc' ? 'desc' : 'asc';
        $perPage = (int) $request->query('per_page', 10);

        $dotNumber = null;

        $query = Carrier::query()
            ->select([
                'id',
                'row_id',
                'dot_number',
                'legal_name',
                'dba_name',
                'telephone',
                'email_address',
                'phy_street',
                'phy_city',
                'phy_state',
                'phy_zip',
                'mcs150_mileage',
                'nbr_power_unit',
                'driver_total',
                'carrier_operation',
            ])
            ->with([
                'authority:dot_number,docket_number,common_stat,contract_stat,broker_stat',
                'authorityHistory:dot_number,op_auth_type,original_action_desc,orig_served_date,disp_action_desc,disp_decided_date,disp_served_date',
                'smsMeasures:dot_number,unsafe_driv_measure,hos_driv_measure,veh_maint_measure',

                'carrierDetail:dot_number,fleetsize,status_code,safety_rating,dun_bradstreet_no',

                'inspections' => function ($q) {
                    $q->select('dot_number', 'vin')->limit(1);
                },
            ])
            ->withExists('insuranceFilings');
        if (! empty($search)) {

            switch ($searchedBy) {

                case 'mc_number':

                    $search = strtoupper($search);

                    if (! str_starts_with($search, 'MC')) {
                        $search = 'MC'.$search;
                    }

                    $dotNumber = CarrierAuthority::where('docket_number', $search)
                        ->value('dot_number');

                    if ($dotNumber) {
                        $query->where('dot_number', $dotNumber);
                    } else {
                        $query->whereRaw('1 = 0');
                    }

                    break;

                case 'dot_number':

                    $query->where('dot_number', $search);
                    break;

                case 'legal_name':

                    $query->where('legal_name', 'LIKE', "%{$search}%");
                    break;

                case 'phone':

                    $query->where('telephone', $search);
                    break;

                case 'email':

                    $query->where('email_address', $search);
                    break;

                default:

                    $query->where('dot_number', $search);
            }
        }

        $data = $query
            ->orderBy('legal_name', $sortDir)
            ->paginate($perPage);

        $transformed = $data->getCollection()->map(function ($carrier) {

            $auth = $carrier->authority;

            return [

                'id' => $carrier->id,
                'row_id' => $carrier->row_id,
                'carrier_operation' => $carrier->carrier_operation,
                'company_name' => $carrier->legal_name,
                'dba_name' => $carrier->dba_name,
                'dot_number' => $carrier->dot_number,
                'mc_number' => $auth?->docket_number,
                'phone' => $carrier->telephone,
                'email' => $carrier->email_address,
                'duns' => $carrier->carrierDetail?->dun_bradstreet_no,

                'address' => collect([
                    $carrier->phy_street,
                    $carrier->phy_city,
                    $carrier->phy_state,
                    $carrier->phy_zip,
                ])->filter()->implode(', '),

                'insurance_current' => $carrier->insurance_filings_exists,

                'vin' => optional($carrier->inspections->first())->vin,

                'mileage' => $carrier->mcs150_mileage,

                'fleet_size' => match ($carrier->carrierDetail?->fleetsize) {
                    'A' => '1',
                    'B' => '2-3',
                    'C' => '4-6',
                    'D' => '7-8',
                    'E' => '9-11',
                    'F' => '12-14',
                    'G' => '15-17',
                    'H' => '18-19',
                    'I' => '20-23',
                    'J' => '24-28',
                    'K' => '29-32',
                    'L' => '33-38',
                    'M' => '39-44',
                    'N' => '45-55',
                    'O' => '56-75',
                    'P' => '76-100',
                    'Q' => '101-200',
                    'R' => '201-300',
                    'S' => '301-400',
                    'T' => '401-550',
                    'U' => '551-999',
                    'V' => '1000-2000',
                    'W' => '2001-3000',
                    'X' => '3001-4000',
                    'Y' => '4001-5000',
                    'Z' => 'OVER 5000',
                    default => null,
                },

                'drivers' => $carrier->driver_total,

                'is_broker' => $auth?->broker_stat === 'ACTIVE',

                'active_authority' => $carrier->carrierDetail?->status_code,

                'authority_verified' => $auth?->common_stat === 'ACTIVE' ||
                    $auth?->contract_stat === 'ACTIVE' ||
                    $auth?->broker_stat === 'ACTIVE',

                'risk_level' => match ($carrier->carrierDetail?->safety_rating) {
                    'S' => 'Satisfactory',
                    'C' => 'Conditional',
                    'U' => 'Unsatisfactory',
                    default => 'Not Rated',
                },
            ];
        });

        return response()->json([
            'current_page' => $data->currentPage(),
            'per_page' => $data->perPage(),
            'total' => $data->total(),
            'last_page' => $data->lastPage(),
            'has_more_pages' => $data->hasMorePages(),
            'data' => $transformed,
        ]);
    }

    public function search2(Request $request)
    {
        $search = trim($request->query('query', ''));
        $sort = $request->query('sort', '');
        $sortDir = $sort === 'sortByNameDesc' ? 'desc' : 'asc';

        $data = Carrier::query()

            ->select([
                'id',
                'row_id',
                'dot_number',
                'legal_name',
                'dba_name',
                'telephone',
                'email_address',
                'phy_street',
                'phy_city',
                'phy_state',
                'phy_zip',
                'mcs150_mileage',
                'nbr_power_unit',
                'driver_total',
                'carrier_operation',
            ])
            ->with([
                'authority:dot_number,docket_number,common_stat,contract_stat,broker_stat',

                'smsMeasures:dot_number,unsafe_driv_measure,hos_driv_measure,veh_maint_measure',

                'carrierDetail:dot_number,fleetsize,status_code,safety_rating,dun_bradstreet_no',

                'inspections' => fn ($q) => $q
                    ->select('dot_number', 'vin')
                    ->limit(1),
            ])->withExists('insuranceFilings')

            ->when(! empty($search), function ($q) use ($search) {

                if (is_numeric($search)) {

                    $q->where(function ($q) use ($search) {

                        $dotNumber = CarrierAuthority::where('docket_number', $search)
                            ->value('dot_number');

                        $q->where(function ($query) use ($search, $dotNumber) {
                            $query->where('dot_number', $search);

                            if ($dotNumber) {
                                $query->orWhere('dot_number', $dotNumber);
                            }
                        });
                    });

                } else {

                    $q->where(function ($q) use ($search) {

                        $q->where('legal_name', 'LIKE', "%{$search}%")
                            ->orWhere('dba_name', 'LIKE', "%{$search}%");
                    });
                }
            })

            ->orderBy('legal_name', $sortDir)

            ->paginate($request->query('per_page', 10));

        $transformed = collect($data->items())->map(function ($carrier) {

            $auth = $carrier->authority;
            $sms = $carrier->smsMeasures;

            return [

                'id' => $carrier->id,
                'row_id' => $carrier->row_id,
                'carrier_operation' => $carrier->carrier_operation,
                'company_name' => $carrier->legal_name,
                'dba_name' => $carrier->dba_name,
                'dot_number' => $carrier->dot_number,
                'mc_number' => $auth?->docket_number,
                'phone' => $carrier->telephone,
                'email' => $carrier->email_address,
                'duns' => $carrier->dun_bradstreet_nol ?? $carrier->carrierDetail?->dun_bradstreet_no,
                'address' => collect([
                    $carrier->phy_street,
                    $carrier->phy_city,
                    $carrier->phy_state,
                    $carrier->phy_zip,
                ])->filter()->implode(', '),
                'insurance_current' => $carrier->insurance_filings_exists,
                'vin' => $carrier->inspections->first()?->vin,
                'mileage' => $carrier->mcs150_mileage,
                'fleet_size' => match ($carrier->carrierDetail?->fleetsize) {
                    'A' => '1',
                    'B' => '2-3',
                    'C' => '4-6',
                    'D' => '7-8',
                    'E' => '9-11',
                    'F' => '12-14',
                    'G' => '15-17',
                    'H' => '18-19',
                    'I' => '20-23',
                    'J' => '24-28',
                    'K' => '29-32',
                    'L' => '33-38',
                    'M' => '39-44',
                    'N' => '45-55',
                    'O' => '56-75',
                    'P' => '76-100',
                    'Q' => '101-200',
                    'R' => '201-300',
                    'S' => '301-400',
                    'T' => '401-550',
                    'U' => '551-999',
                    'V' => '1000-2000',
                    'W' => '2001-3000',
                    'X' => '3001-4000',
                    'Y' => '4001-5000',
                    'Z' => 'OVER 5000',
                    default => null,
                },
                'drivers' => $carrier->driver_total,

                'is_broker' => $auth?->broker_stat === 'ACTIVE',
                'active_authority' => $carrier->carrierDetail?->status_code,
                'authority_verified' => $auth?->common_stat === 'ACTIVE' ||
                    $auth?->contract_stat === 'ACTIVE' ||
                    $auth?->broker_stat === 'ACTIVE',
                'risk_level' => $carrier->carrierDetail?->safety_rating,
            ];
        });

        return response()->json([
            'current_page' => $data->currentPage(),
            'per_page' => $data->perPage(),
            'total' => $data->total(),
            'last_page' => $data->lastPage(),
            'has_more_pages' => $data->hasMorePages(),
            'data' => $transformed,
        ]);
    }

    /**
     * Log a carrier profile view against the signed-in broker.
     *
     * Never allowed to break the profile response — a logging failure is
     * recorded and swallowed.
     */
    private function logCarrierView(Carrier $carrier, $dtScore = null): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        try {
            SearchHistory::updateOrCreate(
                [
                    'company_id' => auth()->user()->company_id,
                    'carrier_id' => $carrier->id,
                ],
                array_filter([
                    'user_id' => $userId,
                    // Null on the first (pre-computation) call, so it must not
                    // overwrite a score stored moments later.
                    'dt_score' => $dtScore,
                ], fn ($value) => $value !== null)
            )->touch();
        } catch (\Throwable $e) {
            Log::warning('Carrier view logging failed', [
                'dot_number' => $carrier->dot_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function detail($rowid)
    {
        $carrier = Carrier::query()
            ->where('row_id', $rowid)
            ->with([
                'authority',
                'smsMeasures',
                'contacts',
                'oosOrders',
                'authorityOrders',
                'authorityHistory',
                'inspections.units',
                'inspections.citations',
                'inspections.violationDetails',
                'crashes.detail',
                'crashDetails',
                'insuranceFilings',
                'insuranceFilingsPending',
                'insuranceFilingsHistory',
                'violationDetails',
                'carrierDetail',          // ← required for computed fields
            ])
            ->first();

        if (! $carrier) {
            return response()->json([
                'success' => false,
                'message' => 'Carrier not found',
            ], 404);
        }

        // Record that this broker opened the carrier's profile. Same table and
        // updateOrCreate semantics as POST /search-history, so a repeat view
        // bumps the timestamp instead of adding a duplicate row.
        $this->logCarrierView($carrier);

        $url = "https://mobile.fmcsa.dot.gov/qc/services/carriers/{$carrier->dot_number}?webKey=34c9e0e573a1af35b71d28176c73382a4a8bb313";
        $fmcsaResponse = Http::timeout(60)
            ->acceptJson()
            ->get($url);

        $fmcsaData = null;
        if ($fmcsaResponse->successful()) {
            $fmcsaData = $fmcsaResponse->json();
        }
        $sms = $carrier->smsMeasures;
        $detail = $carrier->carrierDetail;
        $auth = $carrier->authority;

        // ── Risk score ────────────────────────────────────────────────────
        $riskScore =
            (float) $sms?->unsafe_driv_measure +
            (float) $sms?->hos_driv_measure +
            (float) $sms?->veh_maint_measure;

        // ── Contact name counts ───────────────────────────────────────────
        $contactNameCounts = $carrier->contacts
            ->groupBy('attn_to_or_title')
            ->map(fn ($items, $name) => ['name' => $name, 'count' => $items->count()])
            ->values();

        // ── Insurance totals ──────────────────────────────────────────────

        $currentYear = now()->year;

        $getLatestAmount = function ($type) use ($carrier, $currentYear) {

            $filing = $carrier->insuranceFilings
                ->filter(function ($item) use ($type) {
                    return stripos($item->ins_type_desc ?? '', $type) !== false;
                })

                // Prefer current year's filing
                ->sortByDesc(function ($item) use ($currentYear) {
                    $year = $item->effective_date
                        ? Carbon::parse($item->effective_date)->year
                        : 0;

                    return ($year == $currentYear ? 10000 : 0) + strtotime($item->effective_date ?? '1900-01-01');
                })

                ->first();

            return (float) (
                (
                    $filing?->max_cov_amount
                    ?? $filing?->min_cov_amount
                    ?? $filing?->underl_lim_amount
                    ?? 0
                ) * 1000
            );
        };

        $bipdTotal = $getLatestAmount('BIPD');
        $cargoTotal = $getLatestAmount('Cargo');
        $bondTotal = $getLatestAmount('Surety');

        if ($bondTotal == 0) {
            $bondTotal = $getLatestAmount('Bond');
        }

        // ════════════════════════════════════════════════════════════════
        // COMPUTED FIELDS
        // ════════════════════════════════════════════════════════════════

        // dot_age — days since DOT number was issued

        $dotAge = null;
        $addDate = $carrier->add_date ?? $detail?->add_date;

        if ($addDate) {
            try {

                // Handle FMCSA dates like 01-JUN-74
                if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($addDate))) {

                    $year = substr($addDate, -2);

                    // FMCSA data started long before 2000,
                    // so convert 74 → 1974, 06 → 2006
                    $century = $year > date('y') ? '19' : '20';

                    $fixedDate = substr($addDate, 0, -2).$century.$year;

                    $parsedDate = Carbon::createFromFormat('d-M-Y', strtoupper($fixedDate));

                } else {

                    $parsedDate = Carbon::parse($addDate);

                }

                $dotAge = (int) $parsedDate->diffInYears(now());

            } catch (\Throwable $e) {
                $dotAge = null;
            }
        }

        // mcs150_year — year of last MCS-150 filing
        $mcs150Year = null;
        if ($carrier->mcs150_date) {
            try {
                $mcs150Year = (int) Carbon::parse($carrier->mcs150_date)->year;
            } catch (\Throwable) {
            }
        }

        // out_of_service_flag — active unrescinded OOS order exists
        $outOfServiceFlag = $carrier->oosOrders
            ->contains(fn ($o) => strtolower($o->status ?? '') === 'active' && empty($o->rescind_date));

        // email_domain
        $emailDomain = null;
        if ($carrier->email_address && str_contains($carrier->email_address, '@')) {
            $emailDomain = explode('@', $carrier->email_address)[1] ?? null;
        }
        $webPresence = null;

        if ($carrier->email_address && str_contains($carrier->email_address, '@')) {

            $domain = strtolower(trim(explode('@', $carrier->email_address)[1] ?? ''));

            $freeEmailProviders = [
                'gmail.com',
                'yahoo.com',
                'hotmail.com',
                'outlook.com',
                'live.com',
                'msn.com',
                'aol.com',
                'icloud.com',
                'me.com',
                'mac.com',
                'proton.me',
                'protonmail.com',
                'zoho.com',
                'ymail.com',
                'rocketmail.com',
                'mail.com',
                'gmx.com',
                'gmx.net',
                'rediffmail.com',
                'att.net',
                'verizon.net',
                'comcast.net',
                'cox.net',
                'sbcglobal.net',
            ];

            if (! in_array($domain, $freeEmailProviders, true)) {
                $webPresence = 'https://'.$domain;
            }
        }

        // ownership_profile
        $ownedPower = (int) ($detail?->owntruck ?? 0) + (int) ($detail?->owntract ?? 0);
        $terminalPower = (int) ($detail?->trmtruck ?? 0) + (int) ($detail?->trmtract ?? 0);
        $tripPower = (int) ($detail?->trptruck ?? 0) + (int) ($detail?->trptract ?? 0);
        $ownershipProfile = match (true) {
            $ownedPower > ($terminalPower + $tripPower) => 'owned_fleet',
            $tripPower > 0 => 'trip_leased',
            default => 'terminal_leased',
        };

        $observedUnits = $carrier->inspections
            ->flatMap(function ($inspection) {

                $units = [];

                // Primary unit
                if (
                    $inspection->vin &&
                    in_array(strtoupper($inspection->unit_type_desc ?? ''), [
                        'TRUCK',
                        'TRUCK TRACTOR',
                        'STRAIGHT TRUCK',
                        'SCHOOL BUS',
                        'BUS',
                        'TRACTOR',
                    ])
                ) {
                    $units[] = $inspection->vin;
                }

                return $units;
            })
            ->unique()
            ->count();
        $observedTrailers = $carrier->inspections
            ->flatMap(function ($inspection) {

                $trailers = [];

                // Secondary unit
                if (
                    $inspection->vin2 &&
                    str_contains(
                        strtoupper($inspection->unit_type_desc2 ?? ''),
                        'TRAILER'
                    )
                ) {
                    $trailers[] = $inspection->vin2;
                }

                // Some inspections may store trailer as primary unit
                if (
                    $inspection->vin &&
                    str_contains(
                        strtoupper($inspection->unit_type_desc ?? ''),
                        'TRAILER'
                    )
                ) {
                    $trailers[] = $inspection->vin;
                }

                return $trailers;
            })
            ->unique()
            ->count();

        // cargo_carried — active cargo types as comma-separated string
        $cargoMap = [
            'crgo_genfreight' => 'General Freight',   'crgo_household' => 'Household Goods',
            'crgo_metalsheet' => 'Metal/Sheet',        'crgo_motoveh' => 'Motor Vehicles',
            'crgo_drivetow' => 'Drive/Tow Away',     'crgo_logpole' => 'Logs/Poles',
            'crgo_bldgmat' => 'Building Materials', 'crgo_mobilehome' => 'Mobile Homes',
            'crgo_machlrg' => 'Machinery/Large',    'crgo_produce' => 'Fresh Produce',
            'crgo_liqgas' => 'Liquids/Gases',      'crgo_intermodal' => 'Intermodal',
            'crgo_passengers' => 'Passengers',         'crgo_oilfield' => 'Oilfield Equipment',
            'crgo_livestock' => 'Livestock',          'crgo_grainfeed' => 'Grain/Feed',
            'crgo_coalcoke' => 'Coal/Coke',          'crgo_meat' => 'Meat',
            'crgo_garbage' => 'Garbage/Refuse',     'crgo_usmail' => 'U.S. Mail',
            'crgo_chem' => 'Chemicals',          'crgo_drybulk' => 'Dry Bulk',
            'crgo_coldfood' => 'Refrigerated Food',  'crgo_beverages' => 'Beverages',
            'crgo_paperprod' => 'Paper Products',     'crgo_utility' => 'Utility',
            'crgo_farmsupp' => 'Farm Supplies',      'crgo_construct' => 'Construction',
            'crgo_waterwell' => 'Water Well',         'crgo_cargoothr' => $detail?->crgo_cargoothr_desc ?? 'Other',
        ];
        $cargoCarried = collect($cargoMap)
            ->filter(fn ($label, $field) => in_array(strtoupper($detail?->$field ?? ''), ['X', 'Y', '1'], true))
            ->values()
            ->implode(', ');

        // docket — first full docket string
        $docket = ($detail?->docket1prefix && $detail?->docket1)
            ? $detail->docket1prefix.$detail->docket1
            : null;

        // indicator_authority
        $indicatorAuthority = $auth?->common_stat === 'A' ||
                              $auth?->contract_stat === 'A' ||
                              $auth?->broker_stat === 'A';

        // OOS rates & alert flags (FMCSA national avg: vehicle 10.8%, driver 4.5%)
        $vehicleInspTotal = (int) ($sms?->vehicle_insp_total ?? 0);
        $vehicleOosTotal = (int) ($sms?->vehicle_oos_insp_total ?? 0);
        $driverInspTotal = (int) ($sms?->driver_insp_total ?? 0);
        $driverOosTotal = (int) ($sms?->driver_oos_insp_total ?? 0);

        $vehicleOosPct = $vehicleInspTotal > 0 ? round($vehicleOosTotal / $vehicleInspTotal * 100, 2) : null;
        $driverOosPct = $driverInspTotal > 0 ? round($driverOosTotal / $driverInspTotal * 100, 2) : null;

        $vehicleOosRate = $vehicleInspTotal > 0 ? $vehicleOosTotal / $vehicleInspTotal : null;
        $driverOosRate = $driverInspTotal > 0 ? $driverOosTotal / $driverInspTotal : null;
        $oosAlertVehicle = $vehicleOosRate !== null && $vehicleOosRate > 0.108;
        $oosAlertDriver = $driverOosRate !== null && $driverOosRate > 0.045;

        // Roadside alert — Unsafe Driving BASIC (FMCSA threshold: 65)
        $basicRoadsideAlertUnsafeDriving = (float) ($sms?->unsafe_driv_measure ?? 0) > 65;

        // Last activity dates
        $lastInspectionDate = $carrier->inspections->max('insp_date');
        $lastViolationDate = $carrier->violationDetails->max('insp_date');
        $lastCrashDate = $carrier->crashes->max('report_date');

        // Crash aggregates
        $crashesTotal = $carrier->crashes->count();
        $crashFatalities = $carrier->crashes->sum('fatalities');
        $crashInjuries = $carrier->crashes->sum('injuries');
        $crashesTowAway = $carrier->crashes->where('tow_away', true)->count();

        // Violations total
        $violationsTotal = $carrier->violationDetails->count();

        // Inspected states
        $inspectedStates = $carrier->inspections->pluck('county_code_state')->filter()->unique()->count();

        // Inspected power units vs trailers (unique VINs)
        $inspectedPowerUnits = $carrier->inspections
            ->filter(fn ($i) => in_array(strtolower($i->unit_type_desc ?? ''), ['truck', 'tractor']))
            ->pluck('vin')->filter()->unique()->count();

        $inspectedTrailers = $carrier->inspections
            ->filter(fn ($i) => str_contains(strtolower($i->unit_type_desc ?? ''), 'trailer'))
            ->pluck('vin')->filter()->unique()->count();

        // Preferred lanes — top 5 states by inspection count
        $stateCounts = $carrier->inspections
            ->pluck('county_code_state')
            ->filter()
            ->countBy()
            ->sortDesc();

        $total = $stateCounts->sum();

        $preferredLanes = $stateCounts
            ->take(6)
            ->map(function ($count, $state) use ($total) {
                return [
                    'state' => $state,
                    'inspection_count' => $count,
                    'percentage' => $total > 0
                        ? round(($count / $total) * 100)
                        : 0,
                ];
            })
            ->values();

        // Per-state inspection counts
        $stateInspectionCounts = $carrier->inspections
            ->pluck('county_code_state')->filter()->countBy()->sortDesc()->toArray();
        $getAuthorityAge = function ($type) use ($carrier) {

            $history = $carrier->authorityHistory
                ->where('mod_col_1', $type)
                ->where('original_action_desc', 'GRANTED')
                ->sortBy('orig_served_date')
                ->first();

            return $history?->orig_served_date
                ? Carbon::parse($history->orig_served_date)->diffInYears(now())
                : null;
        };

        $authorityAgeCommon = $getAuthorityAge('MOTOR PROPERTY COMMON CARRIER');
        $authorityAgeContract = $getAuthorityAge('MOTOR PROPERTY CONTRACT CARRIER');
        $authorityAgeBroker = $getAuthorityAge('PROPERTY BROKER');

        $today = now();

        $totalInspections = $carrier->inspections->count();

        $inspectionsLast120Days = $carrier->inspections
            ->filter(function ($inspection) use ($today) {
                return $inspection->insp_date &&
                    Carbon::parse($inspection->insp_date)->gte($today->copy()->subDays(120));
            })
            ->count();

        $observedLast120Days = $totalInspections > 0
            ? round(($inspectionsLast120Days * 100) / $totalInspections, 1)
            : 0;
        // ════════════════════════════════════════════════════════════════
        // RESPONSE
        // ════════════════════════════════════════════════════════════════

        $trustScore = $this->calculateCarrierTrustScore(
            $carrier,
            $detail,
            $sms,
            $auth,
            $vehicleOosPct,
            $driverOosPct,
            $authorityAgeCommon,
            $authorityAgeContract,
            $authorityAgeBroker,
            $dotAge,
            $mcs150Year,
            $observedUnits,
            $observedTrailers,
            $crashesTotal,
            $crashFatalities,
            $crashInjuries,
            $crashesTowAway
        );

        // Store the score the broker actually saw against the view log.
        $this->logCarrierView($carrier, $trustScore['overall_score'] ?? null);

        // Is this carrier on the company's shortlist?
        $isShortlisted = auth()->check() && CarrierShortlist::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('carrier_id', $carrier->id)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [

                // ── Core identity ─────────────────────────────────────
                'id' => $carrier->id,
                'fmcsa_data' => $fmcsaData['content']['carrier'] ?? null,
                'row_id' => $carrier->row_id,
                'dot_number' => $carrier->dot_number,
                'company_name' => $carrier->legal_name,
                'dba_name' => $carrier->dba_name,
                'carrier_operation' => $carrier->carrier_operation,
                'shortlisted' => $isShortlisted,
                'phone' => $carrier->telephone,
                'fax' => $carrier->fax,
                'email' => $carrier->email_address,
                'crash_rate' => $detail?->recordable_crash_rate ?? 0,
                'duns' => $carrier->dun_bradstreet_nol ?? $carrier->carrierDetail?->dun_bradstreet_no,
                // ── Fleet & drivers ───────────────────────────────────
                'power_unit' => $detail?->power_units ?? 0,
                'driver_total' => $detail?->total_drivers ?? 0,
                'mcs150_mileage' => $carrier->mcs150_mileage,
                'mcs150_mileage_year' => $carrier->mcs150_mileage_year,
                'observed_last_120_days' => [
                    'percentage' => $observedLast120Days,
                    'recent_inspections' => $inspectionsLast120Days,
                    'total_inspections' => $totalInspections,
                ],

                // ── Addresses ─────────────────────────────────────────
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
                'inspection_summary' => [

                    'vehicle' => [
                        'inspections' => $sms?->vehicle_insp_total,
                        'oos_inspections' => $sms?->vehicle_oos_insp_total,
                        'oos_pct' => $vehicleOosPct,
                    ],

                    'driver' => [
                        'inspections' => $sms?->driver_insp_total,
                        'oos_inspections' => $sms?->driver_oos_insp_total,
                        'oos_pct' => $driverOosPct,
                    ],

                    'hazmat' => [
                        'inspections' => $sms?->hazmat_insp_total,
                        'oos_inspections' => $sms?->hazmat_oos_total,
                        'oos_pct' => $sms?->hazmat_insp_total > 0
                            ? round(($sms->hazmat_oos_total / $sms->hazmat_insp_total) * 100, 2)
                            : null,
                    ],
                ],

                // ── Authority & contacts ──────────────────────────────
                'authority' => $carrier->authority,
                'contact_name_counts' => $contactNameCounts,
                'contacts' => $carrier->contacts,
                'oos_orders' => $carrier->oosOrders,
                'authority_orders' => $carrier->authorityOrders,
                'authority_history' => $carrier->authorityHistory,

                // ── SMS / risk ────────────────────────────────────────
                'sms_measures' => [
                    ...(($sms?->toArray()) ?? []),
                    'risk_score' => round($riskScore, 2),
                ],
                'risk_level' => match ($detail->safety_rating) {
                    'S' => 'Satisfactory',
                    'C' => 'Conditional',
                    'U' => 'Unsatisfactory',
                    default => 'Not Rated',
                },
                // ── Inspections ───────────────────────────────────────
                'inspections' => $carrier->inspections->map(function ($inspection) {
                    $data = $inspection->toArray();

                    $data['insp_date'] = $inspection->insp_date
                        ? Carbon::parse($inspection->insp_date)->format('Y-m-d')
                        : null;

                    return $data;
                }),
                'violation_details' => $carrier->violationDetails,

                // ── Crashes ───────────────────────────────────────────
                'crashes' => $carrier->crashes,
                'crash_details' => $carrier->crashDetails,

                // ── Insurance ─────────────────────────────────────────
                'insurance_filings' => $carrier->insuranceFilings
                    ->sortByDesc(function ($item) {
                        return $item->effective_date
                            ? Carbon::parse($item->effective_date)->timestamp
                            : 0;
                    })
                    ->values()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'row_id' => $item->row_id,
                            'dot_number' => $item->dot_number,
                            'docket_number' => $item->docket_number,

                            'effective_date' => $item->effective_date
                                ? Carbon::parse($item->effective_date)->format('Y-m-d')
                                : null,

                            'cancl_effective_date' => $item->cancl_effective_date
                                ? Carbon::parse($item->cancl_effective_date)->format('Y-m-d')
                                : null,

                            'ins_form_code' => $item->ins_form_code,
                            'ins_type_desc' => $item->ins_type_desc,
                            'ins_class_code' => $item->ins_class_code,
                            'ins_type_ind' => $item->ins_type_ind,
                            'policy_no' => $item->policy_no,
                            'name_company' => $item->name_company,
                            'cancl_method' => $item->cancl_method,
                            'cancl_method_gen' => $item->cancl_method_gen,
                            'inser_branch' => $item->inser_branch,

                            'min_cov_amount' => number_format((float) $item->min_cov_amount * 1000, 2, '.', ''),
                            'max_cov_amount' => number_format((float) $item->max_cov_amount * 1000, 2, '.', ''),
                            'underl_lim_amount' => number_format((float) $item->underl_lim_amount * 1000, 2, '.', ''),
                        ];
                    }),
                'insurance_summary' => [
                    'bipd_coverage_total_amount' => $bipdTotal,
                    'cargo_insurance_total_amount' => $cargoTotal,
                    'bond_total_amount' => $bondTotal,
                ],
                'insurance_filings_pending' => $carrier->insuranceFilingsPending,
                'insurance_filings_history' => $carrier->insuranceFilingsHistory,

                // ── Company snapshot ──────────────────────────────────
                'company_snapshot' => [
                    'authorized_for_hire' => $carrier->authorized_for_hire,
                    'exempt_for_hire' => $carrier->exempt_for_hire,
                    'private_property' => $carrier->private_property,
                    'private_passenger_business' => $carrier->private_passenger_business,
                    'private_passenger_nonbusiness' => $carrier->private_passenger_nonbusiness,
                    'migrant' => $carrier->migrant,
                ],

                // ── Raw carrier detail record ─────────────────────────
                'carrier_detail' => $detail,

                // ── Computed fields ───────────────────────────────────
                'computed' => [

                    // S1 — Carrier level
                    'dot_age' => $dotAge,
                    'status_code' => match (strtoupper($detail?->status_code ?? '')) {
                        'A' => 'Active',
                        'I' => 'Inactive',
                        default => $detail?->status_code,
                    },
                    'authority_age_common' => $authorityAgeCommon,
                    'authority_age_contract' => $authorityAgeContract,
                    'authority_age_broker' => $authorityAgeBroker,

                    'web_presence' => $webPresence,
                    'mcs150_year' => $mcs150Year,
                    'snapshot_date' => now()->toDateString(),
                    'out_of_service_flag' => $outOfServiceFlag,
                    'email_domain' => $emailDomain,
                    'ownership_profile' => $ownershipProfile,
                    'cargo_carried' => $cargoCarried ?: null,
                    'docket' => $docket,
                    'indicator_authority' => $indicatorAuthority,
                    'observed_units' => $observedUnits,
                    'observed_trailers' => $observedTrailers,
                    'observed_units_status' => $observedUnits > 0
                    ? 'Tracked'
                    : 'Not Tracked',

                    'observed_trailers_status' => $observedTrailers > 0
                        ? 'Tracked'
                        : 'Not Tracked',
                    // S2 — SMS derived
                    'inspections_vehicle_out_of_service_pct' => $sms->vehicle_oos_insp_total,
                    'inspections_driver_out_of_service_pct' => $driverOosPct,
                    'oos_alert_vehicle' => $oosAlertVehicle,
                    'oos_alert_driver' => $oosAlertDriver,
                    'oos_alert_hazmat' => false, // needs hazmat_oos_rate column
                    'basic_roadside_alert_unsafe_driving' => $basicRoadsideAlertUnsafeDriving,
                    'last_inspection_date' => $lastInspectionDate
                        ? Carbon::parse($lastInspectionDate)->format('d-m-y')
                        : null,

                    'last_violation_date' => $lastViolationDate
                        ? Carbon::parse($lastViolationDate)->format('d-m-y')
                        : null,

                    'last_crash_date' => $lastCrashDate
                        ? Carbon::parse($lastCrashDate)->format('d-m-y')
                        : null,
                    'crashes_total' => $crashesTotal,
                    'crash_fatalities' => $crashFatalities,
                    'crash_injuries' => $crashInjuries,
                    'crashes_tow_away' => $crashesTowAway,
                    'violations_total' => $violationsTotal,
                    'inspected_states' => $inspectedStates,
                    'inspected_power_units' => $inspectedPowerUnits,
                    'inspected_trailers' => $inspectedTrailers,
                    'preferred_lanes' => $preferredLanes,

                    // S3 — Per-state inspection counts
                    'state_inspection_counts' => $stateInspectionCounts,
                    'carrier_trust_score' => $trustScore,
                ],
            ],
        ]);
    }

    private function parseFmcsaDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            // FMCSA format: 01-JUN-74
            if (preg_match('/^\d{2}-[A-Z]{3}-\d{2}$/', strtoupper($value))) {
                $year = substr($value, -2);
                $century = $year > date('y') ? '19' : '20';
                $fixed = substr($value, 0, -2).$century.$year;

                return Carbon::createFromFormat('d-M-Y', strtoupper($fixed));
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** factor => [category, polarity_good, strength_label, weakness_label, severity, live] */
    private const META = [
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

    private const MAIL_DROP_PATTERNS = [
        'UPS STORE', 'REGUS', 'WEWORK', 'PMB ', 'POSTAL ANNEX',
        'MAIL BOXES ETC', 'MAILBOX', 'REGISTERED AGENT', 'VIRTUAL OFFICE', 'SUITE #',
    ];

    /** state => comma list of area codes (unknown code => factor returns null, lands in monitoring) */
    private const AREA_CODES = [
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

    // ----------------------------------------------------------- thresholds

    /**
     * National benchmarks, cached 1h. Cache — not a table. Real-time enough:
     * national aggregates don't move intraday.
     */
    private function benchmarks(): array
    {
        return Cache::remember('dt_risk_benchmarks', 3600, function () {
            $b = [];

            $b['natl_vehicle_oos'] = (float) (SmsMeasure::query()
                ->where('vehicle_insp_total', '>=', 5)
                ->selectRaw('AVG(vehicle_oos_insp_total / NULLIF(vehicle_insp_total,0)) AS avg_v_oos')
                ->value('avg_v_oos') ?? 0.208);

            foreach (['unsafe_driv_measure', 'hos_driv_measure', 'driv_fit_measure',
                'contr_subst_measure', 'veh_maint_measure'] as $m) {
                $cnt = SmsMeasure::query()->whereNotNull($m)->count();
                if ($cnt > 100) {
                    $offset = (int) floor($cnt * 0.90);
                    $b["p90_$m"] = (float) SmsMeasure::query()
                        ->whereNotNull($m)
                        ->orderBy($m)
                        ->skip($offset)
                        ->value($m);
                } else {
                    $b["p90_$m"] = null;
                }
            }

            $ratioQueries = [
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

            foreach ($ratioQueries as $key => $builder) {
                $cnt = DB::connection('external_db')->query()->fromSub($builder, 't')->count();
                if ($cnt > 100) {
                    $lo = (int) floor($cnt * 0.05);
                    $hi = (int) floor($cnt * 0.95);
                    $b["{$key}_lo"] = (float) DB::connection('external_db')->query()->fromSub($builder, 't')->orderBy('r')->skip($lo)->value('r');
                    $b["{$key}_hi"] = (float) DB::connection('external_db')->query()->fromSub($builder, 't')->orderBy('r')->skip($hi)->value('r');
                } else {
                    $b["{$key}_lo"] = $b["{$key}_hi"] = null;
                }
            }

            return $b;
        });
    }

    // ------------------------------------------------------------- assembly

    public function riskFactors(string $dot): ?array
    {

        DB::connection('external_db')->enableQueryLog();

        $start = microtime(true);
        Log::info('Starting riskFactors for DOT: '.$start);

        $carrier = Carrier::query()->where('dot_number', $dot)->first();

        if (! $carrier) {
            return null;
        }

        $cd = CarrierDetail::query()->where('dot_number', $dot)->first();
        $sms = SmsMeasure::query()->where('dot_number', $dot)->first();

        // ---- authority ----
        $authorities = CarrierAuthority::query()->where('dot_number', $dot)->get();
        $hasActiveAuth = $authorities->contains(fn ($a) => $a->common_stat === 'A' || $a->contract_stat === 'A' || $a->broker_stat === 'A');
        $hasActiveBroker = $authorities->contains(fn ($a) => $a->broker_stat === 'A');
        $hasActiveCarrier = $authorities->contains(fn ($a) => $a->common_stat === 'A' || $a->contract_stat === 'A');
        $latestAuthority = $authorities->sortByDesc('id')->first();
        $bipdRequired = $latestAuthority?->min_cov_amount !== null ? (float) $latestAuthority->min_cov_amount : 750000.0;

        $authorityOrders = CarrierAuthorityOrder::query()->where('dot_number', $dot)->get();
        $revocationLast36mo = $authorityOrders->contains(function ($o) {
            $date = $this->parseFmcsaDate($o->order2_effective_date);

            return $date && $date->gt(now()->subMonths(36));
        });
        $consecutiveAuthority = $authorityOrders->isEmpty() && $hasActiveAuth;

        // ---- OOS / BOC-3 ----
        $oosOrders = CarrierOosOrder::query()->where('dot_number', $dot)->get();
        $dotOutOfService = $oosOrders->contains(fn ($o) => empty($o->rescind_date));

        $boc3OnFile = CarrierContact::query()->where('dot_number', $dot)->exists();

        // ---- insurance ----
        $insuranceFilings = InsuranceFiling::query()->where('dot_number', $dot)->get();

        $notCancelledOrFuture = function ($f) {
            if (empty($f->cancl_effective_date)) {
                return true;
            }
            $date = $this->parseFmcsaDate($f->cancl_effective_date);

            return $date && $date->isFuture();
        };

        $bipdOnFile = $insuranceFilings
            ->filter(fn ($f) => str_contains($f->ins_form_code ?? '', '91') && ! str_contains($f->ins_form_code ?? '', '91X'))
            ->filter($notCancelledOrFuture)
            ->max('max_cov_amount');
        $bipdOnFile = $bipdOnFile !== null ? (float) $bipdOnFile : null;

        $cargoInsuranceOnFile = $insuranceFilings
            ->filter(fn ($f) => str_contains($f->ins_form_code ?? '', '91X'))
            ->contains($notCancelledOrFuture);

        $pendingInsuranceCancellation = $insuranceFilings->contains(function ($f) {
            if (empty($f->cancl_effective_date)) {
                return false;
            }
            $date = $this->parseFmcsaDate($f->cancl_effective_date);

            return $date && $date->isFuture();
        });

        $insRrgActive = $insuranceFilings
            ->filter(fn ($f) => str_contains(strtoupper($f->ins_type_desc ?? ''), 'RISK RETENTION') || strtoupper($f->ins_type_ind ?? '') === 'RRG')
            ->contains($notCancelledOrFuture);

        $insuranceHistory = InsuranceFilingHistory::query()->where('dot_number', $dot)->get();
        $insRrgHist = $insuranceHistory->contains(fn ($h) => str_contains(strtoupper($h->ins_type_desc ?? ''), 'RISK RETENTION'));
        $stabilityInsuranceHistory = ! $insuranceHistory->contains(function ($h) {
            if (empty($h->cancl_effective_date)) {
                return false;
            }
            $date = $this->parseFmcsaDate($h->cancl_effective_date);

            return $date && $date->gt(now()->subMonths(36));
        });

        // ---- violations / crashes ----
        $violationsSevereFlag = ViolationDetail::query()->where('dot_number', $dot)->where('severity_weight', '>=', 8)->exists();
        $fatalCrashesFlag = Crash::query()->where('dot_number', $dot)->where('fatalities', '>', 0)->exists();

        // ---- inspections ----
        $inspections = Inspection::query()->where('dot_number', $dot)->get();

        $zeroInspectionsLast12mo = ! $inspections->contains(function ($i) {
            $date = $this->parseFmcsaDate($i->insp_date);

            return $date && $date->gt(now()->subMonths(12));
        });

        $inspectedStates = $inspections->pluck('county_code_state')->filter()->unique()->count();

        $insp24mo = $inspections->filter(function ($i) {
            $date = $this->parseFmcsaDate($i->insp_date);

            return $date && $date->gt(now()->subMonths(24));
        });
        $insp24moCount = $insp24mo->count();
        $insp24moLvl3 = $insp24mo->where('insp_level_id', 3)->count();

        $inspectedUnits = $inspections->pluck('vin')->filter()->unique()->count();

        $isInterstate = $carrier->carrier_operation === 'A';
        $mileage = (int) ($carrier->mcs150_mileage ?: 0);
        $powerUnits = (int) ($carrier->nbr_power_unit ?? 0);

        // ---- network graph — cross-carrier lookups. Index telephone, email_address,
        //      (phy_state, phy_city, phy_street), carrier_details.dun_bradstreet_no,
        //      and inspections.vin or these will be full scans. ----
        $indicatorNetworkGraphPhone = ! empty($carrier->telephone) && Carrier::query()
            ->where('telephone', $carrier->telephone)
            ->where('dot_number', '<>', $dot)
            ->exists();

        $indicatorNetworkGraphAddress = ! empty($carrier->phy_street)
            && strlen(trim($carrier->phy_street)) > 5
            && Carrier::query()
                ->where('phy_state', $carrier->phy_state)
                ->where('phy_city', $carrier->phy_city)
                ->where('phy_street', $carrier->phy_street)
                ->where('dot_number', '<>', $dot)
                ->exists();

        $indicatorNetworkGraphEmail = ! empty($carrier->email_address) && Carrier::query()
            ->where('email_address', $carrier->email_address)
            ->where('dot_number', '<>', $dot)
            ->exists();

        $indicatorNetworkGraphDuns = ! empty($cd?->dun_bradstreet_no)
            && strlen(trim($cd->dun_bradstreet_no)) > 3
            && CarrierDetail::query()
                ->where('dun_bradstreet_no', $cd->dun_bradstreet_no)
                ->where('dot_number', '<>', $dot)
                ->exists();

        $sharedVins = $inspections->pluck('vin')->filter(fn ($v) => strlen((string) $v) === 17)->unique();
        $highSharedPowerUnits = false;
        if ($sharedVins->isNotEmpty()) {
            $sharingDots = Inspection::query()
                ->whereIn('vin', $sharedVins)
                ->where('dot_number', '<>', $dot)
                ->distinct()
                ->count('dot_number');
            $highSharedPowerUnits = $sharingDots >= 3;
        }

        // ---- cargo classifications ----
        $cargoFields = [
            'crgo_genfreight', 'crgo_household', 'crgo_metalsheet', 'crgo_motoveh', 'crgo_drivetow',
            'crgo_logpole', 'crgo_bldgmat', 'crgo_mobilehome', 'crgo_machlrg', 'crgo_produce', 'crgo_liqgas',
            'crgo_intermodal', 'crgo_oilfield', 'crgo_livestock', 'crgo_grainfeed', 'crgo_coalcoke', 'crgo_meat',
            'crgo_garbage', 'crgo_usmail', 'crgo_chem', 'crgo_drybulk', 'crgo_coldfood', 'crgo_beverages',
            'crgo_paperprod', 'crgo_utility', 'crgo_farmsupp', 'crgo_construct', 'crgo_waterwell',
        ];
        $cargoCount = collect($cargoFields)->filter(fn ($f) => strtoupper($cd?->$f ?? '') === 'X')->count();

        // ---- benchmarks / thresholds ----
        $bm = $this->benchmarks();

        $oosBelow = null;
        if (($sms?->vehicle_insp_total ?? 0) >= 5) {
            $oosBelow = ($sms->vehicle_oos_insp_total / max(1, $sms->vehicle_insp_total)) < $bm['natl_vehicle_oos'];
        }

        $alert = false;
        if ($sms) {
            foreach ([
                ['unsafe_driv_measure', 'p90_unsafe_driv_measure'],
                ['hos_driv_measure', 'p90_hos_driv_measure'],
                ['driv_fit_measure', 'p90_driv_fit_measure'],
                ['contr_subst_measure', 'p90_contr_subst_measure'],
                ['veh_maint_measure', 'p90_veh_maint_measure'],
            ] as [$mk, $bk]) {
                if ($sms->$mk !== null && $bm[$bk] !== null && (float) $sms->$mk >= $bm[$bk]) {
                    $alert = true;
                    break;
                }
            }
        }

        $ipuFlag = null;
        if ($powerUnits > 0 && $inspectedUnits > 0) {
            $ipuFlag = ($inspectedUnits / $powerUnits) > 3.0;
        }

        $imFlag = null;
        if ($mileage > 0 && ($sms?->insp_total ?? 0) > 0 && $bm['im_lo'] !== null) {
            $ratio = $sms->insp_total / $mileage;
            $imFlag = ($ratio < $bm['im_lo'] || $ratio > $bm['im_hi']);
        }

        $pumFlag = null;
        if ($mileage > 0 && $powerUnits > 0 && $bm['pum_lo'] !== null) {
            $ratio = $powerUnits / $mileage;
            $pumFlag = ($ratio < $bm['pum_lo'] || $ratio > $bm['pum_hi']);
        }

        $driverOnly = null;
        if ($insp24moCount >= 5) {
            $driverOnly = ($insp24moLvl3 / $insp24moCount) > 0.8;
        }

        // ---- string-based factors ----
        $street = strtoupper(($carrier->phy_street ?? '').' | '.($carrier->mailing_street ?? ''));
        $virtual = collect(self::MAIL_DROP_PATTERNS)->contains(fn ($p) => str_contains($street, $p));

        $areaMatch = null;
        $digits = preg_replace('/[^0-9]/', '', (string) ($carrier->telephone ?? ''));
        $code = strlen($digits) >= 10 ? substr($digits, -10, 3) : null;
        if ($code && $carrier->phy_state && isset(self::AREA_CODES[$carrier->phy_state])) {
            $areaMatch = in_array($code, explode(',', self::AREA_CODES[$carrier->phy_state]), true);
        }

        $addDate = $this->parseFmcsaDate($carrier->add_date);
        $mcs150Date = $this->parseFmcsaDate($carrier->mcs150_date);
        $time = microtime(true) - $start;

        Log::info('Starting riskFactors for DOT: '.$time);

        Log::info(DB::connection('external_db')->getQueryLog());

        return [
            'consecutive_authority' => $consecutiveAuthority,
            'sixty_mo_in_business' => $addDate ? $addDate->lte(now()->subMonths(60)) : null,
            'revocation_last_thirtysix_mo' => $revocationLast36mo,
            'indicator_network_graph_phone' => $indicatorNetworkGraphPhone,
            'indicator_network_graph_address' => $indicatorNetworkGraphAddress,
            'indicator_network_graph_email' => $indicatorNetworkGraphEmail,
            'indicator_network_graph_ein' => null,
            'indicator_network_graph_duns' => $indicatorNetworkGraphDuns,
            'high_shared_power_units' => $highSharedPowerUnits,
            'indicator_benchmark_inspected_power_units_ratio' => $ipuFlag,
            'indicator_benchmark_inspection_mileage_ratio' => $imFlag,
            'indicator_benchmark_power_unit_mileage_ratio' => $pumFlag,
            'zero_inspections_last_twelve_mo' => $zeroInspectionsLast12mo,
            'active_usdot_status' => $cd ? ($cd->status_code === 'A') : null,
            'violations_severe_flag' => $violationsSevereFlag,
            'basic_alert_flag' => $alert,
            'basic_ac_indicator_flag' => $sms
                ? ((bool) $sms->unsafe_driv_ac || (bool) $sms->hos_driv_ac || (bool) $sms->driv_fit_ac
                    || (bool) $sms->contr_subst_ac || (bool) $sms->veh_maint_ac)
                : null,
            'fatal_crashes_flag' => $fatalCrashesFlag,
            'safety_rating_unsatisfactory_conditional' => $cd
                ? in_array($cd->safety_rating, ['U', 'C', 'UNSATISFACTORY', 'CONDITIONAL'], true)
                : null,
            'secondary_contact_info_provided' => $cd
                ? (! empty($cd->company_officer_2) || ! empty($cd->cell_phone))
                : null,
            'primary_contact_info_missing' => empty($cd?->company_officer_1) || empty($carrier->email_address),
            'carrier_w_brokerage_authority' => $hasActiveBroker && $hasActiveCarrier,
            'multi_cargo_classification' => $cargoCount > 3,
            'interstate_carrier_single_state_inspection' => $isInterstate && $inspectedStates === 1,
            'bipd_insurance_above_minimum' => $bipdOnFile !== null && $bipdOnFile > $bipdRequired,
            'bipd_insurance_below_requirement' => $hasActiveAuth && ($bipdOnFile ?? 0) < $bipdRequired,
            'cargo_insurance_on_file' => $cargoInsuranceOnFile,
            'boc3_on_file' => $boc3OnFile,
            'pending_insurance_cancellation' => $pendingInsuranceCancellation,
            'ins_rrg_active' => $insRrgActive,
            'ins_rrg_hist' => $insRrgHist,
            'no_active_authority' => ! $hasActiveAuth,
            'not_authorized_for_hire' => ! in_array($carrier->authorized_for_hire, ['Y', 'X', '1'], true),
            'mcs150_filed_last_24_months' => $mcs150Date ? $mcs150Date->gt(now()->subMonths(24)) : null,
            'oos_below_industry_average' => $oosBelow,
            'smartway_flag' => null,
            'carbtru_flag' => null,
            'phmsa_flag' => null,
            'hazardous_material' => ($carrier->hm_flag === 'Y') || ($cd?->hm_ind === 'Y'),
            'virtual_physical_mailing_address' => $virtual,
            'phone_number_area_codes_match_address_state' => $areaMatch,
            'stability_name_history' => null,
            'stability_email_history' => null,
            'stability_phone_history' => null,
            'stability_address_history' => null,
            'stability_contact_history' => null,
            'stability_insurance_history' => $stabilityInsuranceHistory,
            'dot_out_of_service' => $dotOutOfService,
            'driver_only_inspections' => $driverOnly,
        ];
    }

    public function strengthsWeaknesses(string $dot): ?array
    {
        $factors = $this->riskFactors($dot);
        if ($factors === null) {
            return null;
        }

        $strengths = $weaknesses = $monitoring = [];
        foreach (self::META as $key => [$category, $good, $sLabel, $wLabel, $severity, $live]) {
            $val = $factors[$key] ?? null;
            if (! $live || $val === null) {
                $monitoring[] = ['key' => $key, 'label' => $sLabel ?? $wLabel, 'category' => $category];

                continue;
            }
            if ($val === $good) {
                if ($sLabel !== null) {
                    $strengths[] = ['key' => $key, 'label' => $sLabel, 'category' => $category, 'value' => $val];
                }
            } elseif ($wLabel !== null) {
                $weaknesses[] = ['key' => $key, 'label' => $wLabel, 'category' => $category,
                    'severity' => $severity, 'value' => $val];
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

    public function show(string $dot)
    {
        $result = $this->strengthsWeaknesses($dot);

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Carrier not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function association2($dot)
    {
        $sql = <<<'SQL'
SELECT *
FROM (

    /* EMAIL */
    SELECT
        'EMAIL' AS match_type,
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.email_address = c2.email_address
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number
      AND c1.email_address IS NOT NULL
      AND c1.email_address <> ''

    UNION ALL

    /* PHONE */
    SELECT
        'PHONE',
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.telephone = c2.telephone
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number
      AND c1.telephone IS NOT NULL
      AND c1.telephone <> ''

    UNION ALL

    /* FAX */
    SELECT
        'FAX',
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.fax = c2.fax
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number
      AND c1.fax IS NOT NULL
      AND c1.fax <> ''

    UNION ALL

    /* LEGAL NAME */
    SELECT
        'LEGAL NAME',
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.legal_name = c2.legal_name
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number
      AND c1.legal_name IS NOT NULL
      AND c1.legal_name <> ''

    UNION ALL

    /* DBA NAME */
    SELECT
        'DBA NAME',
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.dba_name = c2.dba_name
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number
      AND c1.dba_name IS NOT NULL
      AND c1.dba_name <> ''

    UNION ALL

    /* PHYSICAL ADDRESS */
    SELECT
        'PHYSICAL ADDRESS',
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.phy_street = c2.phy_street
       AND c1.phy_city = c2.phy_city
       AND c1.phy_state = c2.phy_state
       AND c1.phy_zip = c2.phy_zip
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number

    UNION ALL

    /* MAILING ADDRESS */
    SELECT
        'MAILING ADDRESS',
        c2.dot_number,
        c2.legal_name,
        c2.dba_name,
        c2.telephone,
        c2.fax,
        c2.email_address
    FROM carriers c1
    JOIN carriers c2
        ON c1.mailing_street = c2.mailing_street
       AND c1.mailing_city = c2.mailing_city
       AND c1.mailing_state = c2.mailing_state
       AND c1.mailing_zip = c2.mailing_zip
    WHERE c1.dot_number = ?
      AND c2.dot_number <> c1.dot_number

) associations
ORDER BY legal_name, dot_number;
SQL;

        $associations = DB::connection('external_db')->select($sql, [
            $dot,
            $dot,
            $dot,
            $dot,
            $dot,
            $dot,
            $dot,
        ]);

        return response()->json([
            'success' => true,
            'count' => count($associations),
            'data' => $associations,
        ]);
    }

    public function association3($dot)
    {
        $sql = <<<'SQL'
        SELECT *
        FROM (

            /* EMAIL */
            SELECT
                'EMAIL' AS match_type,
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON c1.email_address = c2.email_address
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number
              AND c1.email_address IS NOT NULL
              AND c1.email_address <> ''

            UNION ALL

            /* PHONE */
            SELECT
                'PHONE',
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON c1.telephone = c2.telephone
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number
              AND c1.telephone IS NOT NULL
              AND c1.telephone <> ''

            UNION ALL

            /* FAX */
            SELECT
                'FAX',
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON c1.fax = c2.fax
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number
              AND c1.fax IS NOT NULL
              AND c1.fax <> ''

            UNION ALL

            /* LEGAL NAME */
            SELECT
                'LEGAL NAME',
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON UPPER(TRIM(c1.legal_name)) = UPPER(TRIM(c2.legal_name))
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number
              AND c1.legal_name IS NOT NULL
              AND c1.legal_name <> ''

            UNION ALL

            /* DBA NAME */
            SELECT
                'DBA NAME',
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON UPPER(TRIM(c1.dba_name)) = UPPER(TRIM(c2.dba_name))
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number
              AND c1.dba_name IS NOT NULL
              AND c1.dba_name <> ''

            UNION ALL

            /* PHYSICAL ADDRESS */
            SELECT
                'PHYSICAL ADDRESS',
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON c1.phy_street = c2.phy_street
               AND c1.phy_city = c2.phy_city
               AND c1.phy_state = c2.phy_state
               AND c1.phy_zip = c2.phy_zip
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number

            UNION ALL

            /* MAILING ADDRESS */
            SELECT
                'MAILING ADDRESS',
                c2.dot_number,
                c2.legal_name,
                c2.dba_name,
                c2.telephone,
                c2.fax,
                c2.email_address
            FROM carriers c1
            JOIN carriers c2
                ON c1.mailing_street = c2.mailing_street
               AND c1.mailing_city = c2.mailing_city
               AND c1.mailing_state = c2.mailing_state
               AND c1.mailing_zip = c2.mailing_zip
            WHERE c1.dot_number = ?
              AND c2.dot_number <> c1.dot_number

        ) AS associations
        ORDER BY legal_name, dot_number
    SQL;

        $associations = DB::connection('external_db')->select($sql, [
            $dot, // EMAIL
            $dot, // PHONE
            $dot, // FAX
            $dot, // LEGAL NAME
            $dot, // DBA NAME
            $dot, // PHYSICAL ADDRESS
            $dot, // MAILING ADDRESS
        ]);

        return response()->json([
            'success' => true,
            'count' => count($associations),
            'data' => $associations,
        ]);
    }

    public function association5($dot)
    {
        // 1. Get the carrier's own record (fast, indexed lookup on dot_number)
        $carrier = DB::connection('external_db')->selectOne(
            'SELECT dot_number, legal_name, dba_name, telephone, fax, email_address,
                phy_street, phy_city, phy_state, phy_zip,
                mailing_street, mailing_city, mailing_state, mailing_zip
         FROM carriers
         WHERE dot_number = ?',
            [$dot]
        );

        if (! $carrier) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
            ]);
        }

        $blocks = [];
        $bindings = [];

        if (! empty($carrier->email_address)) {
            $blocks[] = "SELECT 'EMAIL' AS match_type, dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE email_address = ? AND dot_number <> ?";
            $bindings[] = $carrier->email_address;
            $bindings[] = $dot;
        }

        if (! empty($carrier->telephone)) {
            $blocks[] = "SELECT 'PHONE', dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE telephone = ? AND dot_number <> ?";
            $bindings[] = $carrier->telephone;
            $bindings[] = $dot;
        }

        if (! empty($carrier->fax)) {
            $blocks[] = "SELECT 'FAX', dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE fax = ? AND dot_number <> ?";
            $bindings[] = $carrier->fax;
            $bindings[] = $dot;
        }

        if (! empty($carrier->legal_name)) {
            $blocks[] = "SELECT 'LEGAL NAME', dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE UPPER(TRIM(legal_name)) = ? AND dot_number <> ?";
            $bindings[] = mb_strtoupper(trim($carrier->legal_name));
            $bindings[] = $dot;
        }

        if (! empty($carrier->dba_name)) {
            $blocks[] = "SELECT 'DBA NAME', dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE UPPER(TRIM(dba_name)) = ? AND dot_number <> ?";
            $bindings[] = mb_strtoupper(trim($carrier->dba_name));
            $bindings[] = $dot;
        }

        if (! empty($carrier->phy_street) && ! empty($carrier->phy_city)
            && ! empty($carrier->phy_state) && ! empty($carrier->phy_zip)) {
            $blocks[] = "SELECT 'PHYSICAL ADDRESS', dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE phy_street = ? AND phy_city = ? AND phy_state = ? AND phy_zip = ?
                        AND dot_number <> ?";
            $bindings[] = $carrier->phy_street;
            $bindings[] = $carrier->phy_city;
            $bindings[] = $carrier->phy_state;
            $bindings[] = $carrier->phy_zip;
            $bindings[] = $dot;
        }

        if (! empty($carrier->mailing_street) && ! empty($carrier->mailing_city)
            && ! empty($carrier->mailing_state) && ! empty($carrier->mailing_zip)) {
            $blocks[] = "SELECT 'MAILING ADDRESS', dot_number, legal_name, dba_name, telephone, fax, email_address
                      FROM carriers
                      WHERE mailing_street = ? AND mailing_city = ? AND mailing_state = ? AND mailing_zip = ?
                        AND dot_number <> ?";
            $bindings[] = $carrier->mailing_street;
            $bindings[] = $carrier->mailing_city;
            $bindings[] = $carrier->mailing_state;
            $bindings[] = $carrier->mailing_zip;
            $bindings[] = $dot;
        }

        if (empty($blocks)) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
            ]);
        }

        $sql = '('.implode(') UNION ALL (', $blocks).') ORDER BY legal_name, dot_number';

        $associations = DB::connection('external_db')->select($sql, $bindings);

        return response()->json([
            'success' => true,
            'count' => count($associations),
            'data' => $associations,
        ]);
    }

    public function association($dot)
    {
        DB::connection('external_db')->enableQueryLog();

        $start = microtime(true);
        Log::info('Starting riskFactors for DOT: '.$start);
        $carrier = $this->getCarrier($dot);

        if (! $carrier) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
            ]);
        }

        $blocks = [];
        $bindings = [];

        // Email
        $this->addSimpleMatch(
            $blocks,
            $bindings,
            'EMAIL',
            'email_address',
            $carrier->email_address,
            $dot
        );

        // Phone
        $this->addSimpleMatch(
            $blocks,
            $bindings,
            'PHONE',
            'telephone',
            $carrier->telephone,
            $dot
        );

        // Fax
        $this->addSimpleMatch(
            $blocks,
            $bindings,
            'FAX',
            'fax',
            $carrier->fax,
            $dot
        );

        // Legal Name
        // Legal Name
        if (! empty($carrier->legal_name)) {
            $this->addMatch(
                $blocks,
                $bindings,
                'LEGAL NAME',
                'legal_name = ?',
                [trim($carrier->legal_name)],
                $dot
            );
        }

        // DBA Name
        if (! empty($carrier->dba_name)) {
            $this->addMatch(
                $blocks,
                $bindings,
                'DBA NAME',
                'dba_name = ?',
                [trim($carrier->dba_name)],
                $dot
            );
        }

        // Physical Address
        if (
            ! empty($carrier->phy_street) &&
            ! empty($carrier->phy_city) &&
            ! empty($carrier->phy_state) &&
            ! empty($carrier->phy_zip)
        ) {
            $this->addMatch(
                $blocks,
                $bindings,
                'PHYSICAL ADDRESS',
                'phy_street=? AND phy_city=? AND phy_state=? AND phy_zip=?',
                [
                    $carrier->phy_street,
                    $carrier->phy_city,
                    $carrier->phy_state,
                    $carrier->phy_zip,
                ],
                $dot
            );
        }

        // Mailing Address
        if (
            ! empty($carrier->mailing_street) &&
            ! empty($carrier->mailing_city) &&
            ! empty($carrier->mailing_state) &&
            ! empty($carrier->mailing_zip)
        ) {
            $this->addMatch(
                $blocks,
                $bindings,
                'MAILING ADDRESS',
                'mailing_street=? AND mailing_city=? AND mailing_state=? AND mailing_zip=?',
                [
                    $carrier->mailing_street,
                    $carrier->mailing_city,
                    $carrier->mailing_state,
                    $carrier->mailing_zip,
                ],
                $dot
            );
        }

        if (empty($blocks)) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
            ]);
        }

        $sql = implode(' UNION ALL ', $blocks).' ORDER BY legal_name, dot_number';

        $associations = DB::connection('external_db')->select($sql, $bindings);
        $time = microtime(true) - $start;

        Log::info('Starting riskFactors for DOT: '.$time);

        Log::info(DB::connection('external_db')->getQueryLog());

        return response()->json([
            'success' => true,
            'count' => count($associations),
            'data' => $associations,
        ]);
    }

    private function getCarrier($dot)
    {
        return DB::connection('external_db')->selectOne('
            SELECT
                dot_number,
                legal_name,
                dba_name,
                telephone,
                fax,
                email_address,
                phy_street,
                phy_city,
                phy_state,
                phy_zip,
                mailing_street,
                mailing_city,
                mailing_state,
                mailing_zip
            FROM carriers
            WHERE dot_number = ?
            LIMIT 1
        ', [$dot]);
    }

    private function addSimpleMatch(&$blocks, &$bindings, $label, $column, $value, $dot)
    {
        if (empty($value)) {
            return;
        }

        $this->addMatch(
            $blocks,
            $bindings,
            $label,
            "{$column} = ?",
            [$value],
            $dot
        );
    }

    private function addMatch(&$blocks, &$bindings, $label, $where, array $values, $dot)
    {
        $blocks[] = "
        SELECT
            '{$label}' AS match_type,
            dot_number,
            legal_name,
            dba_name,
            telephone,
            fax,
            email_address,
            mcs150_mileage AS annual_mileage,
            (
                SELECT docket_number
                FROM carrier_authorities ca
                WHERE ca.dot_number = carriers.dot_number
                LIMIT 1
            ) AS mc_number,
            (
                SELECT dun_bradstreet_no
                FROM carrier_details cd
                WHERE cd.dot_number = carriers.dot_number
                LIMIT 1
            ) AS duns_number,
           (
    SELECT CASE fleetsize
        WHEN 'A' THEN '1'
        WHEN 'B' THEN '2-3'
        WHEN 'C' THEN '4-6'
        WHEN 'D' THEN '7-8'
        WHEN 'E' THEN '9-11'
        WHEN 'F' THEN '12-14'
        WHEN 'G' THEN '15-17'
        WHEN 'H' THEN '18-19'
        WHEN 'I' THEN '20-23'
        WHEN 'J' THEN '24-28'
        WHEN 'K' THEN '29-32'
        WHEN 'L' THEN '33-38'
        WHEN 'M' THEN '39-44'
        WHEN 'N' THEN '45-55'
        WHEN 'O' THEN '56-75'
        WHEN 'P' THEN '76-100'
        WHEN 'Q' THEN '101-200'
        WHEN 'R' THEN '201-300'
        WHEN 'S' THEN '301-400'
        WHEN 'T' THEN '401-550'
        WHEN 'U' THEN '551-999'
        WHEN 'V' THEN '1000-2000'
        WHEN 'W' THEN '2001-3000'
        WHEN 'X' THEN '3001-4000'
        WHEN 'Y' THEN '4001-5000'
        WHEN 'Z' THEN 'OVER 5000'
        ELSE NULL
    END
    FROM carrier_details cd
    WHERE cd.dot_number = carriers.dot_number
    LIMIT 1
) AS fleet_size
        FROM carriers
        WHERE {$where}
          AND dot_number <> ?
    ";

        foreach ($values as $value) {
            $bindings[] = $value;
        }

        $bindings[] = $dot;
    }

    public function vinAssociation($dot)
    {
        $sql = <<<'SQL'
WITH target_vins AS (
    SELECT vin
    FROM inspections
    WHERE dot_number = ? AND vin IS NOT NULL AND vin <> ''

    UNION

    SELECT vin2
    FROM inspections
    WHERE dot_number = ? AND vin2 IS NOT NULL AND vin2 <> ''
)

SELECT
    'VIN' AS match_type,
    tv.vin AS matched_vin,
    i.dot_number,
    c2.legal_name,
    c2.dba_name,
    c2.telephone,
    c2.fax,
    c2.email_address
FROM target_vins tv
JOIN inspections i
    ON i.vin = tv.vin
JOIN carriers c2
    ON c2.dot_number = i.dot_number
WHERE i.dot_number <> ?

UNION ALL

SELECT
    'VIN' AS match_type,
    tv.vin AS matched_vin,
    i.dot_number,
    c2.legal_name,
    c2.dba_name,
    c2.telephone,
    c2.fax,
    c2.email_address
FROM target_vins tv
JOIN inspections i
    ON i.vin2 = tv.vin
JOIN carriers c2
    ON c2.dot_number = i.dot_number
WHERE i.dot_number <> ?

ORDER BY legal_name, dot_number
SQL;

        $results = DB::connection('external_db')->select($sql, [
            $dot,
            $dot,
            $dot,
            $dot,
        ]);

        return response()->json([
            'success' => true,
            'count' => count($results),
            'data' => $results,
        ]);
    }

    public function brokerQuestions()
    {
        $connect = CarrierConnectRequestsModel::where(
            'row_id',
            request('row_id')
        )->first();

        if (! $connect) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid request.',
            ]);
        }

        $questions = CustomersQuestionsModel::where(
            'customer_id',
            $connect->sender_id
        )
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => true,
            'questions' => $questions,
        ]);
    }

    public function saveBrokerAnswers()
    {
        $connect = CarrierConnectRequestsModel::where(
            'row_id',
            request('row_id')
        )->first();

        if (! $connect) {
            return response()->json([
                'status' => false,
            ]);
        }

        $connect->broker_questionnaire = json_encode(request('answers'));

        $connect->save();

        return response()->json([
            'status' => true,
            'message' => 'Saved successfully.',
        ]);
    }

    public function esign(Request $request)
    {
        $request->validate([
            'row_id' => 'required',
            'signature' => 'required|image',
            'page' => 'required',
            'xPct' => 'required',
            'yPct' => 'required',
        ]);

        $signature = $request->file('signature');

        $signatureName = time().'_'.uniqid().'.'.$signature->getClientOriginalExtension();

        $signature->storeAs(
            'public/signatures',
            $signatureName
        );

        return response()->json([
            'status' => true,
            'message' => 'Signature uploaded successfully.',
            'signature' => asset('storage/signatures/'.$signatureName),
            'page' => $request->page,
            'xPct' => $request->xPct,
            'yPct' => $request->yPct,
        ]);
    }
}
