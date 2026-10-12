<?php

/*
| DT Pay: brokers pay carriers through DollarTraq.
|
| Escrow model. The broker is charged; the money sits on DollarTraq's Stripe
| balance until the broker releases it, then it is transferred to the
| carrier's connected (Express) account. The carrier always receives the load
| amount in full: the platform fee and any card surcharge are on top.
*/
return [

    /*
    | DT Pay has its own Stripe keys, separate from subscriptions
    | (services.stripe), so staging can run it on test keys while the rest of
    | the platform is live.
    */
    'stripe' => [
        'secret' => env('DTPAY_STRIPE_SECRET'),
        'key' => env('DTPAY_STRIPE_KEY'),
        'webhook_secret' => env('DTPAY_STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    | A live secret key moves real money. DT Pay refuses one unless this is
    | set explicitly - so a copied .env can never turn staging into a live
    | payment system by accident.
    */
    'allow_live' => filter_var(env('DTPAY_ALLOW_LIVE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Shared secret the DollarTraq admin backend sends to the admin endpoints
    | (refund approvals, escrow ledger). Empty switches those endpoints off.
    */
    'admin_token' => env('DTPAY_ADMIN_TOKEN'),

    'currency' => 'usd',

    'fees' => [
        // Charged to the broker on top of the load amount.
        'platform_pct' => (float) env('DTPAY_PLATFORM_FEE_PCT', 1.2),

        // Guest Pay (no DollarTraq account): pay-per-use rate.
        'guest_platform_pct' => (float) env('DTPAY_GUEST_FEE_PCT', 2.0),

        // Card surcharge, on top. Bank (ACH) payments carry none.
        'card_pct' => (float) env('DTPAY_CARD_FEE_PCT', 2.9),
    ],

    // Smallest and largest load amount one payment may carry, in dollars.
    'limits' => [
        'min' => 1.00,
        'max' => (float) env('DTPAY_MAX_AMOUNT', 250000),
    ],

    // Where rate confirmations, PODs and dispute evidence are stored.
    'disk' => env('DTPAY_DISK', env('FILESYSTEM_DISK', 'local')),

    // Where carriers land after Stripe's payout onboarding.
    'payout_return_url' => env('DTPAY_PAYOUT_RETURN_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/payouts/complete'),

    // Guest tracking links point here: {base}/guest-pay/track/{token}
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),
];
