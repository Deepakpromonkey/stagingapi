<?php

namespace App\Http\Controllers\Carrier\Concerns;

use App\Models\Carriers\Carrier;
use App\Models\Carriers\Inspection;
use App\Support\Fmcsa;
use Illuminate\Support\Facades\Cache;

/**
 * DT Trust Score v3 — rule-catalog engine, adapted to the post-Motus schema.
 *
 * Model: every finding is a rule with a tier of risk points
 * (Low 125 / Medium 250 / Review 1,000 / Fail 10,000), MyCarrierPortal-
 * compatible. Points map deterministically onto the 0-100 gauge, so the
 * gauge can never contradict the status:
 *
 *   points <  1,000  -> 100 .. 55   status Acceptable
 *   points <  10,000 ->  54 .. 19   status Unacceptable-Review
 *   points >= 10,000 ->  18 ..  0   status Unacceptable-Fail
 *
 * Missing data abstains — it never fires a rule and never counts as clean.
 * Unknown inputs lower data confidence, which caps the score instead.
 *
 * Installed on CarrierController via `use DtTrustScoreV3;`. Rollback is the
 * two call sites: change dtCalculateTrustScore() back to
 * calculateCarrierTrustScore(), which stays untouched alongside
 * checkKnockout().
 *
 * This trait leans on members that already exist on CarrierController and
 * will fail loudly at boot if they are removed: SMS_BASICS,
 * MAIL_DROP_PATTERNS, hasInsuranceFiling(), insuranceFilingMatches(),
 * smsPercentiles(), benchmarks(), getGrade(), parseFmcsaDate().
 *
 * Return shape is a superset of the old one — overall_score / grade /
 * status / knockout / pillars keep their old keys, values and types, and
 * the full v3 detail rides alongside under 'v3'.
 */
trait DtTrustScoreV3
{
    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    | Same arguments, same order, as calculateCarrierTrustScore() — including
    | the trailing optional $inspectionCount the shortlist call site passes.
    */

