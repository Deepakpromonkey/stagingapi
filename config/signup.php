<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signup verification
    |--------------------------------------------------------------------------
    |
    | Rules for the one-time codes that prove the email address and phone
    | number entered on the signup form before an account exists.
    |
    */

    // How long a code stays usable once sent.
    'otp_lifetime_minutes' => (int) env('SIGNUP_OTP_MINUTES', 10),

    // Wrong guesses allowed before the code is burned and a new one is needed.
    'otp_max_attempts' => (int) env('SIGNUP_OTP_ATTEMPTS', 5),

    /*
    | How long the proof issued on success is good for.
    |
    | Longer than the code itself: someone verifies their email early, then
    | fills in the rest of the form. Expiring the proof underneath them would
    | make a slow but honest signup fail at the last step.
    */
    'token_lifetime_minutes' => (int) env('SIGNUP_TOKEN_MINUTES', 60),

    /*
    | Minimum wait between sends to the same address. Resending is the
    | button a frustrated user leans on, and every press costs an SMS.
    */
    'resend_cooldown_seconds' => (int) env('SIGNUP_OTP_COOLDOWN', 30),

    /*
    | Applied only to a number that arrives with no country code at all.
    |
    | Deliberately not inherited from carrier_connect.default_dial_code: that
    | one is set for the FMCSA feed's own region and is +91 here, which would
    | silently turn a US number typed without a prefix into an Indian one. The
    | signup form sends its country code explicitly, so this is a last resort.
    */
    'default_dial_code' => env('SIGNUP_DIAL_CODE', '+1'),

];
