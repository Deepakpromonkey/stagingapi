<?php

/*
|--------------------------------------------------------------------------
| Drayage Directory
|--------------------------------------------------------------------------
|
| Carriers imported from the LoadMatch / Drayage.com directory export. The
| dataset lives in JSON files under `root`, never in MySQL: each import builds
| a new dataset version beside the old ones and an atomic pointer swap
| (current.json) makes it live, so a rollback is the same swap pointed at an
| older folder. See docs/drayage-directory.md for the layout and runbook.
|
*/

return [

    // Private, never served: nginx's root is public/, and nothing links here.
    'root' => env('DRAYAGE_ROOT', storage_path('app/drayage')),

    // Shown wherever a response says where this data came from.
    'source_label' => 'LoadMatch / Drayage.com directory (imported)',

    'schema_version' => 1,

    /*
    | Import limits. The upload size is in kilobytes, the way Laravel's `max`
    | rule counts files. nginx (client_max_body_size) and php.ini
    | (upload_max_filesize / post_max_size) must allow at least as much, or
    | the request dies before it reaches this check - `php artisan
    | drayage:import {file}` sidesteps both for a file already on the box.
    */
    'import' => [
        'max_upload_kb' => (int) env('DRAYAGE_MAX_UPLOAD_KB', 51200),

        // Above this share of rejected rows the import fails and the previous
        // dataset stays live.
        'max_rejected_percent' => (float) env('DRAYAGE_MAX_REJECTED_PERCENT', 5),

        // Rejected rows, warnings and merges kept in an import report.
        'report_cap' => 500,

        // Seconds. Comfortably above a 5k-row import, which measures in seconds.
        'timeout' => (int) env('DRAYAGE_IMPORT_TIMEOUT', 300),

        // An import holds every carrier in memory while it merges duplicates
        // (~100 MB for the 4,664-row export); raised for the import only.
        'memory_limit' => env('DRAYAGE_IMPORT_MEMORY_LIMIT', '512M'),
    ],

    /*
    | The import job is pinned to the database queue the scheduler's worker
    | drains (routes/console.php), the same way the VIN and ELD jobs are -
    | QUEUE_CONNECTION is `sync` on the API boxes.
    */
    'queue' => [
        'connection' => env('DRAYAGE_QUEUE_CONNECTION', 'database'),
        'name' => env('DRAYAGE_QUEUE', 'drayage'),
    ],

    // Dataset versions kept for rollback, the live one included.
    'retention' => (int) env('DRAYAGE_RETENTION', 5),

    /*
    | The decoded search index is cached per dataset id, so activating another
    | dataset invalidates it by construction. null = the app's default store.
    */
    'cache' => [
        'store' => env('DRAYAGE_CACHE_STORE'),
        'ttl' => (int) env('DRAYAGE_CACHE_TTL', 86400),
    ],

    /*
    | Optional compressed copy of each activated dataset on S3. Off by default:
    | staging and production share one bucket, so anything written there goes
    | under a prefix that says which environment it came from.
    */
    'backup' => [
        'enabled' => (bool) env('DRAYAGE_S3_BACKUP', false),
        'disk' => env('DRAYAGE_BACKUP_DISK', 's3'),
        'prefix' => env('DRAYAGE_S3_PREFIX', 'staging/drayage/'),
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 500,
    ],

    // Requests per minute, per user.
    'rate_limits' => [
        'read' => (int) env('DRAYAGE_READ_RATE_LIMIT', 120),
        'export' => (int) env('DRAYAGE_EXPORT_RATE_LIMIT', 5),
        'admin' => (int) env('DRAYAGE_ADMIN_RATE_LIMIT', 60),
    ],

    // Rows in one export. Above this, narrow the filters.
    'export_max_rows' => (int) env('DRAYAGE_EXPORT_MAX_ROWS', 10000),

];
