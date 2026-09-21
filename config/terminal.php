<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terminal (withterminal.com)
    |--------------------------------------------------------------------------
    |
    | One API in front of the ELD/telematics providers a carrier might already
    | be running — Samsara, Motive, Geotab and the rest. The carrier links
    | their own account through Terminal's hosted "Link" page; we never see
    | their provider credentials, only a connection token afterwards.
    |
    | Two keys, and they are not interchangeable:
    |   publishable (pk_)  goes in the Link URL the carrier's browser opens
    |   secret      (sk_)  never leaves the server; it signs every API call
    |
    */

    'enabled' => (bool) env('TERMINAL_ENABLED', false),

    'publishable_key' => env('TERMINAL_PUBLISHABLE_KEY'),
    'secret_key' => env('TERMINAL_SECRET_KEY'),

    /*
    | Sandbox and production are separate accounts with separate keys, so the
    | environment is chosen explicitly rather than inferred from APP_ENV — a
    | staging box pointed at production telematics data would be a real
    | problem, and inference is how that happens.
    */
    'environment' => env('TERMINAL_ENVIRONMENT', 'sandbox'),

    'base_urls' => [
        'sandbox' => 'https://api.sandbox.withterminal.com/tsp/v1',
        'production' => 'https://api.withterminal.com/tsp/v1',
    ],

    'link_urls' => [
        'sandbox' => 'https://link.sandbox.withterminal.com',
        'production' => 'https://link.withterminal.com',
    ],

    /*
    | Webhooks are delivered through Svix, so the signature scheme is Svix's:
    | svix-id / svix-timestamp / svix-signature, HMAC-SHA256 over
    | "{id}.{timestamp}.{body}" keyed by the base64 part of this secret.
    | Copied from the Terminal dashboard when the endpoint is registered.
    */
    'webhook_secret' => env('TERMINAL_WEBHOOK_SECRET'),

    // How much history to ask for on a first connection.
    'backfill_days' => (int) env('TERMINAL_BACKFILL_DAYS', 30),

    // Page size for the cursor-paginated list endpoints.
    'page_size' => (int) env('TERMINAL_PAGE_SIZE', 100),

    /*
    | A stalled sync must not hold a request open. The carrier is sitting on a
    | redirect when the exchange happens, and the broker dashboard reads stored
    | rows rather than calling Terminal live.
    */
    'timeout' => (int) env('TERMINAL_TIMEOUT', 20),

    /*
    | Its own queue rather than `default`. A first sync backfills a month of
    | HOS logs for a whole fleet, and a broker waiting on an invitation email
    | should not sit behind that. Listed last in the scheduler's worker for the
    | same reason `vin` is — see routes/console.php.
    */
    'queue' => env('TERMINAL_QUEUE', 'eld'),

    // How stale a connection's data may get before the scheduler resyncs it.
    'resync_after_minutes' => (int) env('TERMINAL_RESYNC_MINUTES', 360),

];
