<?php

/*
|--------------------------------------------------------------------------
| DT Trust Score — every scoring parameter, in one place
|--------------------------------------------------------------------------
| The engine (App\Http\Controllers\Carrier\Concerns\DtTrustScoreV3) holds
| the rule LOGIC; every number that decides a score lives here.
|
| After changing a value on a server that caches config, run
| `php artisan config:cache`. Cached scores are keyed by a fingerprint of
| this file, so every carrier is re-scored with the new numbers on its next
| request — nothing else to clear.
|
| To score a carrier from anywhere:
|
|     DtScore::for(dot: '1234567');            // full result array
|     DtScore::for(mc: 'MC012345');            // by MC / docket number
|     DtScore::value(dot: '1234567');          // just the 0-100 number
|
| How a score is built:
|   1. Each rule that fires adds the points of its tier (tier_points).
|   2. Total points map onto 0-100 through the gauge.
|   3. Caps (young authority, thin data, …) set a ceiling on that number.
|   4. Any Fail-tier rule overrides everything: score = fail_score.
*/

return [

    'model_version' => 'dt-trust-v3.5-motus',

    /*
    |--------------------------------------------------------------------------
    | Tier points — what a fired rule costs
    |--------------------------------------------------------------------------
    | Every rule below names the tier it lands on. A 'fail' tier also forces
    | the Unacceptable-Fail status, whatever the points total.
    */

    'tier_points' => [
        'low' => 125,
        'medium' => 250,
        'review' => 1000,
        'fail' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Points -> 0-100 gauge
    |--------------------------------------------------------------------------
    | Piecewise straight lines: [from points, to points, score at from,
    | score at to]. The last segment whose "from" the total reaches is used,
    | and the score never drops below that segment's end value.
    */

    'gauge' => [
        [0, 1000, 100, 55],
        [1000, 10000, 54, 19],
        [10000, 30000, 18, 0],
    ],

    // Total risk points at which the status becomes Unacceptable-Review.
    'review_at_points' => 1000,

    // The score shown when any Fail-tier rule fires.
    'fail_score' => 18,

    /*
    |--------------------------------------------------------------------------
    | Bands, grades and the legacy status pill
    |--------------------------------------------------------------------------
    | Minimum score for each label. Checked top to bottom.
    */

    'bands' => [
        'preferred' => 85,
        'acceptable' => 70,
        // anything lower that is still Acceptable status => 'conditional'
    ],

    'grades' => [
        'A' => 90,
        'B' => 80,
        'C' => 70,
        'D' => 60,
        // anything lower => 'F'
    ],

    'legacy_status' => [
        'Approved' => 80,
        'Review' => 60,
        // anything lower => 'High Risk'
    ],

    // Below this data confidence the score is flagged for manual review.
    'manual_review_below_confidence' => 0.65,

    /*
    |--------------------------------------------------------------------------
    | Data confidence caps
    |--------------------------------------------------------------------------
    | Confidence = share of the eight core inputs that are present. Below
    | each confidence level the score is capped at the given ceiling.
    */

    'confidence' => [
        'caps' => [
            // [confidence below, score cap]
            [0.40, 55.0],
            [0.65, 70.0],
            [0.85, 85.0],
        ],
        // Inspections needed before "inspection history" counts as present.
        'min_inspections' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pillar cards (presentation only)
    |--------------------------------------------------------------------------
    | The seven cards on the profile page. They never change the overall
    | score. A group loses its whole weight at 'zero_at_points'.
    */

    'pillars' => [
        'weights' => [
            'safety_roadside' => 24,
            'identity_fraud' => 20,
            'insurance_financial' => 18,
            'authority_compliance' => 12,
            'crash_history' => 10,
            'inspection_quality' => 8,
            'operations_experience' => 8,
        ],
        'zero_at_points' => 1000,
        // Minimum share of the weight for each card status.
        'status' => [
            'Excellent' => 0.90,
            'Good' => 0.72,
            'Average' => 0.50,
            'Poor' => 0.25,
            // anything lower => 'Critical'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    | 'tier'  — the tier a single-outcome rule fires at.
    | 'tiers' — tier => minimum value, for rules that escalate. The highest
    |           threshold the value reaches wins.
    | 'enabled' => false switches a rule off entirely.
    */

    'rules' => [

        // ── Authority & Compliance ──────────────────────────────────────

        // Score ceiling when the authority record is missing or unreadable.
        'authority_unknown_cap' => 84,

        'AUTH-01' => ['tier' => 'fail'],      // common + contract authority inactive
        'AUTH-02' => ['tier' => 'fail'],      // DOT missing / inactive
        'AUTH-03' => ['tier' => 'fail'],      // active out-of-service order
        'AUTH-06' => ['tier' => 'review'],    // revocation pending
        'AUTH-07' => ['tier' => 'low'],       // application pending
        'AUTH-08' => ['tier' => 'review'],    // reinstated after revocation

        // Completed revocations on record.
        'AUTH-09' => ['tiers' => ['review' => 3, 'medium' => 1]],

        // Suspension orders on record.
        'AUTH-10' => ['tiers' => ['medium' => 1]],

        // Involuntary revocation proceedings that did not complete.
        'AUTH-11' => [
            'window_months' => 36,
            'tiers' => ['review' => 4, 'medium' => 2, 'low' => 1],
        ],

        // Active broker authority alongside carrier authority.
        'AUTH-12' => [
            'tier' => 'medium',
            'escalated_tier' => 'review',
            // Escalate when nothing was ever seen at roadside, or …
            'escalate_if_authority_days_under' => 180,
            'escalate_if_power_units_at_most' => 2,
        ],

        // Authority age ladder, youngest first. 'rule' fires at 'tier';
        // 'cap' is the score ceiling while the authority is that young.
        // 'flag' is the review flag the profile shows.
        'authority_age' => [
            ['under_days' => 30, 'rule' => 'OPS-01', 'tier' => 'review', 'cap' => 45, 'flag' => 'senior_approval_required'],
            ['under_days' => 90, 'rule' => 'OPS-04', 'tier' => 'medium', 'cap' => 65, 'flag' => 'documented_review_required'],
            ['under_days' => 180, 'rule' => null, 'tier' => null, 'cap' => 75, 'flag' => null],
            ['under_days' => 365, 'rule' => null, 'tier' => null, 'cap' => 84, 'flag' => null],
        ],

        // Authority age unknown and the DOT is under a year old.
        'authority_age_unknown_new_dot' => [
            'rule' => 'OPS-04', 'tier' => 'medium', 'cap' => 65, 'flag' => 'documented_review_required',
        ],

        // ── Insurance & Financial ───────────────────────────────────────

        'INS-01' => ['tier' => 'fail'],       // no BIPD on file
        'INS-02' => [                         // BIPD below the required minimum
            'tier' => 'fail',
            'default_required_dollars' => 750000,
        ],
        'INS-03' => ['tier' => 'fail'],       // cargo required, none on file
        'INS-04' => ['tier' => 'low'],        // broker bond / trust missing
        'INS-10' => ['tier' => 'review'],     // cancellation pending
        'INS-11' => ['tiers' => ['medium' => 1]],              // rejected filings
        'INS-12' => ['tiers' => ['medium' => 8, 'low' => 5]],  // distinct insurers

        // ── Safety & Roadside ───────────────────────────────────────────

        'SAF-01' => ['tier' => 'fail'],       // Unsatisfactory rating
        'SAF-02' => ['tier' => 'fail'],       // Conditional rating

        // BASIC measure at/above the national cut-point for its threshold
        // percentile. 'fallback_cut' is the percentile used when the
        // benchmark table has no cut at the threshold itself.
        'sms' => [
            'min_inspections' => 5,
            'basics' => [
                'unsafe_driv' => ['threshold' => 65, 'fallback_cut' => 75],
                'hos_driv' => ['threshold' => 65, 'fallback_cut' => 75],
                'driv_fit' => ['threshold' => 80, 'fallback_cut' => 90],
                'contr_subst' => ['threshold' => 65, 'fallback_cut' => 75],
                'veh_maint' => ['threshold' => 80, 'fallback_cut' => 90],
            ],
            'tier' => 'medium',               // one BASIC over (SMS-*)
        ],
        'SMS-MULTI' => ['tier' => 'review', 'min_basics' => 2],
        'SAF-AC' => ['tier' => 'medium'],     // acute/critical indicator

        // Out-of-service rate as a multiple of the national average.
        'SAF-10' => [                         // vehicle
            'min_inspections' => 5,
            'national_fallback_pct' => 20.0,
            'tiers' => ['medium' => 2, 'low' => 1],
        ],
        'SAF-11' => [                         // driver
            'min_inspections' => 5,
            'national_fallback_pct' => 5.0,
            'tiers' => ['medium' => 2, 'low' => 1],
        ],

        // ── Crash History ───────────────────────────────────────────────

        'crash_window_months' => 24,

        // Fatal crash in the window. Small fleets and high fatal rates take
        // the full tier; a large fleet at baseline rate takes the lower one.
        'CR-01' => [
            'tier' => 'review',
            'large_fleet_tier' => 'medium',
            'large_fleet_min_units' => 100,
            'high_fatal_rate_per_unit' => 0.005,
        ],

        // Crashes per power unit per year.
        'CR-02' => ['min_crashes' => 2, 'tiers' => ['medium' => 0.5, 'low' => 0.25]],

        // Crash count when fleet size is unreported.
        'CR-03' => ['tiers' => ['medium' => 10, 'low' => 5]],

        // Tow-away crashes in the window.
        'CR-04' => ['tiers' => ['low' => 5]],

        // ── Inspection Quality ──────────────────────────────────────────

        // Share of inspections with a violation.
        'INSP-01' => ['min_inspections' => 5, 'tiers' => ['medium' => 0.75, 'low' => 0.50]],

        // Established authority with too few inspections.
        'INSP-02' => ['tier' => 'low', 'established_after_days' => 365, 'min_inspections' => 5],

        // No inspection in this many days.
        'INSP-03' => ['tier' => 'low', 'stale_after_days' => 365],

        // ── Identity & Fraud ────────────────────────────────────────────

        'PRT-21' => ['tier' => 'fail'],                          // internally blocked
        'PRT-20' => ['tier' => 'fail', 'min_reports' => 2],     // fraud reports

        // Other DOTs sharing a phone / email / address / roadside VIN.
        'network' => [
            'enabled' => env('TRUSTSCORE_NETWORK_CHECKS', true),
            'cache_seconds' => 21600,
            // A fleet this big and this old takes 'large_fleet_tier' instead
            // of 'review' — a corporate family, not a chameleon.
            'large_fleet_min_units' => 50,
            'large_fleet_min_days' => 1825,
            'large_fleet_tier' => 'medium',
        ],
        'NET-01' => ['tiers' => ['review' => 3, 'low' => 2]],                  // phone
        'NET-02' => ['tiers' => ['review' => 3, 'low' => 2]],                  // email
        'NET-03' => ['tiers' => ['review' => 3, 'low' => 2]],                  // address
        'NET-04' => ['tiers' => ['review' => 5, 'medium' => 3, 'low' => 2]],   // VINs

        'ID-01' => [                          // mail-drop address
            'tier' => 'low',
            'patterns' => [
                'UPS STORE', 'REGUS', 'WEWORK', 'PMB ', 'POSTAL ANNEX',
                'MAIL BOXES ETC', 'MAILBOX', 'REGISTERED AGENT', 'VIRTUAL OFFICE', 'SUITE #',
            ],
        ],
        'ID-03' => [                          // free-provider email
            'tier' => 'low',
            'domains' => [
                'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com',
                'aol.com', 'live.com', 'msn.com', 'protonmail.com',
            ],
        ],

        // ── Operations & Experience ─────────────────────────────────────

        'OPS-10' => ['tier' => 'low', 'max_age_years' => 2],              // MCS-150 out of date
        'OPS-11' => ['tier' => 'low', 'min_inspections' => 5],            // ghost fleet
        'OPS-12' => [                                                     // false federal filing
            'tier' => 'medium',
            'codes' => ['390.19', '390.35'],
        ],
        'OPS-13' => ['tier' => 'low', 'min_observed_units' => 2],         // under-reported fleet

    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    | How long a computed score is reused before it is recalculated. The
    | search page, shortlist and profile share one cached value per DOT.
    */

    'cache_hours' => 6,

];
