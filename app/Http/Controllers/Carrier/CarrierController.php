<?php

namespace App\Http\Controllers\Carrier;

use App\Console\Commands\BuildCarrierChangeLogIndex;
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
use App\Jobs\RefreshFleetStats;
use App\Services\Carrier\CarrierChangeLogService;
use App\Services\Vin\FleetStatsService;
use App\Services\Vin\VinDecoderService;
use App\Support\CarrierBenchmarks;
use App\Support\Fmcsa;
use App\Support\Vin;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarrierController extends Controller
{
    /**
     * Rows one identifier may contribute, and distinct carriers the whole
     * endpoint may return. A carrier on a shared mail drop or a Gmail address
     * otherwise returns thousands, and the profile shows five at a time.
     */
    private const ASSOCIATION_MAX_MATCHES = 200;

    /**
     * Carrier-and-VIN pairs the equipment lookup may return. A carrier running
     * a large fleet through a busy scale house shares vehicles with a lot of
     * people, and the profile pages them five at a time.
     */
    private const VIN_MAX_PAIRS = 1000;

    /**
     * Former identifiers worth matching on, as column => [label, value shown].
     *
     * Every column is the leading column of an index on company_census_file,
     * so each block stays a lookup. phy_street is the exception and is gated —
     * see addFormerMatches().
     */
    private const FORMER_MATCH_COLUMNS = [
        'email_address' => ['FORMER EMAIL', 'email_address'],
        'telephone' => ['FORMER PHONE', 'telephone'],
        'fax' => ['FORMER FAX', 'fax'],
        'legal_name' => ['FORMER LEGAL NAME', 'legal_name'],
        'dba_name' => ['FORMER DBA NAME', 'dba_name'],
        'mailing_street' => ['FORMER MAILING ADDRESS', "CONCAT_WS(', ', mailing_street, mailing_city, mailing_state, mailing_zip)"],
        'phy_street' => ['FORMER PHYSICAL ADDRESS', "CONCAT_WS(', ', phy_street, phy_city, phy_state, phy_zip)"],
    ];

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
        $crashesTowAway,
        // Optional so the profile keeps calling this exactly as before.
        ?int $inspectionCount = null
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
            $carrier,
            $inspectionCount
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
            $driverOosPct,
            $inspectionCount
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

    /**
     * Does a filing on insurance_filings match one of the coverage kinds?
     *
     * Form codes in the feed: 91 / 91X are BIPD (ins_type_desc 'BIPD/Primary',
     * 'BIPD/Excess'), 34 is cargo, 84 surety bond, 85 trust fund. The
     * description is checked too because form codes are blank on some rows.
     */
    private function insuranceFilingMatches($filing, string $kind): bool
    {
        $code = strtoupper(trim((string) ($filing->ins_form_code ?? '')));
        $desc = strtoupper((string) ($filing->ins_type_desc ?? ''));

        return match ($kind) {
            'bipd' => in_array($code, ['91', '91X'], true) || str_starts_with($desc, 'BIPD'),
            'cargo' => $code === '34' || str_contains($desc, 'CARGO'),
            'bond' => in_array($code, ['84', '85'], true)
                || str_contains($desc, 'SURETY')
                || str_contains($desc, 'BOND')
                || str_contains($desc, 'TRUST FUND'),
            default => false,
        };
    }

    /** Does the carrier hold an uncancelled filing of this coverage kind? */
    private function hasInsuranceFiling($carrier, string $kind): bool
    {
        return $carrier->insuranceFilings->contains(function ($filing) use ($kind) {

            if (! $this->insuranceFilingMatches($filing, $kind)) {
                return false;
            }

            if (empty($filing->cancl_effective_date)) {
                return true;
            }

            return Fmcsa::date($filing->cancl_effective_date)?->isFuture() ?? false;
        });
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

        // Status is 'A' / 'I' / 'N' since the Motus load — it was 'ACTIVE'
        // before. Comparing against the old literal knocked out every carrier
        // in the database and capped each one at 18 / grade F.
        if (
            ! Fmcsa::isActive($auth?->common_stat) &&
            ! Fmcsa::isActive($auth?->contract_stat)
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

        // Two sources have to agree before knocking a carrier out. The
        // authority record's `bipd_file` is a coverage amount in thousands and
        // reads '00000' on the large majority of rows — most of which are the
        // carrier's old, superseded authorities — so on its own it would reject
        // nearly the whole database. An actual filing on insurance_filings is
        // the stronger signal.
        if (! Fmcsa::onFile($auth?->bipd_file) && ! $this->hasInsuranceFiling($carrier, 'bipd')) {

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
            Fmcsa::flag($auth?->cargo_req) &&
            ! Fmcsa::onFile($auth?->cargo_file) &&
            ! $this->hasInsuranceFiling($carrier, 'cargo')
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
            Fmcsa::flag($auth?->bond_req) &&
            ! Fmcsa::onFile($auth?->bond_file) &&
            ! $this->hasInsuranceFiling($carrier, 'bond')
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

        // carrier_oos_orders still spells its status out in full ('ACTIVE' /
        // 'INACTIVE'), unlike the authority columns. rescind_date is a varchar,
        // so blanks have to count as "not rescinded" alongside NULL.
        $activeOOS = $carrier->oosOrders
            ->filter(fn ($o) => strtoupper((string) $o->status) === 'ACTIVE' && empty($o->rescind_date))
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

    /**
     * The five BASICs, keyed by the column prefix they share across
     * sms_measures and the cut-point table below.
     */
    private const SMS_BASICS = [
        'unsafe_driv',
        'hos_driv',
        'driv_fit',
        'contr_subst',
        'veh_maint',
    ];

    /**
     * National 50th / 75th / 90th cut-points for each BASIC measure.
     *
     * The Motus feed carries no `*_pct` column, so the percentile bands the
     * scoring is written against are rebuilt from the raw measures. Computing
     * them is a five-way window sort over the whole SMS table (~12s), so like
     * the other benchmarks it is refreshed by `carrier:refresh-benchmarks` and
     * only read here.
     */
    private function smsPercentiles(): array
    {
        return CarrierBenchmarks::smsCuts();
    }

    /**
     * The percentile band a BASIC measure falls into: 90, 75, 50 or 0.
     *
     * Stands in for the `*_pct` columns the feed no longer carries, so the
     * threshold checks downstream read exactly as they did before.
     *
     * A zero measure is never banded: for BASICs where most carriers sit at
     * zero the cut-points are zero too, and a plain `>=` would flag everybody.
     */
    private function measureBand($measure, array $cuts): float
    {
        $measure = (float) ($measure ?? 0);

        if ($measure <= 0) {
            return 0.0;
        }

        return match (true) {
            $measure >= $cuts[90] => 90.0,
            $measure >= $cuts[75] => 75.0,
            $measure >= $cuts[50] => 50.0,
            default => 0.0,
        };
    }

    /** Is this BASIC at or above the national alert (90th percentile) line? */
    private function basicAlert($measure, array $cuts): bool
    {
        return $this->measureBand($measure, $cuts) >= 90;
    }

    /**
     * The `*_pct` and `*_basic_alert` fields the sms_measures payload used to
     * carry, rebuilt from the measures so the response shape is unchanged.
     */
    private function smsPercentileFields($sms): array
    {
        $cuts = $this->smsPercentiles();
        $fields = [];

        foreach (self::SMS_BASICS as $basic) {
            $measure = $sms?->{"{$basic}_measure"};

            $fields["{$basic}_pct"] = $this->measureBand($measure, $cuts[$basic]);
            $fields["{$basic}_basic_alert"] = $this->basicAlert($measure, $cuts[$basic]);
        }

        return $fields;
    }

    private function calculateSafety(
        $sms,
        $detail,
        $vehicleOosPct,
        $driverOosPct,
        $carrier,
        // Search pages pass this in already counted. Loading the relation just
        // to count it costs 20k+ hydrated rows for a large carrier, so the
        // caller is allowed to answer instead. Null keeps the old behaviour
        // for the profile, where the rows are loaded anyway.
        ?int $inspectionCount = null
    ) {
        $score = 24;

        $deductions = [];

        $cuts = $this->smsPercentiles();

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

        $unsafe = $this->measureBand($sms?->unsafe_driv_measure, $cuts['unsafe_driv']);

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

        $hos = $this->measureBand($sms?->hos_driv_measure, $cuts['hos_driv']);

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

        $maintenance = $this->measureBand($sms?->veh_maint_measure, $cuts['veh_maint']);

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

        $fitness = $this->measureBand($sms?->driv_fit_measure, $cuts['driv_fit']);

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

        $substance = $this->measureBand($sms?->contr_subst_measure, $cuts['contr_subst']);

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

        // The `*_basic_alert` columns went away with the Motus load. A BASIC
        // alert is by definition the measure sitting at or above the national
        // intervention percentile, which the cut-points give us directly.
        if ($this->basicAlert($sms?->unsafe_driv_measure, $cuts['unsafe_driv'])) {
            $score -= 2;
            $deductions[] = 'Unsafe Driving BASIC Alert';
        }

        if ($this->basicAlert($sms?->hos_driv_measure, $cuts['hos_driv'])) {
            $score -= 2;
            $deductions[] = 'HOS BASIC Alert';
        }

        if ($this->basicAlert($sms?->veh_maint_measure, $cuts['veh_maint'])) {
            $score -= 2;
            $deductions[] = 'Vehicle Maintenance BASIC Alert';
        }

        if ($this->basicAlert($sms?->driv_fit_measure, $cuts['driv_fit'])) {
            $score -= 2;
            $deductions[] = 'Driver Fitness BASIC Alert';
        }

        if ($this->basicAlert($sms?->contr_subst_measure, $cuts['contr_subst'])) {
            $score -= 2;
            $deductions[] = 'Controlled Substance BASIC Alert';
        }

        /*
        |--------------------------------------------------------------------------
        | Acute / Critical Violations
        |--------------------------------------------------------------------------
        |
        | These read `*_rd_alert` before the Motus load. That column is gone;
        | `*_ac` — the acute/critical indicator — is the one alert column that
        | survived, so the deduction now hangs off it. The loader is not
        | populating it yet, so this contributes nothing today and starts
        | counting the moment it does.
        */

        if (Fmcsa::flag($sms?->unsafe_driv_ac)) {
            $score--;
        }

        if (Fmcsa::flag($sms?->hos_driv_ac)) {
            $score--;
        }

        if (Fmcsa::flag($sms?->veh_maint_ac)) {
            $score--;
        }

        if (Fmcsa::flag($sms?->driv_fit_ac)) {
            $score--;
        }

        if (Fmcsa::flag($sms?->contr_subst_ac)) {
            $score--;
        }

        /*
        |--------------------------------------------------------------------------
        | Inspection Volume
        |--------------------------------------------------------------------------
        */

        $inspectionCount = $inspectionCount ?? $carrier->inspections->count();

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

                // Percentile bands derived from the raw measures — the feed no
                // longer ships the `*_pct` columns these used to read.
                'unsafe_driv_pct' => $unsafe,

                'hos_driv_pct' => $hos,

                'veh_maint_pct' => $maintenance,

                'driv_fit_pct' => $fitness,

                'contr_subst_pct' => $substance,

                'unsafe_driv_measure' => $sms?->unsafe_driv_measure,

                'hos_driv_measure' => $sms?->hos_driv_measure,

                'veh_maint_measure' => $sms?->veh_maint_measure,

                'driv_fit_measure' => $sms?->driv_fit_measure,

                'contr_subst_measure' => $sms?->contr_subst_measure,

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

        // As in checkKnockout(): '00000' on bipd_file and 'N' on the other two
        // are both non-empty strings, so empty() read every carrier as covered.
        if (! Fmcsa::onFile($auth?->bipd_file) && ! $this->hasInsuranceFiling($carrier, 'bipd')) {

            $score -= 5;

            $deductions[] = 'No BIPD Insurance';

        }

        /*
        |--------------------------------------------------------------------------
        | Cargo Insurance
        |--------------------------------------------------------------------------
        */

        if (
            Fmcsa::flag($auth?->cargo_req) &&
            ! Fmcsa::onFile($auth?->cargo_file) &&
            ! $this->hasInsuranceFiling($carrier, 'cargo')
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
            Fmcsa::flag($auth?->bond_req) &&
            ! Fmcsa::onFile($auth?->bond_file) &&
            ! $this->hasInsuranceFiling($carrier, 'bond')
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

        if (Fmcsa::isActive($auth?->common_stat)) {

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

        if (Fmcsa::isActive($auth?->contract_stat)) {

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

        if (Fmcsa::isActive($auth?->broker_stat)) {

            $score += 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Pending Applications
        |--------------------------------------------------------------------------
        | These six columns hold 'Y' or 'N', never a blank, so the empty() test
        | they used to run was true for every carrier — a guaranteed -12 that
        | pinned the whole pillar at 0/12.
        */

        if (Fmcsa::flag($auth?->common_app_pend)) {

            $score -= 1;

            $deductions[] = 'Common Authority Pending';

        }

        if (Fmcsa::flag($auth?->contract_app_pend)) {

            $score -= 1;

            $deductions[] = 'Contract Authority Pending';

        }

        if (Fmcsa::flag($auth?->broker_app_pend)) {

            $score -= 1;

            $deductions[] = 'Broker Authority Pending';

        }

        /*
        |--------------------------------------------------------------------------
        | Revocation Pending
        |--------------------------------------------------------------------------
        */

        if (Fmcsa::flag($auth?->common_rev_pend)) {

            $score -= 3;

            $deductions[] = 'Common Revocation Pending';

        }

        if (Fmcsa::flag($auth?->contract_rev_pend)) {

            $score -= 3;

            $deductions[] = 'Contract Revocation Pending';

        }

        if (Fmcsa::flag($auth?->broker_rev_pend)) {

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

    /*
    |--------------------------------------------------------------------------
    | Crash / Inspection / Operations pillars
    |--------------------------------------------------------------------------
    |
    | calculateCarrierTrustScore() has always called these three plus
    | getGrade(), and none of them existed. The call never got that far: the
    | knockout check above compared authority status against 'ACTIVE', which no
    | longer appears in the data, so every carrier short-circuited to a capped
    | score of 18 / grade F and returned before reaching this point. Fixing the
    | status comparison exposed the gap.
    |
    | The four weights already in the file come to 74 (safety 24, identity 20,
    | insurance 18, authority 12) and the overall thresholds treat the score as
    | out of 100, so the remaining 26 is split crash 10 / inspection 8 /
    | operations 8. Those weights and the bands below are a starting point —
    | they are the part of the scoring nobody has signed off on yet.
    */

    private function calculateCrash(
        $carrier,
        $crashesTotal,
        $crashFatalities,
        $crashInjuries,
        $crashesTowAway
    ) {
        $score = 10;

        $deductions = [];

        /*
        |--------------------------------------------------------------------------
        | Fatalities
        |--------------------------------------------------------------------------
        */

        if ($crashFatalities >= 3) {

            $score -= 8;

            $deductions[] = 'Multiple Fatal Crashes';

        } elseif ($crashFatalities >= 1) {

            $score -= 5;

            $deductions[] = 'Fatal Crash On Record';

        }

        /*
        |--------------------------------------------------------------------------
        | Injuries
        |--------------------------------------------------------------------------
        */

        if ($crashInjuries >= 10) {

            $score -= 3;

            $deductions[] = 'High Injury Crash Count';

        } elseif ($crashInjuries >= 5) {

            $score -= 2;

        } elseif ($crashInjuries >= 1) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Tow-Away Crashes
        |--------------------------------------------------------------------------
        */

        if ($crashesTowAway >= 10) {

            $score -= 3;

            $deductions[] = 'Frequent Tow-Away Crashes';

        } elseif ($crashesTowAway >= 5) {

            $score -= 2;

        } elseif ($crashesTowAway >= 1) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Crash Volume
        |--------------------------------------------------------------------------
        */

        if ($crashesTotal >= 20) {

            $score -= 3;

            $deductions[] = 'High Crash Volume';

        } elseif ($crashesTotal >= 10) {

            $score -= 2;

        } elseif ($crashesTotal >= 5) {

            $score -= 1;

        }

        /*
        |--------------------------------------------------------------------------
        | Crashes Per Power Unit
        |--------------------------------------------------------------------------
        | Volume alone punishes large fleets, so weigh it against fleet size
        | where the carrier has reported one.
        */

        $powerUnits = (int) ($carrier->nbr_power_unit ?? 0);

        $crashesPerUnit = $powerUnits > 0
            ? round($crashesTotal / $powerUnits, 3)
            : null;

        if ($crashesPerUnit !== null) {

            if ($crashesPerUnit >= 1.0) {

                $score -= 3;

                $deductions[] = 'Crash Rate Well Above Fleet Size';

            } elseif ($crashesPerUnit >= 0.5) {

                $score -= 2;

            } elseif ($crashesPerUnit >= 0.25) {

                $score -= 1;

            }

        }

        $score = max(0, min(10, round($score)));

        if ($score >= 9) {

            $status = 'Excellent';

        } elseif ($score >= 7) {

            $status = 'Good';

        } elseif ($score >= 5) {

            $status = 'Average';

        } elseif ($score >= 3) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 10,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'crashes_total' => $crashesTotal,

                'crash_fatalities' => $crashFatalities,

                'crash_injuries' => $crashInjuries,

                'crashes_tow_away' => $crashesTowAway,

                'power_units' => $powerUnits ?: null,

                'crashes_per_power_unit' => $crashesPerUnit,

            ],

        ];
    }

    private function calculateInspection(
        $carrier,
        $sms,
        $vehicleOosPct,
        $driverOosPct,
        // See calculateSafety() — pre-counted by the search path.
        ?int $inspectionCount = null
    ) {
        $score = 8;

        $deductions = [];

        /*
        |--------------------------------------------------------------------------
        | Inspection Volume
        |--------------------------------------------------------------------------
        | sms_measures is the authority on totals; fall back to the inspection
        | rows for carriers the SMS extract has not picked up.
        */

        $inspTotal = (int) ($sms?->insp_total ?? 0);

        if ($inspTotal === 0) {
            $inspTotal = $inspectionCount ?? $carrier->inspections->count();
        }

        if ($inspTotal === 0) {

            $score -= 4;

            $deductions[] = 'No Inspection History';

        } elseif ($inspTotal < 5) {

            $score -= 2;

            $deductions[] = 'Thin Inspection History';

        }

        /*
        |--------------------------------------------------------------------------
        | Share Of Inspections Carrying A Violation
        |--------------------------------------------------------------------------
        */

        $inspWithViolations = 0;

        foreach (self::SMS_BASICS as $basic) {
            $inspWithViolations += (int) ($sms?->{"{$basic}_insp_w_viol"} ?? 0);
        }

        $violationRate = $inspTotal > 0
            ? round($inspWithViolations / $inspTotal, 3)
            : null;

        if ($violationRate !== null) {

            if ($violationRate >= 0.75) {

                $score -= 3;

                $deductions[] = 'Violations On Most Inspections';

            } elseif ($violationRate >= 0.50) {

                $score -= 2;

            } elseif ($violationRate >= 0.25) {

                $score -= 1;

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Out Of Service Rates
        |--------------------------------------------------------------------------
        | FMCSA national averages: vehicle 10.8%, driver 4.5%.
        */

        if ($vehicleOosPct !== null && $vehicleOosPct >= 30) {

            $score -= 2;

            $deductions[] = 'Vehicle OOS Rate Well Above National Average';

        } elseif ($vehicleOosPct !== null && $vehicleOosPct >= 20) {

            $score -= 1;

        }

        if ($driverOosPct !== null && $driverOosPct >= 15) {

            $score -= 2;

            $deductions[] = 'Driver OOS Rate Well Above National Average';

        } elseif ($driverOosPct !== null && $driverOosPct >= 10) {

            $score -= 1;

        }

        $score = max(0, min(8, round($score)));

        if ($score >= 7) {

            $status = 'Excellent';

        } elseif ($score >= 6) {

            $status = 'Good';

        } elseif ($score >= 4) {

            $status = 'Average';

        } elseif ($score >= 2) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 8,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'inspection_count' => $inspTotal,

                'inspections_with_violations' => $inspWithViolations,

                'violation_rate' => $violationRate,

                'vehicle_oos_pct' => $vehicleOosPct,

                'driver_oos_pct' => $driverOosPct,

            ],

        ];
    }

    private function calculateOperations(
        $carrier,
        $detail,
        $dotAge,
        $mcs150Year,
        $observedUnits,
        $observedTrailers
    ) {
        $score = 8;

        $deductions = [];

        /*
        |--------------------------------------------------------------------------
        | Time Since DOT Registration
        |--------------------------------------------------------------------------
        */

        if ($dotAge === null) {

            $score -= 1;

            $deductions[] = 'DOT Registration Date Unknown';

        } elseif ($dotAge < 2) {

            $score -= 3;

            $deductions[] = 'New Operation';

        } elseif ($dotAge < 5) {

            $score -= 1;

        } elseif ($dotAge >= 10) {

            $score += 1;

        }

        /*
        |--------------------------------------------------------------------------
        | MCS-150 Currency
        |--------------------------------------------------------------------------
        | Carriers must refile every two years.
        */

        $currentYear = (int) now()->year;

        if ($mcs150Year === null) {

            $score -= 2;

            $deductions[] = 'No MCS-150 On File';

        } elseif (($currentYear - $mcs150Year) > 2) {

            $score -= 2;

            $deductions[] = 'MCS-150 Filing Overdue';

        }

        /*
        |--------------------------------------------------------------------------
        | Reported Fleet Against Observed Equipment
        |--------------------------------------------------------------------------
        | A carrier that reports power units but has never had one inspected is
        | worth flagging.
        */

        $reportedUnits = (int) ($carrier->nbr_power_unit ?? 0);

        if ($reportedUnits > 0 && $observedUnits === 0) {

            $score -= 2;

            $deductions[] = 'No Equipment Observed Roadside';

        }

        /*
        |--------------------------------------------------------------------------
        | Self-Reported Operating Data
        |--------------------------------------------------------------------------
        */

        if ((int) ($carrier->mcs150_mileage ?? 0) <= 0) {

            $score -= 1;

            $deductions[] = 'No Mileage Reported';

        }

        $driverCount = (int) ($detail?->total_drivers ?? $carrier->driver_total ?? 0);

        if ($driverCount <= 0) {

            $score -= 1;

            $deductions[] = 'No Drivers Reported';

        }

        $score = max(0, min(8, round($score)));

        if ($score >= 7) {

            $status = 'Excellent';

        } elseif ($score >= 6) {

            $status = 'Good';

        } elseif ($score >= 4) {

            $status = 'Average';

        } elseif ($score >= 2) {

            $status = 'Poor';

        } else {

            $status = 'Critical';

        }

        return [

            'weight' => 8,

            'score' => $score,

            'status' => $status,

            'deductions' => $deductions,

            'parameters' => [

                'dot_age' => $dotAge,

                'mcs150_year' => $mcs150Year,

                'reported_power_units' => $reportedUnits,

                'observed_units' => $observedUnits,

                'observed_trailers' => $observedTrailers,

                'mcs150_mileage' => $carrier->mcs150_mileage,

                'driver_total' => $driverCount,

            ],

        ];
    }

    /** Letter grade for a 0-100 trust score. */
    private function getGrade($score)
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    /**
     * Columns read straight off `carriers` for a search row.
     */
    private const SEARCH_COLUMNS = [
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
        // Scoring inputs: DOT age and the MCS-150 filing year.
        'add_date',
        'mcs150_date',
    ];

    /**
     * A search row's related fields, resolved in the same query as the row.
     *
     * These used to be five eager loads. The carrier database is on EC2, so
     * every extra statement is a full network round trip (~300ms measured) —
     * five of them dominated the response. As correlated subselects the server
     * answers all of them in one trip off the existing dot_number indexes.
     *
     * `ORDER BY id DESC LIMIT 1` is also what collapses the duplicate rows the
     * Motus load left in carrier_details and carrier_authorities.
     *
     * Two of the old eager loads (`authorityHistory`, `smsMeasures`) are gone
     * outright: nothing in the response ever read them, and authorityHistory is
     * a 9.9M-row table.
     */
    private function carrierSearchQuery(): EloquentBuilder
    {
        $latestAuthority = fn (string $column) => CarrierAuthority::query()
            ->select($column)
            ->whereColumn('carrier_authorities.dot_number', 'carriers.dot_number')
            ->orderByDesc('carrier_authorities.id')
            ->limit(1);

        $latestDetail = fn (string $column) => CarrierDetail::query()
            ->select($column)
            ->whereColumn('carrier_details.dot_number', 'carriers.dot_number')
            ->orderByDesc('carrier_details.id')
            ->limit(1);

        return Carrier::query()
            ->select(self::SEARCH_COLUMNS)
            ->addSelect([
                'mc_number' => $latestAuthority('docket_number'),
                'common_stat' => $latestAuthority('common_stat'),
                'contract_stat' => $latestAuthority('contract_stat'),
                'broker_stat' => $latestAuthority('broker_stat'),

                'fleetsize' => $latestDetail('fleetsize'),
                'status_code' => $latestDetail('status_code'),
                'safety_rating' => $latestDetail('safety_rating'),
                'duns' => $latestDetail('dun_bradstreet_no'),

                // The old eager load asked for `inspections` with limit(1),
                // which Laravel applies to the whole eager-load statement — so
                // one VIN came back for the entire page and landed on whichever
                // carrier matched first.
                'vin' => Inspection::query()
                    ->select('vin')
                    ->whereColumn('inspections.dot_number', 'carriers.dot_number')
                    ->whereNotNull('vin')
                    ->orderByDesc('inspections.id')
                    ->limit(1),
            ])
            ->withExists('insuranceFilings');
    }

    /**
     * Narrow a carrier query by the field the broker chose to search on.
     */
    private function applyCarrierSearchFilter(EloquentBuilder $query, string $search, string $searchedBy): EloquentBuilder
    {
        if ($search === '') {
            return $query;
        }

        switch ($searchedBy) {

            case 'mc_number':

                $docket = strtoupper($search);

                if (! str_starts_with($docket, 'MC')) {
                    $docket = 'MC'.$docket;
                }

                // As a subquery rather than a lookup-then-filter, so resolving
                // the docket costs no extra round trip.
                $query->whereIn('dot_number', CarrierAuthority::query()
                    ->select('dot_number')
                    ->where('docket_number', $docket));

                break;

            case 'legal_name':

                $query->where('legal_name', 'LIKE', "%{$search}%");
                break;

            case 'phone':

                // The census file stores phone numbers unformatted
                // ('8006540055'), where the previous feed stored
                // '(800) 654-0055'. Brokers type either, so compare on digits.
                $query->where('telephone', preg_replace('/\D+/', '', $search) ?: $search);
                break;

            case 'email':

                $query->where('email_address', $search);
                break;

            case 'dot_number':
            default:

                $query->where('dot_number', $search);
        }

        return $query;
    }

    /**
     * Run a carrier search and return [rows, total].
     *
     * Deliberately two statements. `idx_carriers_legal_name` covers
     * `SELECT id … ORDER BY legal_name`, so step one is answered from the index
     * alone. Adding any other column to that statement — dot_number, telephone,
     * anything — makes it a primary-key lookup per candidate row while scanning
     * 2M rows in name order, which took 24s against 0.4s for the covered
     * version. Step two fetches the full row for ten primary keys, which is
     * free, and carries the subselects with it.
     *
     * `id` joins the sort so that pages do not overlap when several carriers
     * share a legal name.
     *
     * @param  callable(EloquentBuilder): EloquentBuilder  $filter
     * @return array{0: Collection, 1: int}
     */
    private function runCarrierSearch(callable $filter, int $page, int $perPage, string $sortDir, string $cacheKey): array
    {
        // One row past the page, so "is there another page" is answered by the
        // page query itself. forPage() derives the offset from the page size,
        // so the offset is set by hand rather than passing $perPage + 1 to it.
        $ids = $filter(Carrier::query())
            ->orderBy('legal_name', $sortDir)
            ->orderBy('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage + 1)
            ->pluck('id');

        $hasMore = $ids->count() > $perPage;
        $ids = $ids->take($perPage);

        $rows = $ids->isEmpty()
            ? collect()
            : $this->carrierSearchQuery()
                ->whereIn('id', $ids)
                ->orderBy('legal_name', $sortDir)
                ->orderBy('id')
                ->get();

        return [
            $rows,
            $hasMore,
            $this->carrierSearchTotal($filter, $page, $perPage, $ids->count(), $hasMore, $cacheKey),
        ];
    }

    /**
     * How long a search's total row count stays usable. The carrier database
     * is a scheduled bulk load rather than a live write path, so a count
     * cannot move between two searches minutes — or hours — apart.
     */
    private const SEARCH_TOTAL_TTL_HOURS = 6;

    /**
     * Total row count for a search, never on the request path.
     *
     * A last page needs no COUNT at all: the total falls out of the offset and
     * the rows in hand, which covers every DOT / MC / phone / email lookup and
     * the tail of a name search.
     *
     * Otherwise the count comes from cache, and a cold cache returns null —
     * "not known yet" — rather than making the broker wait. `LIKE '%term%'`
     * can only be counted by scanning the whole index (~12s over 4.48M rows),
     * which was the single slowest thing in a company-name search and bought
     * nothing but a number in the results header. The scan instead runs once
     * after the response has been flushed, so the next page of the same search
     * reads it from the cache.
     *
     * Paging does not depend on this: `has_more_pages` comes from the page
     * query fetching one row past the page.
     */
    private function carrierSearchTotal(callable $filter, int $page, int $perPage, int $rowCount, bool $hasMore, string $cacheKey): ?int
    {
        if (! $hasMore) {
            return ($page - 1) * $perPage + $rowCount;
        }

        $key = 'carrier_search_total:'.md5($cacheKey);

        $cached = Cache::get($key);

        if ($cached !== null) {
            return (int) $cached;
        }

        // One warm-up per term at a time. Without the lock every request for a
        // popular term queues its own 12s scan behind the response and holds a
        // PHP worker for it.
        if (Cache::add($key.':warming', 1, now()->addMinutes(2))) {

            app()->terminating(function () use ($filter, $key) {

                try {
                    Cache::put(
                        $key,
                        (int) $filter(Carrier::query())->toBase()->getCountForPagination(),
                        now()->addHours(self::SEARCH_TOTAL_TTL_HOURS),
                    );
                } catch (\Throwable $e) {
                    Log::warning('Carrier search total warm-up failed', ['error' => $e->getMessage()]);
                } finally {
                    Cache::forget($key.':warming');
                }

            });
        }

        return null;
    }

    /**
     * Years since a DOT number was issued.
     *
     * Lifted out of detail() unchanged so that a search row and the carrier
     * profile derive the age — and therefore the score — identically. The feed
     * writes dates as '01-JUN-74', which Carbon cannot parse on its own.
     */
    private function dotAgeFrom($addDate): ?int
    {
        if (! $addDate) {
            return null;
        }

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

            return (int) $parsedDate->diffInYears(now());

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * How long a computed search score stays usable.
     *
     * The carrier database is a bulk FMCSA load on a schedule, not a live
     * write path, so a score cannot change between two searches minutes apart.
     */
    private const SEARCH_SCORE_TTL_HOURS = 6;

    /**
     * DT scores for the carriers on one search page, keyed by carrier id.
     *
     * This is the expensive part of the search response and it is deliberate:
     * a broker searching a carrier for the first time has no stored score, and
     * showing a dash there was the whole complaint. Three things keep it from
     * behaving like the old /blocked endpoint:
     *
     *  - Every relation is eager-loaded across the whole page at once, so the
     *    cost is a fixed twelve statements no matter how many rows came back —
     *    never one query per carrier.
     *  - inspections and crashes are never loaded as rows. The score only
     *    needs counts out of them and a large carrier has tens of thousands,
     *    so the database counts instead. Those two aggregates depend on the
     *    covering indexes idx_dot_vin_type and idx_dot_vin2_type on
     *    sms_input_inspection, the table behind the inspections view; without
     *    them the distinct-VIN count measured 102s for one page, and with them
     *    3.5s for the ten busiest carriers in the feed.
     *  - Each score is cached by DOT number, so only a carrier nobody has
     *    searched for recently is paid for.
     *
     * The same calculateCarrierTrustScore() the profile calls produces the
     * number, so the score on the card matches the score on the profile.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return array<int, int>
     */
    private function computeSearchScores(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $scores = [];

        foreach ($rows as $carrier) {

            $cached = Cache::get('carrier_dt_score:'.$carrier->dot_number);

            if ($cached !== null) {
                $scores[$carrier->id] = (int) $cached;
            }
        }

        // reject() rather than a plain collect(), so what comes back is still
        // an Eloquent collection and can eager-load relations.
        $pending = $rows->reject(fn (Carrier $carrier) => array_key_exists($carrier->id, $scores));

        if ($pending->isEmpty()) {
            return $scores;
        }

        $pending->load([
            'carrierDetail',
            'smsMeasures',
            'authority',
            'oosOrders',
            'authorityOrders',
            'insuranceFilings',
            'insuranceFilingsPending',
            'insuranceFilingsHistory',

            // Column-limited on purpose — the authority pillar reads four
            // fields off it and nothing else. dot_number stays because the
            // eager load matches rows back on it.
            'authorityHistory:dot_number,op_auth_type,original_action_desc,orig_served_date,disp_action_desc',
        ]);

        // inspections and crashes are deliberately NOT eager-loaded. The
        // busiest carrier in the feed has 22,482 inspection rows, and
        // hydrating those for ten carriers took minutes when measured. The
        // score only needs counts out of them, so the database counts.
        $dots = $pending->pluck('dot_number')->all();

        $inspectionStats = $this->inspectionStatsFor($dots);
        $crashStats = $this->crashStatsFor($dots);

        foreach ($pending as $carrier) {

            $score = $this->trustScoreForRow(
                $carrier,
                $inspectionStats[$carrier->dot_number] ?? [],
                $crashStats[$carrier->dot_number] ?? [],
            );

            if ($score === null) {
                continue;
            }

            Cache::put(
                'carrier_dt_score:'.$carrier->dot_number,
                $score,
                now()->addHours(self::SEARCH_SCORE_TTL_HOURS),
            );

            $scores[$carrier->id] = $score;
        }

        return $scores;
    }

    /**
     * Inspection totals for a page of carriers, keyed by DOT number.
     *
     * Returns the row count plus the distinct power units and trailers seen
     * roadside. Counting distinct VINs is the same rule the profile applies in
     * PHP — a truck stopped nine times is one unit — but done in SQL, because
     * the rows themselves are never needed and there can be tens of thousands
     * of them per carrier.
     *
     * The two unit slots are stacked with UNION ALL so one VIN recorded in
     * either slot counts once. Comparisons are left to the column collation,
     * which is case-insensitive, matching the strtoupper() the profile uses.
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, array{insp_total:int, observed_units:int, observed_trailers:int}>
     */
    private function inspectionStatsFor(array $dots): array
    {
        if (! $dots) {
            return [];
        }

        $connection = DB::connection($this->carrierConnection());

        $placeholders = implode(',', array_fill(0, count($dots), '?'));

        $totals = $connection
            ->table('inspections')
            ->selectRaw('dot_number, COUNT(*) AS insp_total')
            ->whereIn('dot_number', $dots)
            ->groupBy('dot_number')
            ->pluck('insp_total', 'dot_number');

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

        foreach ($totals as $dot => $total) {
            $stats[$dot]['insp_total'] = (int) $total;
        }

        return $stats;
    }

    /**
     * Crash totals for a page of carriers, keyed by DOT number.
     *
     * tow_away is a tinyint(1) holding 0 or 1, so `= 1` here is the same test
     * as the boolean cast the profile filters on.
     *
     * @param  array<int, mixed>  $dots
     * @return array<int|string, array{crashes_total:int, fatalities:int, injuries:int, tow_away:int}>
     */
    private function crashStatsFor(array $dots): array
    {
        if (! $dots) {
            return [];
        }

        return DB::connection($this->carrierConnection())
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

    /** The connection the carrier tables live on. */
    private function carrierConnection(): string
    {
        return (new Carrier)->getConnectionName();
    }

    /**
     * Run the trust score for one already-loaded search row.
     *
     * Assembles the same inputs detail() assembles, off relations that are
     * already in memory, and hands them to the shared calculator.
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

        // Power units and trailers actually seen roadside — distinct VINs
        // across both inspection slots, counted in SQL rather than in PHP.
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

        $trustScore = $this->calculateCarrierTrustScore(
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
            (int) ($inspectionStats['insp_total'] ?? 0),
        );

        return isset($trustScore['overall_score'])
            ? (int) $trustScore['overall_score']
            : null;
    }

    /**
     * DT score for every row on a search page.
     *
     * Computed live so a first-time search shows a real number. If the carrier
     * database is unreachable or the calculation throws, the search response
     * still goes out — falling back to the last score this company was shown,
     * and to null after that, rather than failing the whole request.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return array<int, int>
     */
    private function searchRowScores(Collection $rows): array
    {
        $stored = $this->storedDtScores($rows);

        try {
            return $this->computeSearchScores($rows) + $stored;
        } catch (\Throwable $e) {
            Log::warning('Carrier search scoring failed', [
                'error' => $e->getMessage(),
            ]);

            return $stored;
        }
    }

    /**
     * The DT scores this company has already seen, keyed by carrier id.
     *
     * The score is only ever computed on the profile endpoint, so this is the
     * last score the company was actually shown for that carrier — carriers
     * nobody here has opened yet have none, and the row carries a null. The
     * lookup is against the application database (search_histories), not the
     * EC2 carrier database, and is scoped to the ids on this page.
     *
     * Guest search (`/guest-pay/carrier/search`) runs unauthenticated, so
     * there is no company to scope to and every row is scoreless.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return array<int, int>
     */
    private function storedDtScores(Collection $rows): array
    {
        $companyId = auth()->user()?->company_id;

        if (! $companyId || $rows->isEmpty()) {
            return [];
        }

        return SearchHistory::query()
            ->where('company_id', $companyId)
            ->whereIn('carrier_id', $rows->pluck('id')->all())
            ->whereNotNull('dt_score')
            ->pluck('dt_score', 'carrier_id')
            ->map(fn ($score) => (int) $score)
            ->all();
    }

    /**
     * Shape a carrier row for the search response.
     */
    private function transformSearchRow(Carrier $carrier, ?int $dtScore = null): array
    {
        return [

            'dt_score' => $dtScore,

            'id' => $carrier->id,
            'row_id' => $carrier->row_id,
            'carrier_operation' => $carrier->carrier_operation,
            'company_name' => $carrier->legal_name,
            'dba_name' => $carrier->dba_name,
            'dot_number' => $carrier->dot_number,
            'mc_number' => $carrier->mc_number,
            'phone' => $carrier->telephone,
            'email' => $carrier->email_address,
            'duns' => $carrier->duns,

            'address' => collect([
                $carrier->phy_street,
                $carrier->phy_city,
                $carrier->phy_state,
                $carrier->phy_zip,
            ])->filter()->implode(', '),

            'insurance_current' => $carrier->insurance_filings_exists,

            'vin' => $carrier->vin,

            'mileage' => $carrier->mcs150_mileage,

            'fleet_size' => Fmcsa::fleetSize($carrier->fleetsize),

            'drivers' => $carrier->driver_total,

            // Authority status is 'A' / 'I' / 'N' since the Motus load; it was
            // 'ACTIVE' before, so every one of these read false.
            'is_broker' => Fmcsa::isActive($carrier->broker_stat),

            'active_authority' => $carrier->status_code,

            'authority_verified' => Fmcsa::isActive($carrier->common_stat)
                || Fmcsa::isActive($carrier->contract_stat)
                || Fmcsa::isActive($carrier->broker_stat),

            'risk_level' => Fmcsa::safetyRating($carrier->safety_rating),
        ];
    }

    public function search(Request $request)
    {
        $search = trim((string) $request->query('query', ''));
        $searchedBy = (string) $request->query('searched_by', 'dot_number');
        $sort = $request->query('sort', '');
        $sortDir = $sort === 'sortByNameDesc' ? 'desc' : 'asc';
        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        [$rows, $hasMore, $total] = $this->runCarrierSearch(
            fn (EloquentBuilder $query) => $this->applyCarrierSearchFilter($query, $search, $searchedBy),
            $page,
            $perPage,
            $sortDir,
            "search|{$searchedBy}|".mb_strtolower($search),
        );

        $scores = $this->searchRowScores($rows);

        return response()->json([
            'current_page' => $page,
            'per_page' => $perPage,
            // Null while the count is still warming — the results header shows
            // "10+" for it rather than a wrong number, and paging runs off
            // has_more_pages, which is always exact.
            'total' => $total,
            'last_page' => $total === null ? null : max(1, (int) ceil($total / $perPage)),
            'has_more_pages' => $hasMore,
            'data' => $rows->map(fn (Carrier $carrier) => $this->transformSearchRow(
                $carrier,
                $scores[$carrier->id] ?? null,
            ))->values(),
        ]);
    }

    /**
     * Free-text variant of search(): the broker types one box and we work out
     * whether it is an identifier or a name.
     */
    public function search2(Request $request)
    {
        $search = trim((string) $request->query('query', ''));
        $sort = $request->query('sort', '');
        $sortDir = $sort === 'sortByNameDesc' ? 'desc' : 'asc';
        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        // A bare number is either the DOT number or a docket that maps to one.
        // Resolve the docket up front: as an `OR dot_number IN (subquery)` the
        // planner abandons the dot_number index and walks `carriers` in
        // legal_name order instead, which on 2M rows does not come back.
        $dotNumbers = null;

        if ($search !== '' && is_numeric($search)) {
            $dotNumbers = CarrierAuthority::query()
                ->where('docket_number', $search)
                ->pluck('dot_number')
                ->push($search)
                ->unique()
                ->all();
        }

        $filter = function (EloquentBuilder $query) use ($search, $dotNumbers): EloquentBuilder {

            if ($search === '') {
                return $query;
            }

            if ($dotNumbers !== null) {
                return $query->whereIn('dot_number', $dotNumbers);
            }

            // legal_name only, matching what searched_by=legal_name does on the
            // routed endpoint. Adding `OR dba_name LIKE ?` cannot use either
            // index (4.5M-row scan, ~5 minutes), and ordering through idx_dba
            // is no better because dba_name is null on most rows — the index
            // walk spends its time in the null region. Restoring dba matching
            // wants a FULLTEXT index; see docs/carrier-database-notes.md.
            return $query->where('legal_name', 'LIKE', "%{$search}%");
        };

        [$rows, $hasMore, $total] = $this->runCarrierSearch(
            $filter,
            $page,
            $perPage,
            $sortDir,
            'search2|'.mb_strtolower($search),
        );

        $scores = $this->searchRowScores($rows);

        return response()->json([
            'current_page' => $page,
            'per_page' => $perPage,
            // Null while the count is still warming — the results header shows
            // "10+" for it rather than a wrong number, and paging runs off
            // has_more_pages, which is always exact.
            'total' => $total,
            'last_page' => $total === null ? null : max(1, (int) ceil($total / $perPage)),
            'has_more_pages' => $hasMore,
            'data' => $rows->map(function (Carrier $carrier) use ($scores) {
                // search2 has always returned the raw FMCSA rating code here
                // rather than the label search() uses.
                return array_merge(
                    $this->transformSearchRow($carrier, $scores[$carrier->id] ?? null),
                    ['risk_level' => $carrier->safety_rating],
                );
            })->values(),
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

    /**
     * Shape one VIN's decoded fields for the response, or nulls when the VIN
     * is absent, malformed, or not decoded yet.
     *
     * @param  \Illuminate\Support\Collection  $decoded  keyed by normalised VIN
     */
    protected function decodedVinFields(Collection $decoded, ?string $vin): array
    {
        $hit = $decoded->get(Vin::normalize($vin));

        return [
            'model_year' => $hit?->model_year,
            'make' => $hit?->make,
            'model' => $hit?->model,
            'vehicle_type' => $hit?->vehicle_type,
            'body_class' => $hit?->body_class,
            'is_trailer' => $hit?->is_trailer,
        ];
    }

    public function detail($rowid)
    {
        // row_id is the DOT number rendered as a string, so filter on
        // dot_number itself — matching on row_id would be a CAST per row and
        // could not use the unique key.
        $carrier = Carrier::query()
            ->where('dot_number', $rowid)
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
                'census',                 // ← MCS-150 operation flags
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
        $census = $carrier->census;

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

        $dotAge = $this->dotAgeFrom($carrier->add_date ?? $detail?->add_date);

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

        // The feed's own vocabulary: 'TRUCK TRACTOR', 'STRAIGHT TRUCK', 'BUS',
        // 'SCHOOL BUS', 'MOTOR COACH', 'PASSENGER VAN', 'LIMOUSINE' drive
        // themselves; 'SEMI-TRAILER', 'FULL TRAILER', 'POLE TRAILER',
        // 'CRIB LOG TRAILER', 'INTERMODAL CHASSIS', 'DOLLY CONVERTER' are
        // towed. The old list checked for bare 'TRUCK' and 'TRACTOR', which
        // the feed never emits, so most units were counted as neither.
        $isTowedUnit = function (?string $type): bool {
            $type = strtoupper((string) $type);

            return str_contains($type, 'TRAILER')
                || str_contains($type, 'CHASSIS')
                || str_contains($type, 'DOLLY');
        };

        $isPowerUnit = fn (?string $type): bool => in_array(strtoupper((string) $type), [
            'TRUCK TRACTOR',
            'STRAIGHT TRUCK',
            'BUS',
            'SCHOOL BUS',
            'MOTOR COACH',
            'PASSENGER VAN',
            'LIMOUSINE',
        ], true);

        $observedUnits = $carrier->inspections
            ->flatMap(function ($inspection) use ($isPowerUnit) {

                $units = [];

                // Primary unit
                if ($inspection->vin && $isPowerUnit($inspection->unit_type_desc)) {
                    $units[] = $inspection->vin;
                }

                // Some inspections record the power unit second
                if ($inspection->vin2 && $isPowerUnit($inspection->unit_type_desc2)) {
                    $units[] = $inspection->vin2;
                }

                return $units;
            })
            ->unique()
            ->count();

        $observedTrailers = $carrier->inspections
            ->flatMap(function ($inspection) use ($isTowedUnit) {

                $trailers = [];

                // Secondary unit
                if ($inspection->vin2 && $isTowedUnit($inspection->unit_type_desc2)) {
                    $trailers[] = $inspection->vin2;
                }

                // Some inspections may store trailer as primary unit
                if ($inspection->vin && $isTowedUnit($inspection->unit_type_desc)) {
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

        // Hazmat totals live on the inspection rows, not sms_measures.
        $hazmatInspTotal = (int) $carrier->inspections->sum(fn ($i) => (int) $i->total_hazmat_sent);
        $hazmatOosTotal = (int) $carrier->inspections->sum(fn ($i) => (int) $i->hazmat_oos_total);

        $vehicleOosRate = $vehicleInspTotal > 0 ? $vehicleOosTotal / $vehicleInspTotal : null;
        $driverOosRate = $driverInspTotal > 0 ? $driverOosTotal / $driverInspTotal : null;
        $oosAlertVehicle = $vehicleOosRate !== null && $vehicleOosRate > 0.108;
        $oosAlertDriver = $driverOosRate !== null && $driverOosRate > 0.045;

        // Roadside alert — Unsafe Driving BASIC (FMCSA threshold: 65)
        // The old `> 65` was a percentile threshold applied to a value that is
        // now a raw measure (they run 0-1844, not 0-100), so it fired on almost
        // nothing. Compare against the national alert cut-point instead.
        $basicRoadsideAlertUnsafeDriving = $this->basicAlert(
            $sms?->unsafe_driv_measure,
            $this->smsPercentiles()['unsafe_driv'],
        );

        // Last activity dates
        // These columns are '24-APR-24' strings, so max() over them compares
        // text — '31-AUG-19' sorts above '01-JAN-25'. Rank on the parsed date.
        $latestBy = fn ($collection, string $column) => $collection
            ->sortByDesc(fn ($row) => Fmcsa::dateKey($row->$column))
            ->first()?->$column;

        $lastInspectionDate = $latestBy($carrier->inspections, 'insp_date');
        $lastViolationDate = $latestBy($carrier->violationDetails, 'insp_date');
        $lastCrashDate = $latestBy($carrier->crashes, 'report_date');

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
        // These are the same measurement as observed_units / observed_trailers
        // above — distinct VINs seen roadside — so they have to be counted the
        // same way. They previously looked at the primary unit only, while the
        // observed_* pair looked at both, which is why a carrier whose trailers
        // are always the second unit reported 5 observed trailers and 0
        // inspected ones in the same payload.
        $inspectedPowerUnits = $observedUnits;

        $inspectedTrailers = $observedTrailers;

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
        // carrier_authority_history no longer has a `mod_col_1` column — the
        // authority type is `op_auth_type`, and it carries both the long FMCSA
        // description and a short form depending on the row's vintage
        // ('PROPERTY BROKER' and 'BROKER' both occur). Matching the old column
        // meant these three ages were always null.
        $getAuthorityAge = function (string $key) use ($carrier) {

            $types = Fmcsa::authorityType($key);

            $granted = $carrier->authorityHistory
                ->filter(fn ($h) => in_array(strtoupper((string) $h->op_auth_type), $types, true)
                    && strtoupper((string) $h->original_action_desc) === 'GRANTED')
                // orig_served_date is a '24-APR-24' string, so sorting it as
                // text puts the wrong row first.
                ->sortBy(fn ($h) => Fmcsa::dateKey($h->orig_served_date) ?: PHP_INT_MAX)
                ->first();

            $served = Fmcsa::date($granted?->orig_served_date);

            return $served ? (int) $served->diffInYears(now()) : null;
        };

        $authorityAgeCommon = $getAuthorityAge('common');
        $authorityAgeContract = $getAuthorityAge('contract');
        $authorityAgeBroker = $getAuthorityAge('broker');

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
        /*
        | Fleet age comes from the VIN patterns decoded off the request path —
        | see App\Support\Vin. This is a single primary-key read; it never
        | decodes and never aggregates. A carrier nobody has opened before has
        | no row yet, so the cards render blank and a refresh is queued.
        */
        $fleetStats = app(FleetStatsService::class)->forCarrier((string) $carrier->dot_number);

        $fleetStatsAge = $fleetStats['computed_at']
            ? Carbon::parse($fleetStats['computed_at'])->diffInSeconds(now())
            : null;

        if ($fleetStatsAge === null || $fleetStatsAge > (int) config('vin.fleet_stats_ttl', 86400)) {
            // Unique per carrier for 15 minutes, so a popular profile does not
            // queue one of these per view.
            RefreshFleetStats::dispatch((string) $carrier->dot_number);
        }

        /*
        | Year / make / model for the inspection rows this response actually
        | returns. One indexed lookup against the local pattern cache — a miss
        | is simply a null the frontend renders as a dash.
        */
        $vinDecoder = app(VinDecoderService::class);

        $decodedVins = $vinDecoder->lookup(
            $carrier->inspections->flatMap(fn ($inspection) => [$inspection->vin, $inspection->vin2])
        );

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
                'duns' => $detail?->dun_bradstreet_no,
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

                /*
                | Average equipment age in years, over every distinct VIN the
                | carrier has ever been inspected with — not just the recent
                | inspections returned below. Null until the VIN patterns
                | behind this carrier have been decoded.
                */
                'fleet_age' => $fleetStats,

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

                    // sms_measures carries no hazmat totals — it never did, so
                    // these three were always null. The counts do exist on the
                    // inspection rows, which are already loaded.
                    'hazmat' => [
                        'inspections' => $hazmatInspTotal,
                        'oos_inspections' => $hazmatOosTotal,
                        'oos_pct' => $hazmatInspTotal > 0
                            ? round(($hazmatOosTotal / $hazmatInspTotal) * 100, 2)
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
                    // The feed dropped the `*_pct` columns; these are the bands
                    // derived from the measures, so the shape stays the same.
                    ...$this->smsPercentileFields($sms),
                    'risk_score' => round($riskScore, 2),
                ],
                // `$detail` is null for the handful of carriers with no
                // carrier_details row, and this was the one place that reached
                // through it without a null check — a 500 on those profiles.
                'risk_level' => Fmcsa::safetyRating($detail?->safety_rating),
                // ── Inspections ───────────────────────────────────────
                'inspections' => $carrier->inspections->map(function ($inspection) use ($decodedVins) {
                    $data = $inspection->toArray();

                    $data['insp_date'] = $inspection->insp_date
                        ? Carbon::parse($inspection->insp_date)->format('Y-m-d')
                        : null;

                    // Decoded from the VIN. The FMCSA feed carries a make but
                    // never a model or a model year, which is why the fleet
                    // table had nothing to show in those columns.
                    $data['vin_decoded'] = $this->decodedVinFields($decodedVins, $inspection->vin);
                    $data['vin2_decoded'] = $this->decodedVinFields($decodedVins, $inspection->vin2);

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
                // The MCS-150 operation flags come from the SMS census
                // extract, which covers ~761k of the 4.48M carriers — null here
                // means the carrier is not in that extract, not "false".
                'company_snapshot' => [
                    'authorized_for_hire' => $census?->authorized_for_hire,
                    'exempt_for_hire' => $census?->exempt_for_hire,
                    'private_property' => $census?->private_property,
                    'private_passenger_business' => $census?->private_passenger_business,
                    'private_passenger_nonbusiness' => $census?->private_passenger_nonbusiness,
                    'migrant' => $census?->migrant,
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
                    // Was returning the raw OOS count under a `_pct` key, and
                    // reaching through $sms without a null check while it was
                    // at it.
                    'inspections_vehicle_out_of_service_pct' => $vehicleOosPct,
                    'inspections_driver_out_of_service_pct' => $driverOosPct,
                    'oos_alert_vehicle' => $oosAlertVehicle,
                    'oos_alert_driver' => $oosAlertDriver,
                    // FMCSA national hazmat OOS average is 4.5%.
                    'oos_alert_hazmat' => $hazmatInspTotal > 0
                        && ($hazmatOosTotal / $hazmatInspTotal) > 0.045,
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
    /**
     * National benchmarks for the risk factors.
     *
     * Read-only. These are whole-population aggregates — the power-units-per-
     * mile percentile takes about four minutes over 4.48M carriers — so they
     * are computed by `carrier:refresh-benchmarks` and only looked up here.
     * Computing them inline is what made this endpoint time out: the request
     * died before Cache::remember could store anything, so every request paid
     * the full cost again.
     */
    private function benchmarks(): array
    {
        $b = CarrierBenchmarks::all();

        $cuts = $this->smsPercentiles();

        foreach (self::SMS_BASICS as $basic) {
            $b["p90_{$basic}_measure"] = $cuts[$basic][90] > 0 ? $cuts[$basic][90] : null;
        }

        return $b;
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

        // Newest row: the Motus load left duplicate carrier_details rows per
        // DOT number, and an unordered first() picks an arbitrary one.
        $cd = CarrierDetail::query()->where('dot_number', $dot)->first();
        $sms = SmsMeasure::query()->where('dot_number', $dot)->first();
        $census = $carrier->census;

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

        // The filters here had BIPD and cargo the wrong way round: BIPD
        // explicitly excluded form 91X, which is the code virtually every BIPD
        // filing carries, so bipd_insurance_below_requirement fired on everyone
        // while cargo_insurance_on_file was reading BIPD filings.
        $bipdOnFile = $insuranceFilings
            ->filter(fn ($f) => $this->insuranceFilingMatches($f, 'bipd'))
            ->filter($notCancelledOrFuture)
            ->max('max_cov_amount');
        $bipdOnFile = $bipdOnFile !== null ? (float) $bipdOnFile : null;

        $cargoInsuranceOnFile = $insuranceFilings
            ->filter(fn ($f) => $this->insuranceFilingMatches($f, 'cargo'))
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
            // Only known for carriers in the SMS census extract; null there
            // means unknown, so do not assert the negative.
            'not_authorized_for_hire' => $census === null
                ? null
                : ! Fmcsa::flag($census->authorized_for_hire),
            'mcs150_filed_last_24_months' => $mcs150Date ? $mcs150Date->gt(now()->subMonths(24)) : null,
            'oos_below_industry_average' => $oosBelow,
            'smartway_flag' => null,
            'carbtru_flag' => null,
            'phmsa_flag' => null,
            'hazardous_material' => Fmcsa::flag($carrier->hm_flag) || Fmcsa::flag($cd?->hm_ind),
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

    /**
     * Carriers sharing contact details, a name or an address with this one.
     *
     * Deliberately two queries rather than one. The match itself only needs to
     * know which DOT numbers share what, so the UNION selects three columns and
     * stops at ASSOCIATION_MAX_MATCHES per identifier; the per-carrier detail —
     * docket number, DUNS, fleet size, each its own correlated subquery — is
     * then looked up once per distinct carrier instead of once per match row.
     * A carrier on a shared mail drop used to pay for those subqueries
     * thousands of times over, and sort the lot in a filesort afterwards.
     */
    public function association($dot)
    {
        $carrier = $this->getCarrier($dot);

        if (! $carrier) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
            ]);
        }

        return response()->json(Cache::remember(
            'carrier:associations:'.$dot,
            (int) config('carriers.profile_cache_ttl', 900),
            fn () => $this->buildAssociations($carrier, $dot)
        ));
    }

    private function buildAssociations($carrier, $dot): array
    {
        $blocks = [];
        $bindings = [];

        $this->addSimpleMatch($blocks, $bindings, 'EMAIL', 'email_address', $carrier->email_address, $dot);
        $this->addSimpleMatch($blocks, $bindings, 'PHONE', 'telephone', $carrier->telephone, $dot);
        $this->addSimpleMatch($blocks, $bindings, 'FAX', 'fax', $carrier->fax, $dot);

        if (! empty($carrier->legal_name)) {
            $this->addMatch(
                $blocks,
                $bindings,
                'LEGAL NAME',
                'legal_name',
                'legal_name = ?',
                [trim($carrier->legal_name)],
                $dot
            );
        }

        if (! empty($carrier->dba_name)) {
            $this->addMatch(
                $blocks,
                $bindings,
                'DBA NAME',
                'dba_name',
                'dba_name = ?',
                [trim($carrier->dba_name)],
                $dot
            );
        }

        /*
        | Street, city and state are the address. The ZIP was a fourth equality
        | test layered on top of an address the first three had already pinned,
        | and a filter that narrow can only ever lose matches - the census
        | carries the ZIP five digits on some rows and nine on others, so two
        | carriers standing in the same building routinely disagree on it and
        | the shared address silently never reaches the profile. It is still
        | what the panel displays, just no longer what the match turns on.
        |
        | Requiring a ZIP to be present before matching at all had the same
        | effect from the other side: a carrier whose own ZIP is blank got no
        | address block whatsoever.
        |
        | Both matches still lead with the columns idx_phy and idx_mail lead
        | with, so they stay index lookups rather than scans of the census -
        | idx_phy is (phy_state, phy_city, phy_street) and carries no ZIP, so
        | dropping it costs nothing there either.
        */
        if (
            ! empty($carrier->phy_street) &&
            ! empty($carrier->phy_city) &&
            ! empty($carrier->phy_state)
        ) {
            $this->addMatch(
                $blocks,
                $bindings,
                'PHYSICAL ADDRESS',
                "CONCAT_WS(', ', phy_street, phy_city, phy_state, phy_zip)",
                'phy_street=? AND phy_city=? AND phy_state=?',
                [
                    $carrier->phy_street,
                    $carrier->phy_city,
                    $carrier->phy_state,
                ],
                $dot
            );
        }

        if (
            ! empty($carrier->mailing_street) &&
            ! empty($carrier->mailing_city) &&
            ! empty($carrier->mailing_state)
        ) {
            $this->addMatch(
                $blocks,
                $bindings,
                'MAILING ADDRESS',
                "CONCAT_WS(', ', mailing_street, mailing_city, mailing_state, mailing_zip)",
                'mailing_street=? AND mailing_city=? AND mailing_state=?',
                [
                    $carrier->mailing_street,
                    $carrier->mailing_city,
                    $carrier->mailing_state,
                ],
                $dot
            );
        }

        $this->addFormerMatches($blocks, $bindings, $carrier, $dot);

        if (empty($blocks)) {
            return [
                'success' => true,
                'count' => 0,
                'data' => [],
            ];
        }

        // Parenthesised so each part keeps its own LIMIT.
        $matches = DB::connection('external_db')->select(
            '('.implode(') UNION ALL (', $blocks).')',
            $bindings
        );

        $rows = $this->enrichAssociations($matches);

        return [
            'success' => true,
            'count' => count($rows),
            'truncated' => count($matches) > count($rows),
            'data' => $rows,
        ];
    }

    /**
     * Put the per-carrier detail back on the match rows, one lookup per
     * carrier however many identifiers they turned up under.
     */
    private function enrichAssociations(array $matches): array
    {
        if (empty($matches)) {
            return [];
        }

        $dots = $this->pickAssociationDots($matches);

        $placeholders = implode(',', array_fill(0, count($dots), '?'));

        $details = DB::connection('external_db')->select("
            SELECT
                dot_number,
                legal_name,
                dba_name,
                telephone,
                fax,
                email_address,
                CONCAT_WS(', ', phy_street, phy_city, phy_state, phy_zip) AS physical_address,
                CONCAT_WS(', ', mailing_street, mailing_city, mailing_state, mailing_zip) AS mailing_address,
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
            WHERE dot_number IN ({$placeholders})
        ", $dots);

        $byDot = [];

        foreach ($details as $row) {
            $byDot[$row->dot_number] = $row;
        }

        $rows = [];

        foreach ($matches as $match) {
            if (! isset($byDot[$match->dot_number])) {
                continue;
            }

            $rows[] = (object) array_merge((array) $byDot[$match->dot_number], [
                'match_type' => $match->match_type,
                'matched_value' => $match->matched_value,
            ]);
        }

        usort(
            $rows,
            fn ($a, $b) => [$a->legal_name, $a->dot_number] <=> [$b->legal_name, $b->dot_number]
        );

        return $rows;
    }

    /**
     * Which carriers make the ASSOCIATION_MAX_MATCHES cut.
     *
     * Taking them in match order gave the whole allowance to whichever
     * identifier happened to match first: one shared name, or one shared mail
     * drop, fills all 200 slots on its own and the email, phone and address
     * matches queued behind it never reach the profile at all - which is why a
     * carrier could show nothing but name matches. Draw one carrier per match
     * type in turn instead, so every identifier is represented before any one
     * of them takes a second helping.
     */
    private function pickAssociationDots(array $matches): array
    {
        $queues = [];
        $seen = [];

        foreach ($matches as $match) {
            // First type to turn a carrier up owns it - the carrier's other
            // match rows ride along once its DOT number is in.
            if (isset($seen[$match->dot_number])) {
                continue;
            }

            $seen[$match->dot_number] = true;
            $queues[$match->match_type][] = $match->dot_number;
        }

        $picked = [];

        while (count($picked) < self::ASSOCIATION_MAX_MATCHES && ! empty($queues)) {
            foreach ($queues as $type => $queue) {
                $picked[] = array_shift($queue);

                if (empty($queue)) {
                    unset($queues[$type]);
                } else {
                    $queues[$type] = $queue;
                }

                if (count($picked) >= self::ASSOCIATION_MAX_MATCHES) {
                    break;
                }
            }
        }

        return $picked;
    }

    /**
     * Contact history: every change FMCSA has recorded to how this carrier can
     * be reached — addresses, phone and fax numbers, email, the people named as
     * company representatives, and the names it trades under.
     *
     * Read from the change log export on S3 by byte range, so a carrier with a
     * twenty year paper trail costs the same as one registered last week.
     */
    public function contactHistory(CarrierChangeLogService $changeLog, $dot)
    {
        if (! ctype_digit((string) $dot)) {
            return response()->json([
                'success' => false,
                'message' => 'A DOT number is required',
            ], 422);
        }

        // Not an error: the export is indexed by a separate command, and a
        // profile viewed before that has run should say so rather than break.
        if (! $changeLog->isIndexed()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'dot_number' => (string) $dot,
                    'indexed' => false,
                    'summary' => [],
                    'entries' => [],
                    'contact_changes' => 0,
                    'total_changes' => 0,
                    'last_changed_at' => null,
                    'truncated' => false,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $changeLog->contactHistory((string) $dot),
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

    /**
     * Match this carrier's *former* contact details against whoever uses them
     * today, from the FMCSA change log export.
     *
     * Matching on current values alone only finds carriers who are still
     * openly sharing a phone number or an address. The interesting case is the
     * one that moved on: a carrier that changed its number last spring and the
     * company still answering that number now. Those former values come out of
     * the change log, which is read straight from S3 — nothing is imported.
     *
     * One block per identifier, not per value: every column here is the leading
     * column of an index on company_census_file, and an IN list keeps it that
     * way while `matched_value` still names the detail that produced the hit.
     *
     * Silently contributes nothing when the change log has not been indexed on
     * this machine, so associations keep working either way.
     *
     * @see CarrierChangeLogService
     * @see BuildCarrierChangeLogIndex
     */
    private function addFormerMatches(&$blocks, &$bindings, $carrier, $dot)
    {
        $changeLog = app(CarrierChangeLogService::class);

        if (! $changeLog->isIndexed()) {
            return;
        }

        try {
            $former = $changeLog->formerValues((string) $dot, [
                'email_address' => $carrier->email_address ?? null,
                'telephone' => $carrier->telephone ?? null,
                'fax' => $carrier->fax ?? null,
                'legal_name' => $carrier->legal_name ?? null,
                'dba_name' => $carrier->dba_name ?? null,
                'phy_street' => $carrier->phy_street ?? null,
                'mailing_street' => $carrier->mailing_street ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Former-value associations unavailable', [
                'dot' => $dot,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        foreach (self::FORMER_MATCH_COLUMNS as $column => [$label, $valueExpression]) {
            /*
            | phy_street is the one former value with no index to stand on:
            | idx_phy leads with phy_state, and a former street arrives without
            | the city and state that would complete it. Matching it alone is a
            | full scan of the census per lookup, so it stays off until
            | idx_phy_street exists — see database/sql/carrier_indexes.sql.
            */
            if ($column === 'phy_street' && ! config('carriers.former_physical_address_matching', false)) {
                continue;
            }

            $values = $former[$column] ?? [];

            if (empty($values)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($values), '?'));

            $this->addMatch(
                $blocks,
                $bindings,
                $label,
                $valueExpression,
                "{$column} IN ({$placeholders})",
                $values,
                $dot
            );
        }
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
            $column,
            "{$column} = ?",
            [$value],
            $dot
        );
    }

    /**
     * One SELECT per identifier, UNION ALL'd together by the caller.
     *
     * Three columns only — the detail is filled in afterwards, per carrier
     * rather than per match. `$valueExpression` is a column or a CONCAT_WS of
     * columns, never anything the caller took from a request, and gives the
     * profile the value the two carriers actually share instead of only the
     * fact that they share something.
     */
    private function addMatch(&$blocks, &$bindings, $label, $valueExpression, $where, array $values, $dot)
    {
        $blocks[] = "
        SELECT
            '{$label}' AS match_type,
            {$valueExpression} AS matched_value,
            dot_number
        FROM carriers
        WHERE {$where}
          AND dot_number <> ?
        LIMIT ".self::ASSOCIATION_MAX_MATCHES.'
    ';

        foreach ($values as $value) {
            $bindings[] = $value;
        }

        $bindings[] = $dot;
    }

    /**
     * Carriers inspected on the same vehicles as this one — shared equipment.
     *
     * The target's own VINs come from both unit columns, which is cheap:
     * idx_dot_date makes that an index lookup. Matching them against everyone
     * else only uses `vin`, because that is the column carrying idx_vin —
     * joining on `vin2` reads all of sms_input_inspection per lookup, so the
     * trailing-unit side stays off until idx_vin2 exists.
     *
     * Distinct carrier-and-VIN pairs rather than one row per inspection: the
     * profile lists the VINs a carrier shares, so a vehicle inspected forty
     * times was forty identical rows over the wire and one line on the page.
     */
    public function vinAssociation($dot)
    {
        return response()->json(Cache::remember(
            'carrier:vin-associations:'.$dot,
            (int) config('carriers.profile_cache_ttl', 900),
            fn () => $this->buildVinAssociations($dot)
        ));
    }

    /**
     * Distinct carrier-and-VIN pairs sharing a vehicle with this carrier.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function vinPairQuery($dot): array
    {
        $matchTrailingUnit = (bool) config('carriers.vin2_matching', false);

        // Both of the target's own unit columns are cheap to read — dot_number
        // leads idx_dot_date. Only the matching side is index-bound.
        $trailingUnitBlock = $matchTrailingUnit ? '
            UNION

            SELECT DISTINCT i.vin2 AS matched_vin, i.dot_number
            FROM target_vins tv
            JOIN inspections i ON i.vin2 = tv.vin
            WHERE i.dot_number <> ?
        ' : '';

        $bindings = $matchTrailingUnit
            ? [$dot, $dot, $dot, $dot]
            : [$dot, $dot, $dot];

        $sql = '
            WITH target_vins AS (
                SELECT vin
                FROM inspections
                WHERE dot_number = ? AND vin IS NOT NULL AND vin <> ""

                UNION

                SELECT vin2
                FROM inspections
                WHERE dot_number = ? AND vin2 IS NOT NULL AND vin2 <> ""
            )

            SELECT DISTINCT i.vin AS matched_vin, i.dot_number
            FROM target_vins tv
            JOIN inspections i ON i.vin = tv.vin
            WHERE i.dot_number <> ?
            '.$trailingUnitBlock.'
            LIMIT '.self::VIN_MAX_PAIRS;

        return [$sql, $bindings];
    }

    private function buildVinAssociations($dot): array
    {
        [$sql, $bindings] = $this->vinPairQuery($dot);

        $pairs = DB::connection('external_db')->select($sql, $bindings);

        if (empty($pairs)) {
            return [
                'success' => true,
                'count' => 0,
                'data' => [],
            ];
        }

        $dots = array_slice(
            array_values(array_unique(array_map(fn ($pair) => $pair->dot_number, $pairs))),
            0,
            self::ASSOCIATION_MAX_MATCHES
        );

        $placeholders = implode(',', array_fill(0, count($dots), '?'));

        $details = DB::connection('external_db')->select("
            SELECT dot_number, legal_name, dba_name, telephone, fax, email_address
            FROM carriers
            WHERE dot_number IN ({$placeholders})
        ", $dots);

        $byDot = [];

        foreach ($details as $row) {
            $byDot[$row->dot_number] = $row;
        }

        $rows = [];

        foreach ($pairs as $pair) {
            if (! isset($byDot[$pair->dot_number])) {
                continue;
            }

            $rows[] = (object) array_merge((array) $byDot[$pair->dot_number], [
                'match_type' => 'VIN',
                'matched_vin' => $pair->matched_vin,
            ]);
        }

        usort(
            $rows,
            fn ($a, $b) => [$a->legal_name, $a->dot_number] <=> [$b->legal_name, $b->dot_number]
        );

        return [
            'success' => true,
            'count' => count($rows),
            'truncated' => count($pairs) >= self::VIN_MAX_PAIRS || count($pairs) > count($rows),
            'data' => $rows,
        ];
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
