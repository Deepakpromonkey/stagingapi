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
 * v3.3 adds affiliate handling on NET rules (name-stem exclusion +
 * large-fleet demotion), fleet-normalised CR-01, and an active-broker
 * gate on INS-04.
 *
 * v3.2 adds AUTH-11 (revocation proceedings), AUTH-12 (dual authority),
 * INS-13 (feed-consistency flag), INSP-02 (thin history), INSP-03 (stale
 * history), and tiered NET-04 VIN sharing.
 *
 * Missing data abstains — it never fires a rule and never counts as clean.
 * Unknown inputs lower data confidence, which caps the score instead.
 *
 * INSTALL (two lines, one call):
 *
 *   1. use App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3;   // imports
 *   2. use DtTrustScoreV3;                                          // in class body
 *   3. at the call site (~L3358):
 *        $trustScore = $this->dtCalculateTrustScore(   // was calculateCarrierTrustScore(
 *      Same 17 arguments, same order. Rollback = change that one call back.
 *
 * The old calculateCarrierTrustScore() / checkKnockout() stay untouched.
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
    | Same 17 arguments, same order, as calculateCarrierTrustScore().
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
        $crashesTowAway
    ) {
        /*
        |--------------------------------------------------------------------------
        | Evaluate Every Rule Group
        |--------------------------------------------------------------------------
        */

        $ageDays = $this->dtAuthorityAgeDays($carrier);

        $groups = [

            'authority_compliance' => $this->dtEvaluateAuthority($carrier, $detail, $auth, $dotAge, $observedUnits, $ageDays),

            'insurance_financial' => $this->dtEvaluateInsurance($carrier, $auth),

            'safety_roadside' => $this->dtEvaluateSafety($carrier, $sms, $detail, $vehicleOosPct, $driverOosPct),

            'crash_history' => $this->dtEvaluateCrash($carrier, $crashesTotal, $crashFatalities, $crashInjuries, $crashesTowAway),

            'inspection_quality' => $this->dtEvaluateInspection($carrier, $sms, $ageDays),

            'identity_fraud' => $this->dtEvaluateIdentity($carrier, $detail, $ageDays),

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

        $explanation = $this->dtExplain($groups, [
            'risk_points' => $riskPoints,
            'raw_score' => $this->dtPointsToScore((float) $riskPoints),
            'score_after_caps' => $score,
            'overall_score' => $overall,
            'fail' => $fail,
            'status' => $status,
            'legacy_status' => $legacyStatus,
            'band' => $band,
            'caps' => $caps,
            'cap_applied' => $capApplied,
            'confidence' => $confidence,
            'confidence_cap' => $confidenceCap,
            'confidence_inputs' => $confidenceInputs,
            'unknown' => $unknown,
            'flags' => array_values(array_unique($flags)),
            'fired' => $fired,
            'needs_manual_review' => $needsReview,
        ]);

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

            'model_version' => 'dt-trust-v3.3-motus',

            // Why this number: every rule we evaluated, every input we read,
            // and the arithmetic from risk points to the gauge. Survives a
            // Fail, where 'pillars' is deliberately empty.
            'explanation' => $explanation,

            'v3' => [

                'model_version' => 'dt-trust-v3.3-motus',

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

    private function dtEvaluateAuthority($carrier, $detail, $auth, $dotAge, $observedUnits, $ageDays): array
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
        | Revocation PROCEEDINGS — the near-misses (AUTH-11)
        |--------------------------------------------------------------------------
        | An involuntary revocation proceeding that gets DISCONTINUED days
        | later is an insurance or BOC-3 lapse cured at the deadline.
        | Completed revocations belong to AUTH-09; this rule counts
        | proceedings INITIATED in the last 36 months that did not
        | complete. ('DISCONTINUED REVOCATION' never matched AUTH-09's
        | REVOK pattern — REVOC != REVOK — so these scored zero before.)
        */

        $cutoff36 = now()->subMonths(36);

        $proceedings = $carrier->authorityHistory
            ->filter(function ($h) use ($cutoff36) {

                $orig = strtoupper((string) $h->original_action_desc);

                if (! str_contains($orig, 'INVOLUNTARY') || ! str_contains($orig, 'REVOCATION')) {
                    return false;
                }

                if (stripos((string) $h->disp_action_desc, 'REVOK') !== false) {
                    return false;   // completed — AUTH-09 already counts it
                }

                $served = Fmcsa::date($h->orig_served_date);

                return $served !== null && $served->gte($cutoff36);
            })
            ->count();

        if ($proceedings >= 4) {
            $this->dtFire($g, 'AUTH-11', 'review', $proceedings.' involuntary revocation proceedings initiated in the last 36 months.');
        } elseif ($proceedings >= 2) {
            $this->dtFire($g, 'AUTH-11', 'medium', $proceedings.' involuntary revocation proceedings initiated in the last 36 months.');
        } elseif ($proceedings >= 1) {
            $this->dtFire($g, 'AUTH-11', 'low', 'Involuntary revocation proceeding initiated in the last 36 months.');
        }

        /*
        |--------------------------------------------------------------------------
        | Dual Carrier + Broker Authority — re-brokering risk (AUTH-12)
        |--------------------------------------------------------------------------
        | Legal and common, so never an automatic Fail: the risk is the
        | paper-carrier pattern, not the authority itself. Base Medium;
        | escalates to Review when the equipment story doesn't hold up
        | (nothing ever observed at roadside, authority under 180 days,
        | or a reported fleet of 1-2 trucks).
        */

        if ($this->dtAuthorityActive($auth?->broker_stat) === true && ($common === true || $contract === true)) {

            $reportedPu = (int) ($carrier->nbr_power_unit ?? 0);

            $escalate = ((int) $observedUnits === 0)
                || ($ageDays !== null && $ageDays < 180)
                || ($reportedPu > 0 && $reportedPu <= 2);

            if ($escalate) {
                $this->dtFire($g, 'AUTH-12', 'review', 'Dual carrier + broker authority with re-brokering risk markers.');
            } else {
                $this->dtFire($g, 'AUTH-12', 'medium', 'Active broker authority alongside carrier authority.');
            }

            $g['flags'][] = 'dual_authority';

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
            'authority_age_common_years' => $authorityAgeCommon ?? null,
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
        | Feed Consistency Alarm (INS-13) — flag, never points
        |--------------------------------------------------------------------------
        | The authority row says BIPD is on file, BIPD filings exist in the
        | table, yet none of them is live. FMCSA revokes genuinely lapsed
        | carriers within ~60 days, so this shape almost always means the
        | filings ingest is stale (Warrior: cancellation captured
        | 2025-07-07, the same-day replacement filing never ingested).
        | Unverifiable data gets flagged for a human, not scored.
        */

        if (
            ($bipdFlagAmount ?? 0) > 0 &&
            $carrier->insuranceFilings->contains(fn ($f) => $this->insuranceFilingMatches($f, 'bipd')) &&
            ! $this->hasInsuranceFiling($carrier, 'bipd')
        ) {

            $g['flags'][] = 'insurance_feed_inconsistency';

            $g['unknown'][] = 'insurance_filings_freshness';

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
        | Low, not a knockout — BMC-84/85 bonds bind brokers and forwarders.
        | v3.3: gated on the broker authority being ACTIVE. The verification
        | pack showed bond_req persisting on carriers whose broker authority
        | is inactive (Kreilkamp, broker 'I') or absent (Killingsworth,
        | broker 'N') — requiring a bond for authority nobody operates is a
        | stale flag, not a finding.
        */

        if (
            $this->dtAuthorityActive($auth?->broker_stat) === true &&
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

                /*
                | TEMPORARY DEMOTION (v3.2.1): Review, not Fail. Our cuts are
                | one national sort; FMCSA percentiles are computed within
                | safety event groups, and at low inspection counts FMCSA
                | assigns no percentile at all (DOT 3175931: 5 inspections,
                | measures 7.82 / 15, no FMCSA percentile possible — our
                | engine disqualified it, Highway passed it, both wrong).
                | An unappealable Fail is only defensible on faithful input.
                | RESTORE to 'fail' the day carrier:refresh-benchmarks emits
                | peer-grouped 65/80 cuts — that word is the whole change.
                */
                $this->dtFire($g, 'SMS-MULTI', 'review', 'Two or more BASICs at or above the intervention threshold.', [
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

        $powerUnits = (int) ($carrier->nbr_power_unit ?? 0);

        /*
        | CR-01, v3.3: fleet-normalised. "Any fatal in 24 months = Review"
        | put every mega-fleet in permanent Review (Kaplan, 867 units, is
        | near the statistical baseline with 1-2). Small fleets and high
        | fatal RATES stay Review; a large fleet at baseline rate takes
        | Medium — and every fatal raises a standing flag either way, so
        | the broker always sees it.
        */

        if ($fatal24 >= 1) {

            $g['flags'][] = 'fatal_crash_24mo';

            $fatalRateHigh = $powerUnits > 0 && ($fatal24 / $powerUnits) >= 0.005;   // 1+ per 200 units per 24mo

            if ($powerUnits < 100 || $fatalRateHigh) {
                $this->dtFire($g, 'CR-01', 'review', 'Fatal crash within the last 24 months.');
            } else {
                $this->dtFire($g, 'CR-01', 'medium', 'Fatal crash within the last 24 months (large fleet, baseline rate).');
            }

        }

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

    private function dtEvaluateInspection($carrier, $sms, $ageDays): array
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

        /*
        |--------------------------------------------------------------------------
        | Roadside History Depth & Freshness (INSP-02 / INSP-03)
        |--------------------------------------------------------------------------
        | INSP-02: an established authority (>12 months) with fewer than
        | five inspections — narrows the sufficiency cliff from 83-vs-100
        | to 83-vs-94, because "not enough history to judge" is itself a
        | finding on a carrier that has had a year to accumulate some.
        | INSP-03: history exists but the newest inspection is over a year
        | old — operating with no recent roadside contact.
        */

        $established = $ageDays !== null && $ageDays > 365;

        if ($established && $inspTotal < 5) {
            $this->dtFire($g, 'INSP-02', 'low', 'Fewer than five roadside inspections despite 12+ months of authority.');
        }

        $lastInspDays = null;

        if ($carrier->inspections->count() >= 1) {

            $latest = null;

            foreach ($carrier->inspections->all() as $inspection) {

                $date = Fmcsa::date($inspection->insp_date);

                if ($date && ($latest === null || $date->gte($latest))) {
                    $latest = $date;
                }

            }

            if ($latest !== null) {
                $lastInspDays = (int) $latest->diffInDays(now());
            }

            if ($established) {

                if ($latest === null) {
                    $g['unknown'][] = 'last_inspection_date';
                } elseif ($lastInspDays > 365) {
                    $this->dtFire($g, 'INSP-03', 'low', 'No roadside inspection in the last 12 months.');
                }

            }

        }

        $g['params'] = [
            'inspection_count' => $inspTotal,
            'inspections_with_violations' => $withViolations,
            'violation_rate' => $violationRate,
            'last_inspection_days_ago' => $lastInspDays,
        ];

        return $g;
    }

    /*
    |--------------------------------------------------------------------------
    | Group: Identity & Fraud
    |--------------------------------------------------------------------------
    */

    private function dtEvaluateIdentity($carrier, $detail, $ageDays): array
    {
        $g = $this->dtGroup();

        /*
        | v3.3: the chameleon shape is small and young. A 50+ unit fleet
        | with 5+ years of authority sharing identifiers is a corporate
        | family, not a reincarnation — the verification pack had Kaplan
        | (867 units, 52 years) at Review off its own affiliates. Large
        | established fleets take Medium instead of Review on NET rules;
        | everyone else keeps the full tiers.
        */

        $largeEstablished = ((int) ($carrier->nbr_power_unit ?? 0)) >= 50
            && $ageDays !== null
            && $ageDays >= 1825;

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

                    if ($largeEstablished) {
                        $this->dtFire($g, $id, 'medium', sprintf($label, $count), [
                            'note' => 'Demoted from Review: established fleet of 50+ units.',
                        ]);
                    } else {
                        $this->dtFire($g, $id, 'review', sprintf($label, $count));
                    }

                } elseif ($count === 2) {
                    $this->dtFire($g, $id, 'low', sprintf($label, $count));
                }

            }

            $vinShared = $network['vin'] ?? null;

            if ($vinShared !== null) {

                if ($vinShared >= 5) {

                    if ($largeEstablished) {
                        $this->dtFire($g, 'NET-04', 'medium', 'Roadside VINs shared with '.$vinShared.' other carriers.', [
                            'note' => 'Demoted from Review: established fleet of 50+ units.',
                        ]);
                    } else {
                        $this->dtFire($g, 'NET-04', 'review', 'Roadside VINs shared with '.$vinShared.' other carriers.');
                    }

                } elseif ($vinShared >= 3) {
                    $this->dtFire($g, 'NET-04', 'medium', 'Roadside VINs shared with '.$vinShared.' other carriers.');
                } elseif ($vinShared === 2) {
                    $this->dtFire($g, 'NET-04', 'low', 'Roadside VINs shared with two other carriers.');
                }

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

        /*
        | v3.3: affiliate exclusion. DOTs whose legal_name shares this
        | carrier's name stem (KAPLAN TRUCKING / KAPLAN LOGISTICS at one
        | HQ) are family, not chameleons — reincarnated carriers pick
        | UNRELATED names on purpose, so openly name-linked sharing is
        | weak fraud evidence. Needs an index on carriers.legal_name.
        */

        $stem = $this->dtNameStem($carrier->legal_name);

        $stemLike = $stem !== null ? addcslashes($stem, '\\%_').'%' : null;

        try {

            return Cache::remember('dt:trust:net:v2:'.$dot, 21600, function () use ($carrier, $dot, $stemLike) {

                $phone = null;

                if (! empty($carrier->telephone)) {

                    $q = Carrier::query()
                        ->where('telephone', $carrier->telephone)
                        ->where('dot_number', '<>', $dot);

                    if ($stemLike !== null) {
                        $q->where('legal_name', 'NOT LIKE', $stemLike);
                    }

                    $phone = (int) $q->distinct()->count('dot_number');
                }

                $email = null;

                if (! empty($carrier->email_address)) {

                    $q = Carrier::query()
                        ->where('email_address', $carrier->email_address)
                        ->where('dot_number', '<>', $dot);

                    if ($stemLike !== null) {
                        $q->where('legal_name', 'NOT LIKE', $stemLike);
                    }

                    $email = (int) $q->distinct()->count('dot_number');
                }

                $address = null;

                if (! empty($carrier->phy_street) && strlen(trim((string) $carrier->phy_street)) > 5) {

                    $q = Carrier::query()
                        ->where('phy_state', $carrier->phy_state)
                        ->where('phy_city', $carrier->phy_city)
                        ->where('phy_street', $carrier->phy_street)
                        ->where('dot_number', '<>', $dot);

                    if ($stemLike !== null) {
                        $q->where('legal_name', 'NOT LIKE', $stemLike);
                    }

                    $address = (int) $q->distinct()->count('dot_number');
                }

                $vin = null;

                $vins = $carrier->inspections
                    ->pluck('vin')
                    ->filter(fn ($v) => strlen((string) $v) === 17)
                    ->unique()
                    ->take(200);

                if ($vins->isNotEmpty()) {

                    /*
                    | COUNT(DISTINCT dot_number) here makes MySQL take a
                    | loose index scan on idx_dot_vin_type, whose leading
                    | column is dot_number — 2.35M index entries, measured
                    | at 12.3s for five VINs and ~300s for a 200-VIN fleet
                    | (DOT 120670). Selecting the distinct dot_numbers
                    | instead lets the optimiser use idx_vin (11 rows,
                    | under 10ms) and the row set is small enough to count
                    | in PHP. The limit is a backstop, not a filter: past
                    | it the tier is already pinned at the top band.
                    | Pre-dates v3.3 — the affiliate subquery below costs
                    | 46 rows on idx_legal and was never the bottleneck.
                    */

                    $q = Inspection::query()
                        ->select('dot_number')
                        ->whereIn('vin', $vins->all())
                        ->where('dot_number', '<>', $dot);

                    if ($stemLike !== null) {
                        $q->whereNotIn('dot_number', function ($sub) use ($stemLike) {
                            $sub->select('dot_number')
                                ->from('carriers')
                                ->where('legal_name', 'LIKE', $stemLike);
                        });
                    }

                    $vin = $q->distinct()->limit(5000)->pluck('dot_number')->count();
                }

                return ['phone' => $phone, 'email' => $email, 'address' => $address, 'vin' => $vin];
            });

        } catch (\Throwable $e) {

            return null;
        }
    }

    /**
     * Affiliate name stem: uppercase, punctuation stripped, leading
     * article (THE/A/AN) dropped, first token kept when it is 4+
     * characters. Shorter or absent stems return null and the network
     * counts run unfiltered — conservative in the fraud direction.
     */
    private function dtNameStem($name): ?string
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
    private const DT_TIER_POINTS_LOW = 125;

    private const DT_TIER_POINTS_MEDIUM = 250;

    private const DT_TIER_POINTS_REVIEW = 1000;

    private const DT_TIER_POINTS_FAIL = 10000;

    private function dtTierPoints(string $tier): int
    {
        return match ($tier) {
            'low' => self::DT_TIER_POINTS_LOW,
            'medium' => self::DT_TIER_POINTS_MEDIUM,
            'review' => self::DT_TIER_POINTS_REVIEW,
            'fail' => self::DT_TIER_POINTS_FAIL,
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
        $pillars = [];

        foreach (self::DT_PILLAR_WEIGHTS as $key => $weight) {

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

    /*
    |--------------------------------------------------------------------------
    | Explainability — "why is the score this number?"
    |--------------------------------------------------------------------------
    | The engine already knows everything a broker would need to defend a
    | decision: which rules fired, which inputs they read, which caps bound
    | the gauge and how confident the data was. It just never said so out
    | loud. dtExplain() assembles that into one block on the profile
    | response, and unlike 'pillars' it stays populated on a Fail — a
    | disqualified carrier is exactly when someone asks why.
    |
    | Nothing here participates in scoring. It reports what already happened.
    */

    /**
     * Presentation weights for the seven pillar cards. Shared with
     * dtLegacyPillars() so the explanation can never quote a weight the
     * profile page does not render.
     */
    private const DT_PILLAR_WEIGHTS = [
        'safety_roadside' => 24,
        'identity_fraud' => 20,
        'insurance_financial' => 18,
        'authority_compliance' => 12,
        'crash_history' => 10,
        'inspection_quality' => 8,
        'operations_experience' => 8,
    ];

    private const DT_GROUP_LABELS = [
        'authority_compliance' => 'Authority & Compliance',
        'insurance_financial' => 'Insurance & Financial',
        'safety_roadside' => 'Safety & Roadside',
        'crash_history' => 'Crash History',
        'inspection_quality' => 'Inspection Quality',
        'identity_fraud' => 'Identity & Fraud',
        'operations_experience' => 'Operations & Experience',
    ];

    /**
     * Every rule the engine can fire, so the response can list the ones
     * that stayed quiet as well as the ones that did not. 'tiers' is the
     * set of severities a rule can land on, in escalation order.
     *
     * Keep in step with the dtEvaluate*() methods: a rule missing here is
     * still scored, it just reports as an unnamed finding.
     */
    private const DT_RULE_CATALOG = [

        // ── Authority & Compliance ──────────────────────────────────────
        'AUTH-01' => ['authority_compliance', 'Operating authority inactive', 'Common and Contract authority status on the FMCSA authority record.', ['fail']],
        'AUTH-02' => ['authority_compliance', 'DOT number inactive', 'Presence of a DOT number and the census status code.', ['fail']],
        'AUTH-03' => ['authority_compliance', 'Active out-of-service order', 'Out-of-service orders with no rescind date.', ['fail']],
        'AUTH-06' => ['authority_compliance', 'Revocation pending', 'Common / contract / broker revocation-pending flags.', ['review']],
        'AUTH-07' => ['authority_compliance', 'Application pending', 'Common / contract / broker application-pending flags.', ['low']],
        'AUTH-08' => ['authority_compliance', 'Reinstated after revocation', 'Prior-revoke flag against a currently active authority.', ['review']],
        'AUTH-09' => ['authority_compliance', 'Revocation history', 'Completed revocations in the authority history.', ['medium', 'review']],
        'AUTH-10' => ['authority_compliance', 'Suspension orders', 'Suspension orders in the authority order history.', ['medium']],
        'AUTH-11' => ['authority_compliance', 'Revocation proceedings', 'Involuntary revocation proceedings opened in the last 36 months that did not complete.', ['low', 'medium', 'review']],
        'AUTH-12' => ['authority_compliance', 'Dual carrier + broker authority', 'Active broker authority alongside carrier authority, escalated on re-brokering markers.', ['medium', 'review']],
        'OPS-01' => ['authority_compliance', 'Authority under 30 days old', 'Days since the newest granted carrier authority.', ['review']],
        'OPS-04' => ['authority_compliance', 'Authority under 90 days old', 'Days since the newest granted carrier authority, or an unknown age on a first-year DOT.', ['medium']],

        // ── Insurance & Financial ───────────────────────────────────────
        'INS-01' => ['insurance_financial', 'No BIPD on file', 'BIPD amount on the authority record and in the insurance filings.', ['fail']],
        'INS-02' => ['insurance_financial', 'BIPD below minimum', 'BIPD amount on file against the required minimum.', ['fail']],
        'INS-03' => ['insurance_financial', 'Cargo insurance missing', 'Cargo requirement against cargo filings on record.', ['fail']],
        'INS-04' => ['insurance_financial', 'Bond or trust missing', 'Bond / trust requirement for active broker authority.', ['low']],
        'INS-10' => ['insurance_financial', 'Cancellation pending', 'Insurance filings with a future effective cancellation.', ['review']],
        'INS-11' => ['insurance_financial', 'Rejected filing', 'Insurance filings recorded as rejected.', ['medium']],
        'INS-12' => ['insurance_financial', 'Insurer churn', 'Distinct insurance companies across the filing history.', ['low', 'medium']],

        // ── Safety & Roadside ───────────────────────────────────────────
        'SAF-01' => ['safety_roadside', 'Unsatisfactory safety rating', 'FMCSA safety rating.', ['fail']],
        'SAF-02' => ['safety_roadside', 'Conditional safety rating', 'FMCSA safety rating.', ['fail']],
        'SMS-UNSAFE_DRIV' => ['safety_roadside', 'Unsafe Driving BASIC over threshold', 'Unsafe Driving measure against the national cut-point.', ['medium']],
        'SMS-HOS_DRIV' => ['safety_roadside', 'HOS Compliance BASIC over threshold', 'Hours-of-Service measure against the national cut-point.', ['medium']],
        'SMS-DRIV_FIT' => ['safety_roadside', 'Driver Fitness BASIC over threshold', 'Driver Fitness measure against the national cut-point.', ['medium']],
        'SMS-CONTR_SUBST' => ['safety_roadside', 'Controlled Substances BASIC over threshold', 'Controlled Substances measure against the national cut-point.', ['medium']],
        'SMS-VEH_MAINT' => ['safety_roadside', 'Vehicle Maintenance BASIC over threshold', 'Vehicle Maintenance measure against the national cut-point.', ['medium']],
        'SMS-MULTI' => ['safety_roadside', 'Multiple BASICs over threshold', 'Count of BASICs at or above the intervention threshold.', ['review']],
        'SAF-AC-UNSAFE_DRIV' => ['safety_roadside', 'Unsafe Driving acute/critical', 'Acute-critical indicator on the Unsafe Driving BASIC.', ['medium']],
        'SAF-AC-HOS_DRIV' => ['safety_roadside', 'HOS acute/critical', 'Acute-critical indicator on the HOS BASIC.', ['medium']],
        'SAF-AC-DRIV_FIT' => ['safety_roadside', 'Driver Fitness acute/critical', 'Acute-critical indicator on the Driver Fitness BASIC.', ['medium']],
        'SAF-AC-CONTR_SUBST' => ['safety_roadside', 'Controlled Substances acute/critical', 'Acute-critical indicator on the Controlled Substances BASIC.', ['medium']],
        'SAF-AC-VEH_MAINT' => ['safety_roadside', 'Vehicle Maintenance acute/critical', 'Acute-critical indicator on the Vehicle Maintenance BASIC.', ['medium']],
        'SAF-10' => ['safety_roadside', 'Vehicle OOS rate elevated', 'Vehicle out-of-service rate against the national average, with 5+ vehicle inspections.', ['low', 'medium']],
        'SAF-11' => ['safety_roadside', 'Driver OOS rate elevated', 'Driver out-of-service rate against the national average, with 5+ driver inspections.', ['low', 'medium']],

        // ── Crash History ───────────────────────────────────────────────
        'CR-01' => ['crash_history', 'Recent fatal crash', 'Fatal crashes in the last 24 months, normalised by fleet size.', ['medium', 'review']],
        'CR-02' => ['crash_history', 'Crash rate per power unit', 'Crashes per power unit per year.', ['low', 'medium']],
        'CR-03' => ['crash_history', 'Crash volume', 'Raw crash count over 24 months when fleet size is unreported.', ['low', 'medium']],
        'CR-04' => ['crash_history', 'Tow-away crashes', 'Tow-away crashes in the last 24 months.', ['low']],

        // ── Inspection Quality ──────────────────────────────────────────
        'INSP-01' => ['inspection_quality', 'Violation rate', 'Share of inspections that produced violations.', ['low', 'medium']],
        'INSP-02' => ['inspection_quality', 'Thin inspection history', 'Inspection count against 12+ months of authority.', ['low']],
        'INSP-03' => ['inspection_quality', 'Stale inspection history', 'Time since the most recent roadside inspection.', ['low']],

        // ── Identity & Fraud ────────────────────────────────────────────
        'PRT-21' => ['identity_fraud', 'Internally blocked', 'Internal block list.', ['fail']],
        'PRT-20' => ['identity_fraud', 'Fraud reports', 'Internal fraud reports against this carrier.', ['fail']],
        'NET-01' => ['identity_fraud', 'Shared phone number', 'Other DOTs using the same telephone number.', ['low', 'medium', 'review']],
        'NET-02' => ['identity_fraud', 'Shared email address', 'Other DOTs using the same email address.', ['low', 'medium', 'review']],
        'NET-03' => ['identity_fraud', 'Shared physical address', 'Other DOTs at the same physical address.', ['low', 'medium', 'review']],
        'NET-04' => ['identity_fraud', 'Shared roadside VINs', 'Other DOTs inspected on the same VINs.', ['low', 'medium', 'review']],
        'ID-01' => ['identity_fraud', 'Mail-drop address', 'Physical and mailing street against known mail-drop patterns.', ['low']],
        'ID-03' => ['identity_fraud', 'Free-provider email', 'Contact email domain against the free-provider list.', ['low']],

        // ── Operations & Experience ─────────────────────────────────────
        'OPS-10' => ['operations_experience', 'MCS-150 out of date', 'Years since the last MCS-150 filing.', ['low']],
        'OPS-11' => ['operations_experience', 'Ghost fleet', 'Reported power units against units ever observed at roadside.', ['low']],
    ];

    /**
     * Which rules a given unknown input silences. Missing data abstains
     * rather than passing, so the explanation must not report these as
     * clean — that is the difference between "we checked and it was fine"
     * and "we could not check".
     */
    private const DT_UNKNOWN_BLOCKS = [
        'authority_status' => ['AUTH-01'],
        'authority_status_partial' => ['AUTH-01'],
        'dot_status' => ['AUTH-02'],
        'authority_age' => ['OPS-01', 'OPS-04'],
        'bipd' => ['INS-01', 'INS-02'],
        'insurance_filings_freshness' => ['INS-10', 'INS-11'],
        'sms_measures' => [
            'SMS-UNSAFE_DRIV', 'SMS-HOS_DRIV', 'SMS-DRIV_FIT', 'SMS-CONTR_SUBST', 'SMS-VEH_MAINT',
            'SMS-MULTI',
            'SAF-AC-UNSAFE_DRIV', 'SAF-AC-HOS_DRIV', 'SAF-AC-DRIV_FIT', 'SAF-AC-CONTR_SUBST', 'SAF-AC-VEH_MAINT',
        ],
        'basic_percentiles_insufficient_inspections' => [
            'SMS-UNSAFE_DRIV', 'SMS-HOS_DRIV', 'SMS-DRIV_FIT', 'SMS-CONTR_SUBST', 'SMS-VEH_MAINT', 'SMS-MULTI',
        ],
        'unsafe_driv_cutpoint' => ['SMS-UNSAFE_DRIV'],
        'hos_driv_cutpoint' => ['SMS-HOS_DRIV'],
        'driv_fit_cutpoint' => ['SMS-DRIV_FIT'],
        'contr_subst_cutpoint' => ['SMS-CONTR_SUBST'],
        'veh_maint_cutpoint' => ['SMS-VEH_MAINT'],
        'last_inspection_date' => ['INSP-03'],
        'network_graph' => ['NET-01', 'NET-02', 'NET-03', 'NET-04'],
        'mcs150_year' => ['OPS-10'],
    ];

    /**
     * @param  array  $groups  the seven evaluated rule groups
     * @param  array  $ctx     totals already computed by dtCalculateTrustScore()
     */
    private function dtExplain(array $groups, array $ctx): array
    {
        $blocked = [];

        foreach ($ctx['unknown'] as $entry) {
            foreach (self::DT_UNKNOWN_BLOCKS[$entry['field']] ?? [] as $ruleId) {
                $blocked[$ruleId] = $entry['field'];
            }
        }

        // Indexed once, not re-filtered per group: the search controller runs
        // this engine per result row, so a 7 x 60 walk per carrier is 7 x 60
        // too many.
        static $catalogByGroup = null;

        if ($catalogByGroup === null) {

            $catalogByGroup = [];

            foreach (self::DT_RULE_CATALOG as $ruleId => $entry) {
                $catalogByGroup[$entry[0]][$ruleId] = $entry;
            }

        }

        $groupBlocks = [];

        foreach ($groups as $key => $group) {

            $firedById = [];

            foreach ($group['fired'] as $rule) {
                $firedById[$rule['id']][] = $rule;
            }

            $rules = [];

            foreach ($catalogByGroup[$key] ?? [] as $id => [, $name, $checks, $tiers]) {

                $hits = $firedById[$id] ?? [];

                $rules[] = [
                    'id' => $id,
                    'name' => $name,
                    'checks' => $checks,
                    'possible_tiers' => $tiers,
                    'status' => match (true) {
                        $hits !== [] => 'triggered',
                        isset($blocked[$id]) => 'not_evaluated',
                        default => 'passed',
                    },
                    'tier' => $hits[0]['tier'] ?? null,
                    'points' => array_sum(array_column($hits, 'points')),
                    'finding' => $hits === [] ? null : implode(' ', array_column($hits, 'label')),
                    'not_evaluated_because' => $hits === [] && isset($blocked[$id])
                        ? 'Input "'.$this->dtHumanize($blocked[$id]).'" was unavailable, so the rule abstained.'
                        : null,
                ];
            }

            // Anything fired that the catalog does not name still has to
            // show up — an unlisted rule is a documentation gap, not a
            // reason to hide points from the broker.
            foreach ($firedById as $id => $hits) {

                if (isset(self::DT_RULE_CATALOG[$id])) {
                    continue;
                }

                $rules[] = [
                    'id' => $id,
                    'name' => $hits[0]['label'] ?? $id,
                    'checks' => null,
                    'possible_tiers' => [$hits[0]['tier']],
                    'status' => 'triggered',
                    'tier' => $hits[0]['tier'],
                    'points' => array_sum(array_column($hits, 'points')),
                    'finding' => implode(' ', array_column($hits, 'label')),
                    'not_evaluated_because' => null,
                ];
            }

            $counts = array_count_values(array_column($rules, 'status'));

            $parameters = [];

            foreach ($group['params'] as $name => $value) {
                $parameters[] = [
                    'key' => $name,
                    'label' => $this->dtHumanize($name),
                    'value' => $value,
                ];
            }

            $groupBlocks[$key] = [

                'label' => self::DT_GROUP_LABELS[$key] ?? $this->dtHumanize($key),

                'pillar_weight' => self::DT_PILLAR_WEIGHTS[$key] ?? null,

                'risk_points' => $group['points'],

                'share_of_risk_points' => $ctx['risk_points'] > 0
                    ? round($group['points'] / $ctx['risk_points'] * 100, 1)
                    : 0.0,

                'contains_fail' => $group['fail'],

                'rules_checked' => count($rules),

                'rules_triggered' => $counts['triggered'] ?? 0,

                'rules_passed' => $counts['passed'] ?? 0,

                'rules_not_evaluated' => $counts['not_evaluated'] ?? 0,

                'rules' => $rules,

                'parameters' => $parameters,

                'caps' => $group['caps'],

                'flags' => $group['flags'],
            ];
        }

        $topReasons = collect($ctx['fired'])
            ->sortByDesc('points')
            ->take(5)
            ->map(fn ($rule) => [
                'id' => $rule['id'],
                'group' => $rule['group'],
                'tier' => $rule['tier'],
                'points' => $rule['points'],
                'reason' => $rule['message'] ?? $rule['label'],
            ])
            ->values()
            ->all();

        $totals = [
            'rules_checked' => array_sum(array_column($groupBlocks, 'rules_checked')),
            'rules_triggered' => array_sum(array_column($groupBlocks, 'rules_triggered')),
            'rules_passed' => array_sum(array_column($groupBlocks, 'rules_passed')),
            'rules_not_evaluated' => array_sum(array_column($groupBlocks, 'rules_not_evaluated')),
        ];

        return [

            'model_version' => 'dt-trust-v3.3-motus',

            'summary' => $this->dtExplainSummary($ctx, $totals),

            'how_it_works' => [
                'method' => 'Every finding is a rule carrying a fixed tier of risk points. Points are totalled across seven groups, then mapped onto the 0-100 gauge, so the gauge can never contradict the status.',
                'tier_points' => [
                    'low' => self::DT_TIER_POINTS_LOW,
                    'medium' => self::DT_TIER_POINTS_MEDIUM,
                    'review' => self::DT_TIER_POINTS_REVIEW,
                    'fail' => self::DT_TIER_POINTS_FAIL,
                ],
                'bands' => [
                    ['points' => 'under 1,000', 'score' => '100 - 55', 'status' => 'Acceptable'],
                    ['points' => '1,000 - 9,999', 'score' => '54 - 19', 'status' => 'Unacceptable-Review'],
                    ['points' => '10,000 and over', 'score' => '18 - 0', 'status' => 'Unacceptable-Fail'],
                ],
                'abstention' => 'Missing data never fires a rule and never counts as clean. It lowers data confidence, which caps the score instead.',
                'caps' => 'A cap is a ceiling, not a deduction: the lowest cap that bites replaces the score outright.',
                'pillar_weights' => 'Pillar weights are presentation only — the overall score comes from the rule totals, never from the pillar cards.',
            ],

            'score_math' => [
                'starting_score' => 100,
                'risk_points' => $ctx['risk_points'],
                'score_from_points' => $ctx['raw_score'],
                'caps_considered' => $ctx['caps'],
                'cap_applied' => $ctx['cap_applied'],
                'score_after_caps' => $ctx['score_after_caps'],
                'fail_override' => $ctx['fail'],
                'final_score' => $ctx['overall_score'],
                'steps' => $this->dtExplainSteps($ctx),
            ],

            'outcome' => [
                'score' => $ctx['overall_score'],
                'grade' => $this->getGrade($ctx['overall_score']),
                'status' => $ctx['status'],
                'legacy_status' => $ctx['legacy_status'],
                'band' => $ctx['band'],
                'needs_manual_review' => $ctx['needs_manual_review'],
            ],

            'top_reasons' => $topReasons,

            'rule_totals' => $totals,

            'groups' => $groupBlocks,

            'data_confidence' => [
                'value' => $ctx['confidence'],
                'cap' => $ctx['confidence_cap'],
                'inputs' => collect($ctx['confidence_inputs'])
                    ->map(fn ($present, $name) => [
                        'key' => $name,
                        'label' => $this->dtHumanize($name),
                        'present' => $present,
                    ])
                    ->values()
                    ->all(),
                'note' => 'The share of the eight core inputs that are populated. Thin data caps the gauge rather than lowering it.',
            ],

            'inputs_unavailable' => array_map(
                fn ($entry) => [
                    'group' => $entry['group'],
                    'key' => $entry['field'],
                    'label' => $this->dtHumanize($entry['field']),
                    'rules_abstained' => self::DT_UNKNOWN_BLOCKS[$entry['field']] ?? [],
                ],
                $ctx['unknown']
            ),

            'flags' => $ctx['flags'],
        ];
    }

    /** One line a broker can read off the screen without expanding anything. */
    private function dtExplainSummary(array $ctx, array $totals): string
    {
        $parts = [];

        $parts[] = sprintf(
            'Scored %d of 100 (%s) from %s risk points across %d of %d rules checked.',
            $ctx['overall_score'],
            $ctx['band']['label'] ?? $ctx['status'],
            number_format($ctx['risk_points']),
            $totals['rules_triggered'],
            $totals['rules_checked']
        );

        if ($ctx['fail']) {
            $parts[] = 'A Fail-tier rule fired, which pins the score to 18 regardless of the points total.';
        } elseif ($ctx['cap_applied'] !== null) {
            $parts[] = sprintf(
                'Capped at %s: %s',
                $ctx['cap_applied']['cap'],
                $ctx['cap_applied']['reason'] ?? 'cap applied.'
            );
        }

        if ($totals['rules_not_evaluated'] > 0) {
            $parts[] = sprintf(
                '%d rules could not be evaluated because their inputs are missing; they abstained rather than passing.',
                $totals['rules_not_evaluated']
            );
        }

        return implode(' ', $parts);
    }

    /** The arithmetic, in the order it actually happened. */
    private function dtExplainSteps(array $ctx): array
    {
        $steps = [];

        $steps[] = [
            'step' => 'Start',
            'detail' => 'Every carrier starts at 100 with zero risk points.',
            'score' => 100,
        ];

        $steps[] = [
            'step' => 'Risk points',
            'detail' => number_format($ctx['risk_points']).' risk points totalled across the seven rule groups.',
            'score' => $ctx['raw_score'],
        ];

        if ($ctx['cap_applied'] !== null) {
            $steps[] = [
                'step' => 'Cap',
                'detail' => ($ctx['cap_applied']['reason'] ?? 'Score cap applied.')
                    .' Ceiling of '.$ctx['cap_applied']['cap'].'.',
                'score' => $ctx['score_after_caps'],
            ];
        }

        if ($ctx['fail']) {
            $steps[] = [
                'step' => 'Fail override',
                'detail' => 'A Fail-tier rule fired, so the gauge pins to 18 and the pillar cards are suppressed.',
                'score' => 18,
            ];
        }

        $steps[] = [
            'step' => 'Final',
            'detail' => 'Rounded to the gauge value shown on the profile.',
            'score' => $ctx['overall_score'],
        ];

        return $steps;
    }

    /** snake_case input key -> something a human can read on a card. */
    private function dtHumanize(string $key): string
    {
        $words = ucfirst(str_replace('_', ' ', $key));

        return strtr($words, [
            'Bipd' => 'BIPD',
            'Dot ' => 'DOT ',
            'Mcs150' => 'MCS-150',
            'Oos' => 'OOS',
            'Sms ' => 'SMS ',
            'Vin' => 'VIN',
            'Hos ' => 'HOS ',
            'Pct' => '%',
        ]);
    }
}
