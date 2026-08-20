<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Carrier onboarding
    |--------------------------------------------------------------------------
    |
    | Settings for the "Connect" flow a broker starts from a carrier profile:
    | invitation mail, phone OTP, identity verification and payout setup.
    |
    */

    // How long an invitation link stays usable.
    'request_lifetime_hours' => (int) env('CARRIER_CONNECT_LIFETIME_HOURS', 72),

    // Phone OTP rules.
    'otp_lifetime_minutes' => (int) env('CARRIER_CONNECT_OTP_MINUTES', 15),
    'otp_max_attempts' => (int) env('CARRIER_CONNECT_OTP_ATTEMPTS', 3),

    /*
    | Development overrides. Carrier contact details come from the FMCSA feed,
    | which is real data — these keep local testing from mailing or texting an
    | actual trucking company. Leave both empty in production.
    */
    'test_email' => env('CARRIER_CONNECT_TEST_EMAIL'),
    'test_phone' => env('CARRIER_CONNECT_TEST_PHONE'),

    // Default country code applied to 10 digit carrier phone numbers.
    'default_dial_code' => env('CARRIER_CONNECT_DIAL_CODE', '+91'),

    /*
    | Where a carrier signs in once onboarding is done. The API provisions the
    | account and mails this link alongside the credentials; the portal front
    | end is a separate application. Falls back to a /carrier-portal path on the
    | broker front end so the link in the email is never empty.
    */
    'portal_url' => env('CARRIER_PORTAL_URL', 'https://carrier.dollartraq.com'),

];
