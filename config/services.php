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

    // RS256 key the Fleetra assistant tokens are signed with.
    'fleetra' => [
        'jwt_private_key_path' => env('FLEETRA_JWT_PRIVATE_KEY_PATH', storage_path('app/fleetra/jwt-private.pem')),
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
    | Microsoft Teams channel that the security audit trail posts to.
    |
    | This must be a Power Automate "Workflows" webhook — in Teams, channel
    | → ... → Workflows → "Post to a channel when a webhook request is
    | received". The older Office 365 connector webhooks, and the MessageCard
    | payload they accepted, have been retired; what goes out here is an
    | Adaptive Card, which is what Workflows expects.
    |
    | The URL is a credential: anyone holding it can post to the channel. It
    | belongs in .env, never in the repository.
    */
    'teams' => [
        'webhook_url' => env('TEAMS_WEBHOOK_URL'),

        'enabled' => filter_var(env('TEAMS_ALERTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Short on purpose. The alert is queued, but a webhook that hangs
        // should not hold the worker open ahead of the rest of the queue.
        'timeout' => (int) env('TEAMS_WEBHOOK_TIMEOUT', 5),

        /*
        | Which audit events to post. '*' means every event the trail records,
        | including routine sign-ins — deliberate, and a lot of traffic. To
        | quieten it later set TEAMS_ALERT_EVENTS to a comma separated list:
        |
        |   TEAMS_ALERT_EVENTS=login.failed,user.role_changed,user.removed
        |
        | No code change needed. The trail still records everything either
        | way; this only decides what is worth interrupting someone for.
        */
        'events' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TEAMS_ALERT_EVENTS', '*'))
        ))),
    ],
];