    private function dtCalculateTrustScore(
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
        ?int $inspectionCount = null
    ) {
        /*
        |--------------------------------------------------------------------------
        | Evaluate Every Rule Group
        |--------------------------------------------------------------------------
        */

        $groups = [

            'authority_compliance' => $this->dtEvaluateAuthority($carrier, $detail, $auth, $dotAge, $authorityAgeCommon),

            'insurance_financial' => $this->dtEvaluateInsurance($carrier, $auth),

            'safety_roadside' => $this->dtEvaluateSafety($carrier, $sms, $detail, $vehicleOosPct, $driverOosPct),

            'crash_history' => $this->dtEvaluateCrash($carrier, $crashesTotal, $crashFatalities, $crashInjuries, $crashesTowAway),

            'inspection_quality' => $this->dtEvaluateInspection($carrier, $sms),

            'identity_fraud' => $this->dtEvaluateIdentity($carrier, $detail),

            'operations_experience' => $this->dtEvaluateOperations($carrier, $detail, $dotAge, $mcs150Year, $observedUnits, $observedTrailers),

        ];

        /*
        |--------------------------------------------------------------------------
        | Totals
        |--------------------------------------------------------------------------
        */

        $riskPoints = 0;

        $fail = false;

        $fired = [];

        $pending = [];

        $unknown = [];

        $caps = [];

        $flags = [];

        foreach ($groups as $key => $group) {

            $riskPoints += $group['points'];

            $fail = $fail || $group['fail'];

            foreach ($group['fired'] as $rule) {
                $fired[] = array_merge(['group' => $key], $rule);
            }

            foreach ($group['pending'] as $rule) {
                $pending[] = ['group' => $key, 'rule' => $rule];
            }

            foreach ($group['unknown'] as $field) {
                $unknown[] = ['group' => $key, 'field' => $field];
            }

            foreach ($group['caps'] as $cap) {
                $caps[] = array_merge(['group' => $key], $cap);
            }

            foreach ($group['flags'] as $flag) {
                $flags[] = $flag;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Status, Score, Caps
        |--------------------------------------------------------------------------
        */

        $status = $fail
            ? 'Unacceptable-Fail'
            : ($riskPoints >= 1000 ? 'Unacceptable-Review' : 'Acceptable');

        $score = $this->dtPointsToScore((float) $riskPoints);

        [$confidence, $confidenceCap, $confidenceInputs] = $this->dtDataConfidence(
            $carrier,
            $detail,
            $sms,
            $auth,
            $dotAge,
            $mcs150Year
        );

        if (! $fail && $confidenceCap !== null && $score > $confidenceCap) {

            $caps[] = [
                'group' => 'data_confidence',
                'cap' => $confidenceCap,
                'reason' => 'Data confidence '.$confidence.' caps the score.',
            ];

        }

        $capApplied = null;

        if (! $fail) {

            foreach ($caps as $cap) {

                if ($score > $cap['cap']) {

                    $score = (float) $cap['cap'];

                    $capApplied = $cap;

                }

            }

        }

        $band = $this->dtBandFor($status, $score);

        $needsReview = $status !== 'Acceptable'
            || $confidence < 0.65
            || count($unknown) > 0
            || count($flags) > 0;

        /*
        |--------------------------------------------------------------------------
        | Legacy Shape
        |--------------------------------------------------------------------------
        | Everything the front end reads today keeps its old key, type and
        | vocabulary. On a Fail the score pins to the historical 18 and
        | pillars come back empty, exactly as checkKnockout() did.
        */

        $failReasons = collect($fired)
            ->where('tier', 'fail')
            ->map(fn ($rule) => [
                'code' => $rule['code'] ?? $rule['id'],
                'message' => $rule['message'] ?? $rule['label'],
            ])
            ->values()
            ->all();

        $overall = $fail ? 18 : (int) round($score);

        $legacyStatus = match (true) {
            $status === 'Unacceptable-Fail' => 'Rejected',
            $status === 'Unacceptable-Review' => 'Review',
            $overall >= 80 => 'Approved',
            $overall >= 60 => 'Review',
            default => 'High Risk',
        };

        return [

            'overall_score' => $overall,

            'grade' => $this->getGrade($overall),

            'status' => $legacyStatus,

            'knockout' => [

                'triggered' => $fail,

                'cap_score' => $fail ? 18 : null,

                'reasons' => $failReasons,

            ],

            'pillars' => $fail ? [] : $this->dtLegacyPillars($groups),

            'model_version' => 'dt-trust-v3.1-motus',

            'v3' => [

                'model_version' => 'dt-trust-v3.1-motus',

                'risk_points' => $riskPoints,

                'status' => $status,

                'score' => $score,

                'band' => $band,

                'rules_fired' => $fired,

                'rules_pending' => $pending,

                'unknown_inputs' => $unknown,

                'score_caps' => $caps,

                'score_cap_applied' => $capApplied,

                'data_confidence' => $confidence,

                'data_confidence_inputs' => $confidenceInputs,

                'needs_manual_review' => $needsReview,

                'flags' => array_values(array_unique($flags)),

            ],

        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Authority & Compliance
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateAuthority($carrier, $detail, $auth, $dotAge, $authorityAgeCommon = null): array
    {
        $g = $this->dtGroup();

        /*
        | Authority status — 'A' / 'I' / 'N' since the Motus load, but SAFER
        | responses and older rows also carry 'ACTIVE' and 'AUTHORIZED FOR
        | Property'. Fire only when BOTH statuses are positively inactive;
        | one unknown means the rule abstains and the score caps instead.
        */

        $common = $this->dtAuthorityActive($auth?->common_stat);

        $contract = $this->dtAuthorityActive($auth?->contract_stat);

        if ($auth === null || ($common === null && $contract === null)) {

            $g['unknown'][] = 'authority_status';

            $g['caps'][] = ['cap' => 84, 'reason' => 'No usable authority record — capped, not cleared.'];

            $g['flags'][] = 'authority_record_missing';

        } elseif ($common !== true && $contract !== true && ($common === false || $contract === false)) {

            if ($common === false && $contract === false) {

                $this->dtFire($g, 'AUTH-01', 'fail', 'Common and Contract Authority are both inactive.', [
                    'code' => 'AUTHORITY_INACTIVE',
                    'message' => 'Common and Contract Authority are both inactive.',
                ]);

            } else {

                // One inactive, one unreadable — abstain but cap.
                $g['unknown'][] = 'authority_status_partial';

                $g['caps'][] = ['cap' => 84, 'reason' => 'Authority status partially unreadable.'];

            }

        }

        /*
        |--------------------------------------------------------------------------
        | DOT Inactive
        |--------------------------------------------------------------------------
        */

        if (empty($carrier?->dot_number)) {

            $this->dtFire($g, 'AUTH-02', 'fail', 'DOT Number is missing.', [
                'code' => 'DOT_INACTIVE',
                'message' => 'DOT Number is inactive.',
            ]);

        } elseif ($detail === null) {

            $g['unknown'][] = 'dot_status';

        } elseif (strtoupper((string) ($detail->status_code ?? '')) === 'I') {

            $this->dtFire($g, 'AUTH-02', 'fail', 'DOT Number is inactive.', [
                'code' => 'DOT_INACTIVE',
                'message' => 'DOT Number is inactive.',
            ]);

        }

        /*
        |--------------------------------------------------------------------------
        | Out Of Service Order
        |--------------------------------------------------------------------------
        | Same read as checkKnockout(): carrier_oos_orders spells status out
        | in full, and rescind_date is a varchar so blank counts as not
        | rescinded alongside NULL.
        */

        $activeOos = $carrier->oosOrders
            ->filter(fn ($o) => strtoupper((string) $o->status) === 'ACTIVE' && empty($o->rescind_date))
            ->count();

        if ($activeOos > 0) {

            $this->dtFire($g, 'AUTH-03', 'fail', 'Active Out Of Service Order.', [
                'code' => 'OUT_OF_SERVICE',
                'message' => 'Carrier currently has an active Out Of Service Order.',
            ]);

        }

        /*
        |--------------------------------------------------------------------------
        | Revocations Pending / Applications Pending
        |--------------------------------------------------------------------------
        */

        $revPending = $this->dtFlagValue($auth?->common_rev_pend) === true
            || $this->dtFlagValue($auth?->contract_rev_pend) === true
            || $this->dtFlagValue($auth?->broker_rev_pend) === true;

        if ($revPending) {
            $this->dtFire($g, 'AUTH-06', 'review', 'Authority revocation pending.');
        }

        $appPending = $this->dtFlagValue($auth?->common_app_pend) === true
            || $this->dtFlagValue($auth?->contract_app_pend) === true
            || $this->dtFlagValue($auth?->broker_app_pend) === true;

        if ($appPending) {
            $this->dtFire($g, 'AUTH-07', 'low', 'Authority application pending.');
        }

        /*
        |--------------------------------------------------------------------------
        | Reinstated After Revocation
        |--------------------------------------------------------------------------
        | CAVRA §6: reinstatement is an escalation on its own, even with
        | authority active today.
        */

        if (
            strtoupper((string) ($detail?->prior_revoke_flag ?? '')) === 'Y' &&
            ($common === true || $contract === true)
        ) {

            $this->dtFire($g, 'AUTH-08', 'review', 'Authority reinstated after a prior revocation.');

            $g['flags'][] = 'reinstated_after_revocation';

        }

        /*
        |--------------------------------------------------------------------------
        | Revocation History / Suspensions
        |--------------------------------------------------------------------------
        */

        $revocations = $carrier->authorityHistory
            ->filter(fn ($item) => stripos((string) $item->disp_action_desc, 'REVOK') !== false)
            ->count();

        if ($revocations >= 3) {
            $this->dtFire($g, 'AUTH-09', 'review', 'Three or more authority revocations on record.');
        } elseif ($revocations >= 1) {
            $this->dtFire($g, 'AUTH-09', 'medium', 'Authority revocation on record.');
        }

        $suspensions = $carrier->authorityOrders
            ->filter(fn ($item) => stripos((string) $item->order2_type_desc, 'SUSPEND') !== false)
            ->count();

        if ($suspensions >= 1) {
            $this->dtFire($g, 'AUTH-10', 'medium', 'Authority suspension order on record.');
        }

        /*
        |--------------------------------------------------------------------------
        | Authority Age — CAVRA §6 / App A, in DAYS
        |--------------------------------------------------------------------------
        | The call site still passes whole years, which collapses 10 days and
        | 23 months into the same bucket, so the trait re-derives the age of
        | the newest GRANTED carrier authority itself from authorityHistory.
        | < 30 days: review + senior approval, capped 45.
        | 30-89 days: documented review, capped 65.
        */

        $ageDays = $this->dtAuthorityAgeDays($carrier);

        $g['params']['carrier_authority_age_days'] = $ageDays;

        if ($ageDays !== null) {

            if ($ageDays < 30) {

                $this->dtFire($g, 'OPS-01', 'review', 'Carrier authority granted fewer than 30 days ago.');

                $g['caps'][] = ['cap' => 45, 'reason' => 'Authority younger than 30 days.'];

                $g['flags'][] = 'senior_approval_required';

            } elseif ($ageDays < 90) {

                $this->dtFire($g, 'OPS-04', 'medium', 'Carrier authority granted fewer than 90 days ago.');

                $g['caps'][] = ['cap' => 65, 'reason' => 'Authority younger than 90 days.'];

                $g['flags'][] = 'documented_review_required';

            }

        } elseif ($dotAge !== null && (int) $dotAge < 1 && $carrier->authorityHistory->count() === 0) {

            // No grant history at all and the DOT itself is under a year old:
            // can't tell 20 days from 300, so take the 90-day treatment.
            $this->dtFire($g, 'OPS-04', 'medium', 'Authority age unknown; DOT registered under a year ago.');

            $g['caps'][] = ['cap' => 65, 'reason' => 'Authority age unknown on a first-year DOT.'];

            $g['flags'][] = 'documented_review_required';

        } elseif ($ageDays === null) {

            $g['unknown'][] = 'authority_age';

        }

        $g['params'] += [
            'common_authority' => $auth?->common_stat,
            'contract_authority' => $auth?->contract_stat,
            'broker_authority' => $auth?->broker_stat,
            'active_oos_orders' => $activeOos,
            'revocation_count' => $revocations,
            'suspension_orders' => $suspensions,
            'authority_age_common_years' => $authorityAgeCommon,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Insurance & Financial
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateInsurance($carrier, $auth): array
    {
        $g = $this->dtGroup();

        /*
        | BIPD — two-source check exactly as checkKnockout(): the authority
        | flag OR a live filing. bipd_file is a coverage amount in thousands
        | ('00000' means none), so it goes through dtOnFileAmount(), never
        | empty().
        */

        $bipdFlagAmount = $this->dtOnFileAmount($auth?->bipd_file);          // thousands

        $bipdFilingAmount = $this->dtLatestFilingAmount($carrier, 'bipd');   // dollars

        $hasBipd = ($bipdFlagAmount !== null && $bipdFlagAmount > 0)
            || $this->hasInsuranceFiling($carrier, 'bipd');

        if ($auth === null && ! $hasBipd) {

            // No authority row and no filing: unknown, already capped at 84
            // by the authority group. Do not fire.
            $g['unknown'][] = 'bipd';

        } elseif (! $hasBipd) {

            $this->dtFire($g, 'INS-01', 'fail', 'No active BIPD insurance filing.', [
                'code' => 'NO_BIPD',
                'message' => 'No active BIPD insurance filing.',
            ]);

        } else {

            /*
            | Sufficiency — CAVRA App A: on file AND enough. Required comes
            | off the authority row, which stores THOUSANDS (the display
            | path multiplies by 1000 at ~L3540); default 750,000 when the
            | row doesn't say. Fire only when the on-file amount is
            | positively known and short.
            */

            $required = $this->dtOnFileAmount($auth?->min_cov_amount);

            $requiredDollars = ($required !== null && $required > 0) ? $required * 1000 : 750000.0;

            $onFileDollars = $bipdFilingAmount
                ?? (($bipdFlagAmount !== null && $bipdFlagAmount > 0) ? $bipdFlagAmount * 1000 : null);

            $g['params']['bipd_on_file_amount'] = $onFileDollars;

            $g['params']['bipd_required_amount'] = $requiredDollars;

            if ($onFileDollars !== null && $onFileDollars > 0 && $onFileDollars < $requiredDollars) {

                $this->dtFire($g, 'INS-02', 'fail', 'BIPD coverage on file is below the required minimum.', [
                    'code' => 'BIPD_INSUFFICIENT',
                    'message' => 'BIPD on file ($'.number_format($onFileDollars).') is below the required $'.number_format($requiredDollars).'.',
                ]);

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Cargo Insurance
        |--------------------------------------------------------------------------
        */

        if (
            $this->dtFlagValue($auth?->cargo_req) === true &&
            ($this->dtOnFileAmount($auth?->cargo_file) ?? 0) <= 0 &&
            ! $this->hasInsuranceFiling($carrier, 'cargo')
        ) {

            $this->dtFire($g, 'INS-03', 'fail', 'Cargo insurance required but not on file.', [
                'code' => 'NO_CARGO_INSURANCE',
                'message' => 'Cargo Insurance required but not on file.',
            ]);

        }

        /*
        |--------------------------------------------------------------------------
        | Bond
        |--------------------------------------------------------------------------
        | Deliberately demoted from a knockout to Low: BMC-84/85 bonds bind
        | brokers and forwarders, not motor carriers, so a bond-only gap is
        | a finding, not a disqualifier. (Score lands ~84.)
        */

        if (
            $this->dtFlagValue($auth?->bond_req) === true &&
            ($this->dtOnFileAmount($auth?->bond_file) ?? 0) <= 0 &&
            ! $this->hasInsuranceFiling($carrier, 'bond')
        ) {

            $this->dtFire($g, 'INS-04', 'low', 'Bond or trust fund required but not on file.');

        }

        /*
        |--------------------------------------------------------------------------
        | Pending Cancellation
        |--------------------------------------------------------------------------
        | A filing with a future cancl_effective_date is coverage with an
        | expiry date already set — Review, same as MyCarrierPortal.
        */

        $pendingCancellation = $carrier->insuranceFilings->contains(function ($f) {

            if (empty($f->cancl_effective_date)) {
                return false;
            }

            return Fmcsa::date($f->cancl_effective_date)?->isFuture() ?? false;
        });

        if ($pendingCancellation) {
            $this->dtFire($g, 'INS-10', 'review', 'Insurance cancellation pending (future effective date).');
        }

        /*
        |--------------------------------------------------------------------------
        | Rejected Pending Filings / Insurer Churn
        |--------------------------------------------------------------------------
        */

        $rejected = $carrier->insuranceFilingsPending
            ->filter(fn ($x) => ! empty($x->rej_date))
            ->count();

        if ($rejected >= 1) {
            $this->dtFire($g, 'INS-11', 'medium', 'Rejected insurance filing on record.');
        }

        $companyChanges = $carrier->insuranceFilingsHistory
            ->pluck('name_company')
            ->filter()
            ->unique()
            ->count();

        if ($companyChanges >= 8) {
            $this->dtFire($g, 'INS-12', 'medium', 'Eight or more insurance companies in the filing history.');
        } elseif ($companyChanges >= 5) {
            $this->dtFire($g, 'INS-12', 'low', 'Five or more insurance companies in the filing history.');
        }

        $g['params'] += [
            'active_filings' => $carrier->insuranceFilings->count(),
            'pending_filings' => $carrier->insuranceFilingsPending->count(),
            'history_filings' => $carrier->insuranceFilingsHistory->count(),
            'rejected_filings' => $rejected,
            'insurance_company_changes' => $companyChanges,
            'bipd_file' => $auth?->bipd_file,
            'cargo_required' => $auth?->cargo_req,
            'bond_required' => $auth?->bond_req,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Safety & Roadside
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateSafety($carrier, $sms, $detail, $vehicleOosPct, $driverOosPct): array
    {
        $g = $this->dtGroup();

        /*
        | Safety rating — CAVRA §7: Unsatisfactory AND Conditional are hard
        | stops (Montgomery v. Caribe turned on a Conditional carrier). A
        | blank rating means UNRATED — roughly two-thirds of carriers have
        | never been rated — and scores nothing.
        */

        $rating = strtoupper(trim((string) ($detail?->safety_rating ?? '')));

        if ($rating === 'U' || $rating === 'UNSATISFACTORY') {

            $this->dtFire($g, 'SAF-01', 'fail', 'Unsatisfactory Safety Rating.', [
                'code' => 'UNSATISFACTORY_RATING',
                'message' => 'Carrier has an Unsatisfactory Safety Rating.',
            ]);

        } elseif ($rating === 'C' || $rating === 'CONDITIONAL') {

            $this->dtFire($g, 'SAF-02', 'fail', 'Conditional Safety Rating.', [
                'code' => 'CONDITIONAL_RATING',
                'message' => 'Carrier has a Conditional Safety Rating.',
            ]);

        }

        $g['params']['safety_rating'] = $rating === '' ? 'UNRATED' : $rating;

        /*
        |--------------------------------------------------------------------------
        | BASICs — intervention thresholds
        |--------------------------------------------------------------------------
        | Property-carrier percentiles left the public feed with the FAST
        | Act, so the bands are reconstructed from raw measures against the
        | national cut-points (smsPercentiles). The intervention thresholds
        | are 65 (Unsafe Driving, HOS, Controlled Substances — CAVRA §7
        | pins CS at 65, stricter than FMCSA's 80) and 80 (Vehicle
        | Maintenance, Driver Fitness). Until carrier:refresh-benchmarks
        | emits 65/80 cut-points, the nearest cut ABOVE the threshold is
        | used (75 / 90) — deliberately under-inclusive, never over.
        |
        | One BASIC over: Medium. Two or more over: Fail — CAVRA §7 says a
        | carrier over two thresholds is not used, full stop.
        */

        $inspTotal = (int) ($sms?->insp_total ?? 0);

        if ($sms === null) {

            $g['unknown'][] = 'sms_measures';

        } elseif ($inspTotal < 5) {

            $g['unknown'][] = 'basic_percentiles_insufficient_inspections';

        } else {

            $cuts = $this->smsPercentiles();

            $thresholds = [
                'unsafe_driv' => 65,
                'hos_driv' => 65,
                'contr_subst' => 65,
                'veh_maint' => 80,
                'driv_fit' => 80,
            ];

            $labels = [
                'unsafe_driv' => 'Unsafe Driving',
                'hos_driv' => 'HOS Compliance',
                'contr_subst' => 'Controlled Substances',
                'veh_maint' => 'Vehicle Maintenance',
                'driv_fit' => 'Driver Fitness',
            ];

            $over = [];

            foreach (self::SMS_BASICS as $basic) {

                $threshold = $thresholds[$basic];

                $fallback = $threshold <= 65 ? 75 : 90;

                $cut = $cuts[$basic][$threshold] ?? $cuts[$basic][$fallback] ?? null;

                $measure = $sms->{"{$basic}_measure"};

                $g['params']["{$basic}_measure"] = $measure;

                if ($cut === null || $cut <= 0) {

                    $g['unknown'][] = "{$basic}_cutpoint";

                    continue;
                }

                if ($measure === null) {
                    continue;
                }

                if ((float) $measure > 0 && (float) $measure >= (float) $cut) {

                    $over[] = $basic;

                    $this->dtFire(
                        $g,
                        'SMS-'.strtoupper($basic),
                        'medium',
                        $labels[$basic].' BASIC at or above the intervention threshold.'
                    );

                }

            }

            $g['params']['basics_over_threshold'] = count($over);

            if (count($over) >= 2) {

                $this->dtFire($g, 'SMS-MULTI', 'fail', 'Two or more BASICs at or above the intervention threshold.', [
                    'code' => 'MULTIPLE_BASIC_THRESHOLDS',
                    'message' => count($over).' BASICs at or above the intervention threshold.',
                ]);

            }

            /*
            | Acute / critical indicator — the one alert column the Motus
            | load kept. The loader is not populating it yet; NULL abstains,
            | so these start counting the moment it does.
            */

            foreach (self::SMS_BASICS as $basic) {

                if ($this->dtFlagValue($sms->{"{$basic}_ac"}) === true) {

                    $this->dtFire(
                        $g,
                        'SAF-AC-'.strtoupper($basic),
                        'medium',
                        $labels[$basic].' acute/critical violation indicator.'
                    );

                }

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Out Of Service Rates
        |--------------------------------------------------------------------------
        | Measured against the benchmark table's national averages (fraction
        | of inspections), falling back to 20% vehicle / 5% driver if the
        | benchmark rows are absent. Only scored with 5+ relevant
        | inspections — below that the rate is noise.
        */

        $bm = $this->benchmarks();

        $natlVehicle = ((float) ($bm['natl_vehicle_oos'] ?? 0)) * 100;

        $natlDriver = ((float) ($bm['natl_driver_oos'] ?? 0)) * 100;

        if ($natlVehicle <= 0) {
            $natlVehicle = 20.0;
        }

        if ($natlDriver <= 0) {
            $natlDriver = 5.0;
        }

        if ($vehicleOosPct !== null && (int) ($sms?->vehicle_insp_total ?? 0) >= 5) {

            if ($vehicleOosPct >= 2 * $natlVehicle) {
                $this->dtFire($g, 'SAF-10', 'medium', 'Vehicle OOS rate at least twice the national average.');
            } elseif ($vehicleOosPct >= $natlVehicle) {
                $this->dtFire($g, 'SAF-10', 'low', 'Vehicle OOS rate above the national average.');
            }

        }

        if ($driverOosPct !== null && (int) ($sms?->driver_insp_total ?? 0) >= 5) {

            if ($driverOosPct >= 2 * $natlDriver) {
                $this->dtFire($g, 'SAF-11', 'medium', 'Driver OOS rate at least twice the national average.');
            } elseif ($driverOosPct >= $natlDriver) {
                $this->dtFire($g, 'SAF-11', 'low', 'Driver OOS rate above the national average.');
            }

        }

        $g['params'] += [
            'insp_total' => $inspTotal,
            'vehicle_oos_pct' => $vehicleOosPct,
            'driver_oos_pct' => $driverOosPct,
            'natl_vehicle_oos_pct' => $natlVehicle,
            'natl_driver_oos_pct' => $natlDriver,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Crash History
    |--------------------------------------------------------------------------
    | CAVRA §7: no raw counts. The window is 24 months (SMS convention) and
    | the signal is the rate against fleet size. The all-time counts the
    | call site passes stay in the payload for display only.
    */

    private function dtEvaluateCrash($carrier, $crashesTotal, $crashFatalities, $crashInjuries, $crashesTowAway): array
    {
        $g = $this->dtGroup();

        $cutoff = now()->subMonths(24);

        $recent = $carrier->crashes->filter(function ($crash) use ($cutoff) {

            $date = $this->parseFmcsaDate($crash->report_date);

            return $date && $date->gte($cutoff);
        });

        $count24 = $recent->count();

        $fatal24 = (int) $recent->sum('fatalities');

        $injury24 = (int) $recent->sum('injuries');

        $tow24 = $recent->where('tow_away', true)->count();

        if ($fatal24 >= 1) {
            $this->dtFire($g, 'CR-01', 'review', 'Fatal crash within the last 24 months.');
        }

        $powerUnits = (int) ($carrier->nbr_power_unit ?? 0);

        $ratePerUnitYear = ($powerUnits > 0) ? round(($count24 / $powerUnits) / 2, 3) : null;

        if ($ratePerUnitYear !== null && $count24 >= 2) {

            if ($ratePerUnitYear >= 0.5) {
                $this->dtFire($g, 'CR-02', 'medium', 'Crash rate of 0.5+ per power unit per year.');
            } elseif ($ratePerUnitYear >= 0.25) {
                $this->dtFire($g, 'CR-02', 'low', 'Crash rate of 0.25+ per power unit per year.');
            }

        } elseif ($ratePerUnitYear === null) {

            // Fleet size unreported — fall back to windowed volume only.
            if ($count24 >= 10) {
                $this->dtFire($g, 'CR-03', 'medium', 'Ten or more crashes in the last 24 months (fleet size unreported).');
            } elseif ($count24 >= 5) {
                $this->dtFire($g, 'CR-03', 'low', 'Five or more crashes in the last 24 months (fleet size unreported).');
            }

        }

        if ($tow24 >= 5) {
            $this->dtFire($g, 'CR-04', 'low', 'Five or more tow-away crashes in the last 24 months.');
        }

        $g['params'] = [
            'crashes_24mo' => $count24,
            'fatalities_24mo' => $fatal24,
            'injuries_24mo' => $injury24,
            'tow_away_24mo' => $tow24,
            'crashes_per_unit_year' => $ratePerUnitYear,
            'power_units' => $powerUnits ?: null,
            'crashes_total_all_time' => $crashesTotal,
            'crash_fatalities_all_time' => $crashFatalities,
            'crash_injuries_all_time' => $crashInjuries,
            'crashes_tow_away_all_time' => $crashesTowAway,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Inspection Quality
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateInspection($carrier, $sms): array
    {
        $g = $this->dtGroup();

        $inspTotal = (int) ($sms?->insp_total ?? 0);

        if ($inspTotal === 0) {
            $inspTotal = $carrier->inspections->count();
        }

        /*
        | Share of inspections carrying a violation. The per-BASIC
        | insp_w_viol columns overlap (one inspection can violate several
        | BASICs), so the sum is capped at the inspection total — the old
        | uncapped sum produced rates over 100%.
        */

        $withViolations = 0;

        foreach (self::SMS_BASICS as $basic) {
            $withViolations += (int) ($sms?->{"{$basic}_insp_w_viol"} ?? 0);
        }

        $withViolations = min($withViolations, max($inspTotal, 0));

        $violationRate = $inspTotal > 0 ? round($withViolations / $inspTotal, 3) : null;

        if ($inspTotal >= 5 && $violationRate !== null) {

            if ($violationRate >= 0.75) {
                $this->dtFire($g, 'INSP-01', 'medium', 'Violations on 75%+ of inspections.');
            } elseif ($violationRate >= 0.50) {
                $this->dtFire($g, 'INSP-01', 'low', 'Violations on half or more of inspections.');
            }

        }

        $g['params'] = [
            'inspection_count' => $inspTotal,
            'inspections_with_violations' => $withViolations,
            'violation_rate' => $violationRate,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Identity & Fraud
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateIdentity($carrier, $detail): array
    {
        $g = $this->dtGroup();

        /*
        | Internal block / fraud reports — the columns don't exist yet, and
        | property_exists() is always false on an Eloquent model, so the old
        | knockouts were dead code. getAttribute() returns NULL until the
        | columns land; NULL abstains, so these arm themselves the day the
        | migration runs.
        */

        $blocked = $carrier->getAttribute('blocked_internally');

        if ($blocked === null) {
            $g['pending'][] = 'PRT-21 blocked_internally (column not in schema yet)';
        } elseif ($this->dtFlagValue($blocked) === true) {
            $this->dtFire($g, 'PRT-21', 'fail', 'Carrier is internally blocked.', [
                'code' => 'BLOCKED',
                'message' => 'Carrier is internally blocked.',
            ]);
        }

        $fraudReports = $carrier->getAttribute('incident_reports_fraud');

        if ($fraudReports === null) {
            $g['pending'][] = 'PRT-20 incident_reports_fraud (column not in schema yet)';
        } elseif ((int) $fraudReports >= 2) {
            $this->dtFire($g, 'PRT-20', 'fail', 'Multiple fraud reports found.', [
                'code' => 'FRAUD_REPORTS',
                'message' => 'Multiple fraud reports found.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Network Graph — shared identifiers across DOTs
        |--------------------------------------------------------------------------
        | The same lookups riskFactors() runs, distilled to counts and
        | cached for six hours per DOT so the profile page pays for them
        | once, not per view. Needs the indexes riskFactors() already calls
        | out (telephone, email_address, phy_state+phy_city+phy_street,
        | inspections.vin). Kill switch: trustscore.network_checks = false.
        | Any query failure abstains — it never breaks the endpoint.
        */

        $network = $this->dtNetworkCounts($carrier);

        if ($network === null) {

            $g['unknown'][] = 'network_graph';

        } else {

            $g['params']['network_graph'] = $network;

            $sharedLabels = [
                'phone' => ['NET-01', 'Phone number shared with %d other carriers.'],
                'email' => ['NET-02', 'Email address shared with %d other carriers.'],
                'address' => ['NET-03', 'Physical address shared with %d other carriers.'],
            ];

            foreach ($sharedLabels as $key => [$id, $label]) {

                $count = $network[$key];

                if ($count === null) {
                    continue;
                }

                if ($count >= 3) {
                    $this->dtFire($g, $id, 'review', sprintf($label, $count));
                } elseif ($count === 2) {
                    $this->dtFire($g, $id, 'low', sprintf($label, $count));
                }

            }

            if (($network['vin'] ?? 0) >= 3) {
                $this->dtFire($g, 'NET-04', 'medium', 'Roadside VINs shared with three or more other carriers.');
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Address / Contact Hygiene
        |--------------------------------------------------------------------------
        */

        $street = strtoupper(($carrier->phy_street ?? '').' | '.($carrier->mailing_street ?? ''));

        $virtual = collect(self::MAIL_DROP_PATTERNS)
            ->contains(fn ($pattern) => str_contains($street, $pattern));

        if ($virtual) {
            $this->dtFire($g, 'ID-01', 'low', 'Physical or mailing address matches a known mail-drop pattern.');
        }

        if (! empty($carrier->email_address)) {

            $domain = strtolower((string) substr((string) strrchr($carrier->email_address, '@'), 1));

            $freeDomains = [
                'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com',
                'aol.com', 'live.com', 'msn.com', 'protonmail.com',
            ];

            if (in_array($domain, $freeDomains, true)) {
                $this->dtFire($g, 'ID-03', 'low', 'Contact email is a free provider address.');
            }

        }

        $g['params'] += [
            'email' => $carrier->email_address,
            'phone' => $carrier->telephone,
            'virtual_address' => $virtual,
            'prior_revoke_flag' => $detail?->prior_revoke_flag,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Operations & Experience
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateOperations($carrier, $detail, $dotAge, $mcs150Year, $observedUnits, $observedTrailers): array
    {
        $g = $this->dtGroup();

        /*
        |--------------------------------------------------------------------------
        | MCS-150 Currency — carriers must refile every two years.
        |--------------------------------------------------------------------------
        */

        if ($mcs150Year === null) {

            $g['unknown'][] = 'mcs150_year';

        } elseif (((int) now()->year - (int) $mcs150Year) > 2) {

            $this->dtFire($g, 'OPS-10', 'low', 'MCS-150 filing more than two years old.');

        }

        /*
        |--------------------------------------------------------------------------
        | Ghost Fleet — reported power units, none ever seen roadside.
        |--------------------------------------------------------------------------
        | Only meaningful once the carrier has real inspection history;
        | before that it's just the thin-file case, which data confidence
        | already handles.
        */

        $reportedUnits = (int) ($carrier->nbr_power_unit ?? 0);

        $inspectionCount = $carrier->inspections->count();

        if ($reportedUnits > 0 && (int) $observedUnits === 0 && $inspectionCount >= 5) {

            $this->dtFire($g, 'OPS-11', 'low', 'Reported power units, none observed at roadside.');

        }

        $g['params'] = [
            'dot_age_years' => $dotAge,
            'mcs150_year' => $mcs150Year,
            'reported_power_units' => $reportedUnits ?: null,
            'observed_units' => $observedUnits,
            'observed_trailers' => $observedTrailers,
            'mcs150_mileage' => $carrier->mcs150_mileage,
            'driver_total' => (int) ($detail?->total_drivers ?? $carrier->driver_total ?? 0) ?: null,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers — value coercion
    |--------------------------------------------------------------------------
    */

    /**
     * Authority status across every vintage the data carries:
     * 'A' / 'ACTIVE' / 'AUTHORIZED FOR Property' / 'Y' / '1' are active,
     * 'I' / 'N' / 'INACTIVE' / 'NONE' / 'NO' are inactive, anything else
     * (including blank) is unknown.
     */
    private function dtAuthorityActive($value): ?bool
    {
        $value = strtoupper(trim((string) ($value ?? '')));

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['A', 'ACTIVE', 'Y', '1'], true) || str_starts_with($value, 'AUTHORIZED')) {
            return true;
        }

        if (in_array($value, ['I', 'N', 'INACTIVE', 'NONE', 'NO', '0'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Tri-state flag. MySQL BIT(1) arrives as a raw byte ("\x00" is truthy
     * in PHP and trim() eats it), PDO hands numerics back as strings, and
     * the feed mixes Y/N with X markers — so every boolean read goes
     * through here. NULL / blank / placeholder = unknown, never false.
     */
    private function dtFlagValue($value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        $raw = (string) $value;

        if (strlen($raw) === 1 && ord($raw) <= 1) {
            return ord($raw) === 1;   // BIT(1) byte
        }

        $value = strtoupper(trim($raw));

        if ($value === '' || $value === 'NULL' || $value === '-') {
            return null;
        }

        if (in_array($value, ['Y', 'YES', '1', 'X', 'T', 'TRUE'], true)) {
            return true;
        }

        if (in_array($value, ['N', 'NO', '0', 'F', 'FALSE'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Coverage-amount columns (bipd_file, cargo_file, bond_file,
     * min_cov_amount) hold amounts in THOUSANDS as zero-padded strings —
     * '00000' means nothing on file, never "unknown". Returns the numeric
     * amount in thousands, or null when the column is genuinely absent.
     */
    private function dtOnFileAmount($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || strtoupper($value) === 'NULL' || $value === '-') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Latest uncancelled filing amount for a coverage kind, in DOLLARS
     * (the filing columns store thousands). Null when no matching filing.
     */
    private function dtLatestFilingAmount($carrier, string $kind): ?float
    {
        $filing = $carrier->insuranceFilings
            ->filter(fn ($f) => $this->insuranceFilingMatches($f, $kind))
            ->filter(function ($f) {

                if (empty($f->cancl_effective_date)) {
                    return true;
                }

                return Fmcsa::date($f->cancl_effective_date)?->isFuture() ?? false;
            })
            ->sortByDesc(fn ($f) => (float) ($f->max_cov_amount ?? $f->min_cov_amount ?? 0))
            ->first();

        if ($filing === null) {
            return null;
        }

        $amount = $filing->max_cov_amount
            ?? $filing->min_cov_amount
            ?? $filing->underl_lim_amount
            ?? null;

        return $amount === null ? null : ((float) $amount) * 1000;
    }

    /**
     * Age in days of the newest GRANTED carrier (common or contract)
     * authority. The call site passes whole years, which cannot express
     * CAVRA's 30/90-day lines; op_auth_type carries both long and short
     * forms depending on row vintage, and orig_served_date is a
     * '24-APR-24' string, so both go through the Fmcsa helpers.
     */
    private function dtAuthorityAgeDays($carrier): ?int
    {
        $types = array_merge(
            Fmcsa::authorityType('common'),
            Fmcsa::authorityType('contract')
        );

        $granted = $carrier->authorityHistory
            ->filter(fn ($h) => in_array(strtoupper((string) $h->op_auth_type), $types, true)
                && strtoupper((string) $h->original_action_desc) === 'GRANTED')
            ->sortByDesc(fn ($h) => Fmcsa::dateKey($h->orig_served_date) ?: 0)
            ->first();

        $served = Fmcsa::date($granted?->orig_served_date);

        return $served ? (int) $served->diffInDays(now()) : null;
    }

    /**
     * Cross-carrier identifier counts, cached six hours per DOT. Any
     * failure returns null — the identity rules abstain rather than take
     * the endpoint down. Disable with trustscore.network_checks = false.
     */
    private function dtNetworkCounts($carrier): ?array
    {
        if (! config('trustscore.network_checks', true)) {
            return null;
        }

        $dot = (string) $carrier->dot_number;

        if ($dot === '') {
            return null;
        }

        try {

            return Cache::remember('dt:trust:net:'.$dot, 21600, function () use ($carrier, $dot) {

                $phone = null;

                if (! empty($carrier->telephone)) {
                    $phone = (int) Carrier::query()
                        ->where('telephone', $carrier->telephone)
                        ->where('dot_number', '<>', $dot)
                        ->distinct()
                        ->count('dot_number');
                }

                $email = null;

                if (! empty($carrier->email_address)) {
                    $email = (int) Carrier::query()
                        ->where('email_address', $carrier->email_address)
                        ->where('dot_number', '<>', $dot)
                        ->distinct()
                        ->count('dot_number');
                }

                $address = null;

                if (! empty($carrier->phy_street) && strlen(trim((string) $carrier->phy_street)) > 5) {
                    $address = (int) Carrier::query()
                        ->where('phy_state', $carrier->phy_state)
                        ->where('phy_city', $carrier->phy_city)
                        ->where('phy_street', $carrier->phy_street)
                        ->where('dot_number', '<>', $dot)
                        ->distinct()
                        ->count('dot_number');
                }

                $vin = null;

                $vins = $carrier->inspections
                    ->pluck('vin')
                    ->filter(fn ($v) => strlen((string) $v) === 17)
                    ->unique()
                    ->take(200);

                if ($vins->isNotEmpty()) {
                    $vin = (int) Inspection::query()
                        ->whereIn('vin', $vins->all())
                        ->where('dot_number', '<>', $dot)
                        ->distinct()
                        ->count('dot_number');
                }

                return ['phone' => $phone, 'email' => $email, 'address' => $address, 'vin' => $vin];
            });

        } catch (\Throwable $e) {

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers — scoring machinery
    |--------------------------------------------------------------------------
    */

    private function dtGroup(): array
    {
        return [
            'points' => 0,
            'fail' => false,
            'fired' => [],
            'pending' => [],
            'unknown' => [],
            'caps' => [],
            'flags' => [],
            'params' => [],
        ];
    }

    private function dtFire(array &$group, string $id, string $tier, string $label, array $extra = []): void
    {
        $points = $this->dtTierPoints($tier);

        $group['points'] += $points;

        if ($tier === 'fail') {
            $group['fail'] = true;
        }

        $group['fired'][] = array_merge([
            'id' => $id,
            'tier' => $tier,
            'points' => $points,
            'label' => $label,
        ], $extra);
    }

    /** Low 125 / Medium 250 / Review 1,000 / Fail 10,000 — MCP-compatible. */
    private function dtTierPoints(string $tier): int
    {
        return match ($tier) {
            'low' => 125,
            'medium' => 250,
            'review' => 1000,
            'fail' => 10000,
        };
    }

    /**
     * Points -> 0-100, monotonic decreasing so the gauge can never
     * contradict the status. 0 pts = 100; 999 = 55; 1,000 = 54;
     * 10,000 = 18; 30,000+ = 0.
     */
    private function dtPointsToScore(float $points): float
    {
        if ($points >= 10000) {
            return round(max(0.0, 18.0 - (($points - 10000) / 20000) * 18.0), 1);
        }

        if ($points >= 1000) {
            return round(54.0 - (($points - 1000) / 9000) * 35.0, 1);
        }

        return round(100.0 - ($points / 1000) * 45.0, 1);
    }

    private function dtBandFor(string $status, float $score): array
    {
        if ($status === 'Unacceptable-Fail') {
            return [
                'key' => 'disqualified',
                'label' => 'Disqualified',
                'color' => '#B42318',
                'copy' => 'A Fail-tier rule fired. Do not book — no override path.',
            ];
        }

        if ($status === 'Unacceptable-Review') {
            return [
                'key' => 'review_required',
                'label' => 'Review required',
                'color' => '#D9822B',
                'copy' => 'Risk points reached the review threshold. Needs a manager override before booking.',
            ];
        }

        if ($score >= 85) {
            return [
                'key' => 'preferred',
                'label' => 'Preferred',
                'color' => '#1B7A4D',
                'copy' => 'Clears every enabled rule with room to spare.',
            ];
        }

        if ($score >= 70) {
            return [
                'key' => 'acceptable',
                'label' => 'Acceptable',
                'color' => '#2F8F6F',
                'copy' => 'Minor findings only. Standard booking; monitor the flagged rules.',
            ];
        }

        return [
            'key' => 'conditional',
            'label' => 'Conditional',
            'color' => '#D9A416',
            'copy' => 'Approaching the review threshold. Confirm the findings before high-value freight.',
        ];
    }

    /**
     * Data confidence: the share of the eight core inputs that are
     * actually populated. The caps are the v1.1 starting shape and are
     * UNCALIBRATED — run 20-30 known carriers before trusting them.
     */
    private function dtDataConfidence($carrier, $detail, $sms, $auth, $dotAge, $mcs150Year): array
    {
        $inspTotal = (int) ($sms?->insp_total ?? 0);

        if ($inspTotal === 0) {
            $inspTotal = $carrier->inspections->count();
        }

        $inputs = [
            'authority_row' => $auth !== null,
            'detail_row' => $detail !== null,
            'sms_row' => $sms !== null,
            'inspection_history' => $inspTotal >= 5,
            'insurance_data' => $carrier->insuranceFilings->count() > 0
                || (($this->dtOnFileAmount($auth?->bipd_file) ?? 0) > 0),
            'dot_age' => $dotAge !== null,
            'authority_history' => $carrier->authorityHistory->count() > 0,
            'mcs150' => $mcs150Year !== null,
        ];

        $known = count(array_filter($inputs));

        $confidence = round($known / count($inputs), 2);

        $cap = match (true) {
            $confidence < 0.40 => 55.0,
            $confidence < 0.65 => 70.0,
            $confidence < 0.85 => 85.0,
            default => null,
        };

        return [$confidence, $cap, $inputs];
    }

    /**
     * The seven pillar cards the profile page already renders, derived
     * from each group's risk points so the front end needs no change.
     * Presentation only — the overall score comes from the rule totals,
     * never from these.
     */
    private function dtLegacyPillars(array $groups): array
    {
        $weights = [
            'safety_roadside' => 24,
            'identity_fraud' => 20,
            'insurance_financial' => 18,
            'authority_compliance' => 12,
            'crash_history' => 10,
            'inspection_quality' => 8,
            'operations_experience' => 8,
        ];

        $pillars = [];

        foreach ($weights as $key => $weight) {

            $group = $groups[$key];

            $factor = $group['fail']
                ? 0.0
                : max(0.0, 1.0 - $group['points'] / 1000);

            $score = (int) round($weight * $factor);

            $ratio = $weight > 0 ? $score / $weight : 0;

            $status = match (true) {
                $ratio >= 0.90 => 'Excellent',
                $ratio >= 0.72 => 'Good',
                $ratio >= 0.50 => 'Average',
                $ratio >= 0.25 => 'Poor',
                default => 'Critical',
            };

            $pillars[$key] = [

                'weight' => $weight,

                'score' => $score,

                'status' => $status,

                'deductions' => array_values(array_map(fn ($rule) => $rule['label'], $group['fired'])),

                'parameters' => $group['params'],

            ];

        }

        return $pillars;
    }
}
