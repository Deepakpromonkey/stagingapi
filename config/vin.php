<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NHTSA vPIC
    |--------------------------------------------------------------------------
    |
    | The public VIN decoder at https://vpic.nhtsa.dot.gov/api. No key, no
    | account. It is never called on the request path — only from the queued
    | job that fills the vin_patterns cache.
    |
    */

    'endpoint' => env('VPIC_BATCH_URL', 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVINValuesBatch/'),

    // vPIC accepts at most 50 VINs per batch POST.
    'batch_size' => (int) env('VPIC_BATCH_SIZE', 50),

    'timeout' => (int) env('VPIC_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Politeness
    |--------------------------------------------------------------------------
    |
    | A free government API with no published rate limit. Batches are spaced
    | out rather than fired as fast as the queue can drain them; at the default
    | six batches a minute that is 300 patterns a minute, which clears a
    | typical backfill overnight without ever looking like an attack.
    |
    */

    'batches_per_minute' => (int) env('VPIC_BATCHES_PER_MINUTE', 6),

    // Give up on a pattern after this many failed attempts.
    'max_attempts' => (int) env('VPIC_MAX_ATTEMPTS', 3),

    'queue' => env('VPIC_QUEUE', 'vin'),

    /*
    | Queue connection for the VIN jobs, set explicitly rather than inherited
    | from QUEUE_CONNECTION.
    |
    | The application default is `sync`, which runs a dispatched job inline and
    | ignores its delay — that would put every vPIC call and every fleet-age
    | aggregation on the request path, which is the one thing this whole design
    | exists to avoid. These two jobs must be genuinely asynchronous whatever
    | the rest of the application is set to.
    */
    'connection' => env('VIN_QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Fleet statistics
    |--------------------------------------------------------------------------
    |
    | A carrier's fleet age is recomputed at most this often. Inspections
    | arrive from the FMCSA feed daily at best, so anything shorter is wasted
    | work against the external database. Seconds.
    |
    */

    'fleet_stats_ttl' => (int) env('CARRIER_FLEET_STATS_TTL', 86400),

    /*
    | Ages outside this range are treated as a bad decode rather than a very
    | old truck. Years, inclusive.
    */
    'max_plausible_age' => (int) env('VIN_MAX_PLAUSIBLE_AGE', 60),

];
