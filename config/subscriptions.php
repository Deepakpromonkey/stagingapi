<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business types
    |--------------------------------------------------------------------------
    |
    | Asked on signup. The key is stored on the company; the label is what the
    | signup form renders. Anything listed in `dot_number_types` is prompted
    | for a USDOT number as well — the number itself always stays optional,
    | because a new brokerage frequently signs up before its authority is
    | granted.
    |
    */

    'business_types' => [
        // What the signup form actually offers.
        '3pl_freight_broker' => '3PL / Freight Broker',
        'freight_forwarder_shipper' => 'Freight Forwarder / Shipper',
        'technology_vendor' => 'Technology Vendor',
        'insurance_agency' => 'Insurance Agency',
        'other' => 'Other',

        // The original, finer-grained set. No longer offered at signup, but
        // kept accepted so companies already carrying one of these keys still
        // pass validation when their profile is updated.
        'broker' => 'Freight broker',
        'carrier' => 'Motor carrier',
        'broker_carrier' => 'Broker & carrier',
        'freight_forwarder' => 'Freight forwarder',
        'third_party_logistics' => 'Third party logistics (3PL)',
        'shipper' => 'Shipper',
    ],

    'dot_number_types' => [
        '3pl_freight_broker',
        'broker',
        'carrier',
        'broker_carrier',
        'freight_forwarder',
        'third_party_logistics',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Monthly Stripe subscriptions, offered straight after signup. `amount` is
    | in whole dollars and drives the pricing table only — Stripe is the
    | authority on what is actually charged, so the price IDs must point at
    | recurring monthly prices that match.
    |
    | `load_limit` is how many loads the plan allows per billing period. Null
    | means unlimited, which is what Enterprise gets.
    |
    | `trial_days` is free days granted on the plan's first checkout. Only
    | Standard carries a trial; 0 means the card is charged straight away.
    |
    | Enterprise has no price ID: it routes to sales instead of Checkout.
    |
    */

    'currency' => 'usd',

    'interval' => 'month',

    'plans' => [

        'standard' => [
            'name' => 'Standard',
            'description' => 'For brokerages getting their lanes and carriers onto one system.',
            'amount' => 249,
            'price_id' => env('STRIPE_PRICE_STANDARD'),
            'contact_sales' => false,
            'load_limit' => 100,
            'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS_STANDARD', 14),
            'features' => [
                '14 day free trial',
                '100 loads per month',
                'Up to 10 users',
                'Carrier vetting and DT Score',
                'Load tracking and carrier portal',
                'Email support',
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'description' => 'For growing brokerages that need deeper controls and reporting.',
            'amount' => 499,
            'price_id' => env('STRIPE_PRICE_PRO'),
            'contact_sales' => false,
            'load_limit' => 250,

            // No trial on Pro — it is billed from day one.
            'trial_days' => 0,

            'features' => [
                '250 loads per month',
                'Unlimited users',
                'Everything in Standard',
                'Custom scoring weights and hard gates',
                'Advanced carrier reporting',
                'Priority support',
            ],
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Custom pricing for high volume operations.',
            'amount' => null,
            'price_id' => null,
            'contact_sales' => true,
            'load_limit' => null,
            'trial_days' => 0,
            'features' => [
                'Unlimited loads',
                'Everything in Pro',
                'Custom integrations and SSO',
                'Dedicated onboarding',
                'Named account manager',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Load quota
    |--------------------------------------------------------------------------
    |
    | The per-plan `load_limit` above is counted over the Stripe billing period
    | and enforced when a load is created.
    |
    | A company with no subscription at all is left unmetered by default —
    | every account that existed before billing was introduced is in that
    | state, and blocking them would take the product away from them without
    | warning. Turn this on once those accounts have been migrated onto a plan.
    |
    */

    'require_subscription_for_loads' => (bool) env('SUBSCRIPTION_REQUIRED_FOR_LOADS', false),

    /*
    |--------------------------------------------------------------------------
    | Where Stripe sends the customer back
    |--------------------------------------------------------------------------
    |
    | Appended to config('app.frontend_url').
    |
    */

    'success_path' => env('SUBSCRIPTION_SUCCESS_PATH', '/billing/success'),
    'cancel_path' => env('SUBSCRIPTION_CANCEL_PATH', '/billing/plans'),
    'portal_return_path' => env('SUBSCRIPTION_PORTAL_RETURN_PATH', '/billing'),

    /*
    |--------------------------------------------------------------------------
    | Enterprise enquiries
    |--------------------------------------------------------------------------
    */

    'sales_email' => env('SUBSCRIPTION_SALES_EMAIL', 'sales@dollartraq.com'),

];
