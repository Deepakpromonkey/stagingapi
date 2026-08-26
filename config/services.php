<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Identity document verification during carrier onboarding.
    'didit' => [
        'api_key' => env('DIDIT_API_KEY'),
        'workflow_id' => env('DIDIT_WORKFLOW_ID'),
    ],

    // Carrier payout accounts (Stripe Express).
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
     | Reads the policy expiry date out of an insurance agency's reply. See
     | config/coi_insurance.php for the model and the rest of that flow.
     */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // SMS gateway for onboarding OTPs.
    'telnyx' => [
        'key' => env('TELNYX_API_KEY'),

        /*
        | The sending address: a number on the messaging profile, a short code,
        | or an alphanumeric sender ID. Alphanumeric senders are one-way and are
        | rejected outright in the US and Canada, so leave this as a number
        | unless every recipient is somewhere that allows one.
        |
        | It may be left unset if a messaging profile with a number pool is
        | configured below — Telnyx then picks the sending number itself.
        */
        'from' => env('TELNYX_FROM'),

        'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
    ],

];
