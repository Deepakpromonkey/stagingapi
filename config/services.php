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

        /*
        | Test redirect. When set, every SMS goes to this number instead of the
        | real recipient, with the intended recipient named in the message.
        |
        | For staging, where carrier records carry real phone numbers a tester
        | cannot receive. MUST be unset in production — it would divert real
        | carriers' one-time codes. SmsSender logs a warning on every send while
        | it is on, so an environment running with it by mistake says so.
        */
        'override_to' => env('SMS_OVERRIDE_TO'),
    ],

    /*
     | ELD / telematics connections (Terminal).
     |
     | Two keys, two audiences: the publishable key is the one that travels in
     | the Link URL the carrier's browser follows, the secret key never leaves
     | this application and signs every data call. Both are environment-scoped —
     | `pk_sandbox_*`/`sk_sandbox_*` against the sandbox host, `pk_prod_*`/
     | `sk_prod_*` against production.
     |
     | Promoting is TERMINAL_ENVIRONMENT plus the two keys. The hosts follow
     | from the environment rather than being spelled out again, because a
     | production key pointed at the sandbox host — or the reverse — fails in
     | ways that look like a broken integration rather than a wrong setting.
     | See docs/eld-terminal.md.
     */
    'terminal' => [
        /*
        | The kill switch. Off, the ELD step still renders and can still be
        | skipped — it just cannot be started, which is the right behaviour
        | while provider credentials are pending rather than blocking every
        | carrier's onboarding on it.
        */
        'enabled' => filter_var(env('TERMINAL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        'environment' => env('TERMINAL_ENVIRONMENT', 'sandbox'),

        'secret_key' => env('TERMINAL_SECRET_KEY'),
        'publishable_key' => env('TERMINAL_PUBLISHABLE_KEY'),

        // Overridable, but derived from the environment by default so the two
        // cannot silently disagree.
        'base_url' => env('TERMINAL_BASE_URL', env('TERMINAL_ENVIRONMENT', 'sandbox') === 'production'
            ? 'https://api.withterminal.com/tsp/v1'
            : 'https://api.sandbox.withterminal.com/tsp/v1'),

        'link_url' => env('TERMINAL_LINK_URL', env('TERMINAL_ENVIRONMENT', 'sandbox') === 'production'
            ? 'https://link.withterminal.com'
            : 'https://link.sandbox.withterminal.com'),

        /*
        | Svix signing secret (`whsec_...`) from the webhook endpoint's page in
        | the Terminal portal, and set per environment — a sandbox secret does
        | not verify production deliveries. Without it the webhook endpoint
        | refuses every delivery rather than trusting an unverified payload.
        */
        'webhook_secret' => env('TERMINAL_WEBHOOK_SECRET'),

        /*
        | Days of history to pull on a new connection. Terminal meters synced
        | data, not entities, so this is the single biggest lever on the bill —
        | 0 means "from now on", which is the right default until the tracking
        | features that need history actually exist.
        */
        'backfill_days' => (int) env('TERMINAL_BACKFILL_DAYS', 0),

        /*
        | Hours of overlap re-read on every incremental pass. Entity and HOS
        | syncs use ingestion time (`modifiedAfter`), which needs no lookback;
        | locations can only be queried by record time, where a late-arriving
        | ping would otherwise fall in the gap between two runs.
        */
        'lookback_hours' => (int) env('TERMINAL_LOOKBACK_HOURS', 48),
    ],

];
