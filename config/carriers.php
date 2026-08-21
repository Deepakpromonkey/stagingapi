<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile cache
    |--------------------------------------------------------------------------
    |
    | Carrier records come from the FMCSA census and change at most daily, so
    | the assembled profile is cached. Seconds.
    |
    */

    'profile_cache_ttl' => env('CARRIER_PROFILE_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Child collection size
    |--------------------------------------------------------------------------
    |
    | Rows returned per child collection on the profile (inspections, crashes,
    | crash details, violation details). Totals are always reported in full
    | under `computed`.
    |
    */

    'recent_limit' => env('CARRIER_RECENT_LIMIT', 25),

    /*
    |--------------------------------------------------------------------------
    | FMCSA live lookup
    |--------------------------------------------------------------------------
    |
    | Optional per-request snapshot from FMCSA's public API. Cached separately
    | because the upstream service is slow and frequently unavailable.
    |
    */

    'fmcsa_web_key' => env('FMCSA_WEB_KEY'),

    'fmcsa_timeout' => env('FMCSA_TIMEOUT', 8),

    'fmcsa_cache_ttl' => env('FMCSA_CACHE_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Change log
    |--------------------------------------------------------------------------
    |
    | Every field-level change FMCSA has published for every DOT number, as a
    | newline-delimited JSON export on S3. It is measured in gigabytes, so it
    | is never loaded whole and never copied into a database: the file is
    | grouped by DOT, so `carriers:index-change-log` records the byte range
    | each carrier occupies, and a lookup is a binary search over that index
    | followed by one ranged GET for the handful of kilobytes that belong to
    | the carrier being profiled.
    |
    */

    'change_log' => [

        'disk' => env('CARRIER_CHANGE_LOG_DISK', 's3'),

        'key' => env('CARRIER_CHANGE_LOG_KEY', 'data/all-dots.ndjson'),

        // Set to read ranges over plain HTTP instead of the S3 API — useful if
        // the object is public and the app has no AWS credentials.
        'url' => env('CARRIER_CHANGE_LOG_URL'),

        // Set to read ranges from a local copy of the export instead. Takes
        // precedence over both of the above; mainly for working offline.
        'path' => env('CARRIER_CHANGE_LOG_PATH'),

        // Where `carriers:index-change-log` writes its index, on the local
        // disk. A few megabytes; rebuilt whenever the export is replaced.
        'index_path' => env(
            'CARRIER_CHANGE_LOG_INDEX',
            'carrier-change-log/dot-index.bin'
        ),

        'cache_ttl' => env('CARRIER_CHANGE_LOG_CACHE_TTL', 900),

        // Entries returned on the contact history timeline. The summary counts
        // are always computed over everything the carrier has.
        'timeline_limit' => env('CARRIER_CHANGE_LOG_TIMELINE_LIMIT', 500),

        // Former values per identifier fed into company associations. Each one
        // widens the association query, so this is deliberately small.
        'former_values_limit' => env('CARRIER_CHANGE_LOG_FORMER_LIMIT', 8),

    ],

];
