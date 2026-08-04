<?php

/*
|--------------------------------------------------------------------------
| Carrier Portal RBAC Definition
|--------------------------------------------------------------------------
|
| The carrier side of the product has its own seats, entirely separate from
| the broker matrix in config/rbac.php. Nothing here is assignable to a
| broker user and nothing there is assignable to a carrier: the two live in
| different Spatie guards ('carrier' vs 'web'), so even a name collision
| could not cross over.
|
| The shape of the matrix is deliberate — every sensitive write (money
| movement, legal identity, KYC, signing, user management) sits in exactly
| one role, so day-to-day work can be delegated to staff without handing
| over payout control.
|
| CarrierRolePermissionSeeder syncs the database from this file, and
| CarrierRoleService reads the assignment rules from it.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    |
    | Spatie guard every carrier role and permission is created under.
    */

    'guard' => 'carrier',

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    'permissions' => [

        'profile' => [
            'view-own-carrier-profile' => 'View own carrier profile & Trust Score',
            'edit-carrier-contact-info' => 'Edit contact / dispatch info',
        ],

        'loads' => [
            'view-assigned-loads' => 'View assigned loads & tracking',
            'manage-eld-consent' => 'Give / manage ELD tracking consent',
            'manage-assigned-loads' => 'Manage loads (accept, update status)',
        ],

        'documents' => [
            'upload-onboarding-documents' => 'Upload onboarding docs (COI, inspections)',
        ],

        'payments' => [
            'view-own-payments' => 'View payment status & history for own loads',
            'view-carrier-payments' => 'View payment status & history for the whole carrier',
        ],

        // Money movement, legal identity, KYC and signing. This entire group
        // belongs to the Carrier Owner/Admin seat and nothing else.
        'sensitive' => [
            'manage-carrier-payout-banking' => 'Set up / edit payout & banking (DT Pay)',
            'edit-carrier-legal-identity' => 'Edit legal identity / W-9 / tax info',
            'complete-carrier-kyc' => 'Complete KYC (Didit liveness)',
            'sign-carrier-agreements' => 'Sign carrier agreements',
            'manage-carrier-users' => 'Add & edit carrier users',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | `level` drives seniority comparisons. The broker-only columns on the
    | shared roles table (risk overrides, payment release cap) are meaningless
    | here and stay at their defaults.
    |
    */

    'roles' => [

        'carrier_driver' => [
            'name' => 'Driver / Viewer',
            'level' => 10,
            'description' => 'Sees own status, assigned loads and tracking consent. No sensitive writes.',
            'permissions' => [
                'view-own-carrier-profile',
                'view-assigned-loads',
                'view-own-payments',
            ],
        ],

        'carrier_staff' => [
            'name' => 'Carrier Staff',
            'level' => 20,
            'description' => 'Completes onboarding tasks, uploads documents, manages loads and views payments. No sensitive writes.',
            'permissions' => [
                'view-own-carrier-profile',
                'view-assigned-loads',
                'view-own-payments',
                'manage-eld-consent',
                'upload-onboarding-documents',
                'manage-assigned-loads',
                'view-carrier-payments',
                'edit-carrier-contact-info',
            ],
        ],

        'carrier_owner' => [
            'name' => 'Carrier Owner / Admin',
            'level' => 30,
            'description' => 'Everything staff can do, plus banking & payout, W-9 / legal identity, KYC, signing agreements and managing carrier users.',
            'permissions' => [
                'view-own-carrier-profile',
                'view-assigned-loads',
                'view-own-payments',
                'manage-eld-consent',
                'upload-onboarding-documents',
                'manage-assigned-loads',
                'view-carrier-payments',
                'edit-carrier-contact-info',
                'manage-carrier-payout-banking',
                'edit-carrier-legal-identity',
                'complete-carrier-kyc',
                'sign-carrier-agreements',
                'manage-carrier-users',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Assignment Rules
    |--------------------------------------------------------------------------
    |
    | Which seats a holder of a given seat may hand out. Only the owner can
    | seat anyone, including another owner — user management is a sensitive
    | write and is not delegable.
    |
    */

    'assignable' => [
        'carrier_owner' => ['carrier_driver', 'carrier_staff', 'carrier_owner'],
        'carrier_staff' => [],
        'carrier_driver' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    // Seat given to the carrier account created at the end of onboarding —
    // the first person in is the one who signs and gets paid.
    'owner_role' => 'carrier_owner',

    // Seat an invited user gets when none is named.
    'default_role' => 'carrier_staff',

    // Days an invited carrier user has before their invitation record lapses.
    'invitation_lifetime_days' => 7,

];
