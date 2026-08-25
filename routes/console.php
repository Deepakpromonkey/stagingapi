<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| VIN decoding
|--------------------------------------------------------------------------
|
| Both of these keep the fleet cards and the year/model columns current
| without anything happening on the request path. Neither is the first run:
| the initial sweep is `php artisan vin:backfill`, run by hand once, and it
| needs a worker on the vin queue to drain what it schedules.
|
*/

// Patterns from inspection rows the FMCSA loader added since the last pass.
Schedule::command('vin:backfill --incremental')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Recompute the carriers whose stored fleet age has gone stale. Capped per
// run so this never turns into an unbounded job.
Schedule::command('carrier:refresh-fleet-stats --stale --limit=2000')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
