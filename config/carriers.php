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

];
