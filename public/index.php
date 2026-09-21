<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
| `php artisan serve` runs requests through PHP's built-in web server, whose
| SAPI ("cli-server") caps every script at 30 seconds regardless of what
| php.ini says - `php_sapi_name()` reports "cli" (unlimited) everywhere else,
| which is why this is easy to miss testing outside artisan serve. The
| carrier search legitimately needs more than 30s on a cold, unindexed page
| against the remote FMCSA DB, and MySQL's own per-query timeout (set in
| AdvancedCarrierSearchController) is the real safety net against a query
| that actually hangs - this just stops PHP killing the whole script out
| from under a search that is still correctly running.
|
| Scoped to cli-server only, so production (php-fpm, Octane, etc.) is
| untouched and keeps whatever execution-time policy it already has.
*/
if (PHP_SAPI === 'cli-server') {
    ini_set('max_execution_time', 120);
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
