<?php

/*
|--------------------------------------------------------------------------
| Broker Portal RBAC Definition
|--------------------------------------------------------------------------
|
| Two independent concepts live here:
|
| 1. Role  — the seat type a user holds (what screens / actions they get).
| 2. Override capability — a risk decision layered on top of senior roles,
|    granted through `can_override_soft` / `can_override_gate` so it can be
|    assigned independently of the seat type (role gives the default, the
|    user row can override it either way).
|
| This file is the single source of truth. RolePermissionSeeder syncs the
| database from it, and RoleService reads the assignment rules from it.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | Every permission in the broker permission matrix, grouped for the UI.
    | "*-request" permissions mean "can flag / request the exception", the
    | actual approval is always made by a Compliance Manager or above.
    |
    */

    'permissions' => [

        'carriers' => [
            'view-carrier-directory' => 'View carrier directory & profiles',
            'view-trust-score' => 'View Trust Score + pillar breakdown',
            'view-carrier-sensitive-data-partial' => 'View limited carrier data needed to book (legal name, MC/DOT, contact)',
            'view-carrier-sensitive-data-full' => 'View carrier sensitive data (EIN/SSN/bank/W-9)',
            'rate-carriers-notes' => 'Rate carriers / add notes',
            'send-invitation-approved-carriers' => 'Send invitation to approved carriers',
            'send-invitation-flagged-carriers-request' => 'Request invitation to flagged carriers',
            'send-invitation-flagged-carriers' => 'Send invitation to flagged carriers',
        ],

        'loads' => [
            'view-loads-tracking' => 'View loads & ELD tracking',
            'book-assign-loads' => 'Book / assign loads',
        ],

        'payments' => [
            'create-fund-payment' => 'DT Pay — create & fund payment',
            'release-conditional-payment-capped' => 'DT Pay — release conditional payment (up to limit)',
            'release-conditional-payment-unlimited' => 'DT Pay — release conditional payment (no limit)',
            'refund-cancel-payment' => 'DT Pay — refund / cancel',
        ],

        'risk' => [
            'clear-manual-review-flag-request' => 'Request clearing of a manual-review flag',
            'clear-manual-review-flag' => 'Clear manual-review flag',
            'lift-data-confidence-cap-request' => 'Request lift of data-confidence cap',
            'lift-data-confidence-cap-tier1' => 'Lift data-confidence cap (1 tier)',
            'lift-data-confidence-cap-full' => 'Lift data-confidence cap (full)',
            'override-soft-gate-request' => 'Request soft-gate override / below-band onboarding',
            'override-soft-gate' => 'Override soft gate / approve below-band',
            'override-knockout-gate-dual-control' => 'Override knockout gate (overridable set, dual-control)',
        ],

        'administration' => [
            'edit-carrier-agreements' => 'Edit carrier agreements / questions / email templates',
            'manage-users-basic' => 'Add & edit users (Viewer / Agent / Senior Agent)',
            'manage-users-all' => 'Add & edit users and assign any role',
            'edit-scoring-config' => 'Edit scoring config (weights / thresholds)',
            'edit-company-profile-billing' => 'Edit company profile / billing',
            'manage-api-keys-integration' => 'API keys & integration config (ELD, Stripe, FMCSA)',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | `level` drives seniority comparisons, `can_override_soft` /
    | `can_override_gate` are the default risk authority for the seat, and
    | `payment_release_limit` is the per-payment conditional release cap
    | (null = no cap; the release permission decides if they can at all).
    |
    */

    'roles' => [

        'viewer' => [
            'name' => 'Viewer',
            'level' => 10,
            'description' => 'Read-only visibility into carriers, scores and loads. No PII, no actions.',
            'risk_authority' => 'none',
            'can_override_soft' => false,
            'can_override_gate' => false,
            'payment_release_limit' => null,
            'permissions' => [
                'view-carrier-directory',
                'view-trust-score',
                'view-loads-tracking',
            ],
        ],

        'agent' => [
            'name' => 'Agent',
            'level' => 20,
            'description' => 'Day-to-day operator: invite approved carriers, book loads, initiate payments.',
            'risk_authority' => 'none',
            'can_override_soft' => false,
            'can_override_gate' => false,
            'payment_release_limit' => null,
            'permissions' => [
                'view-carrier-directory',
                'view-trust-score',
                'view-carrier-sensitive-data-partial',
                'rate-carriers-notes',
                'send-invitation-approved-carriers',
                'view-loads-tracking',
                'book-assign-loads',
                'create-fund-payment',
            ],
        ],

        'senior_agent' => [
            'name' => 'Senior Agent',
            'level' => 30,
            'description' => 'Agent + full sensitive data + capped payment release + can request risk exceptions.',
            'risk_authority' => 'request',
            'can_override_soft' => false,
            'can_override_gate' => false,
            'payment_release_limit' => 5000.00,
            'permissions' => [
                'view-carrier-directory',
                'view-trust-score',
                'view-carrier-sensitive-data-partial',
                'view-carrier-sensitive-data-full',
                'rate-carriers-notes',
                'send-invitation-approved-carriers',
                'send-invitation-flagged-carriers-request',
                'view-loads-tracking',
                'book-assign-loads',
                'create-fund-payment',
                'release-conditional-payment-capped',
                'clear-manual-review-flag-request',
                'lift-data-confidence-cap-request',
                'override-soft-gate-request',
            ],
        ],

        'compliance_manager' => [
            'name' => 'Compliance Manager',
            'level' => 40,
            'description' => 'Approves & clears scoring exceptions: flags, confidence caps, soft gates, below-band onboarding. Manages agreements, templates and users.',
            'risk_authority' => 'override_soft',
            'can_override_soft' => true,
            'can_override_gate' => false,
            'payment_release_limit' => null,
            'permissions' => [
                'view-carrier-directory',
                'view-trust-score',
                'view-carrier-sensitive-data-partial',
                'view-carrier-sensitive-data-full',
                'rate-carriers-notes',
                'send-invitation-approved-carriers',
                'send-invitation-flagged-carriers',
                'view-loads-tracking',
                'book-assign-loads',
                'create-fund-payment',
                'release-conditional-payment-unlimited',
                'refund-cancel-payment',
                'clear-manual-review-flag',
                'lift-data-confidence-cap-tier1',
                'override-soft-gate',
                'edit-carrier-agreements',
                'manage-users-basic',
            ],
        ],

        'owner_admin' => [
            'name' => 'Owner/Admin',
            'level' => 50,
            'description' => 'Everything: scoring config, billing, role management and hard-gate overrides (dual-control).',
            'risk_authority' => 'override_soft_and_gate',
            'can_override_soft' => true,
            'can_override_gate' => true,
            'payment_release_limit' => null,
            'permissions' => [
                'view-carrier-directory',
                'view-trust-score',
                'view-carrier-sensitive-data-partial',
                'view-carrier-sensitive-data-full',
                'rate-carriers-notes',
                'send-invitation-approved-carriers',
                'send-invitation-flagged-carriers',
                'view-loads-tracking',
                'book-assign-loads',
                'create-fund-payment',
                'release-conditional-payment-unlimited',
                'refund-cancel-payment',
                'clear-manual-review-flag',
                'lift-data-confidence-cap-full',
                'override-soft-gate',
                'override-knockout-gate-dual-control',
                'edit-carrier-agreements',
                'manage-users-all',
                'edit-scoring-config',
                'edit-company-profile-billing',
                'manage-api-keys-integration',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Assignment Rules
    |--------------------------------------------------------------------------
    |
    | Which role slugs a holder of a given role may assign when inviting or
    | editing a user. A Compliance Manager can manage Viewer / Agent /
    | Senior Agent only; creating another Owner/Admin or Compliance Manager
    | is reserved for an Owner/Admin.
    |
    */

    'assignable' => [
        'owner_admin' => ['viewer', 'agent', 'senior_agent', 'compliance_manager', 'owner_admin'],
        'compliance_manager' => ['viewer', 'agent', 'senior_agent'],
        'senior_agent' => [],
        'agent' => [],
        'viewer' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    // Role given to the user who signs up and creates the company.
    'owner_role' => 'owner_admin',

    // Legacy role names replaced by the matrix above (old name => new slug).
    'legacy_role_map' => [
        'Company Admin' => 'owner_admin',
        'Manager' => 'compliance_manager',
        'Dispatcher' => 'agent',
        'Sales' => 'agent',
        'Accounting' => 'senior_agent',
    ],

];
