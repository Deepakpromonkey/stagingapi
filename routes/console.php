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

// Patterns whose row was written but whose job was lost — a killed worker, a
// queue:clear, a job that exhausted its attempts. Nothing else picks these up,
// because everything else deliberately skips a pattern that already has a row.
Schedule::command('vin:requeue')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Recompute the carriers whose stored fleet age has gone stale. Capped per
// run so this never turns into an unbounded job.
Schedule::command('carrier:refresh-fleet-stats --stale --limit=2000')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| ELD / telematics
|--------------------------------------------------------------------------
|
| Refreshes the fleets of carriers that are actually under load. Terminal bills
| for data synced rather than for vehicles and drivers held, so polling every
| carrier who ever finished onboarding would run up a bill for fleets nobody is
| looking at — the command scopes itself to carriers with an active shipment and
| goes quiet again when the load is delivered.
|
| The first import after a carrier connects does not come from here: it is
| dispatched on the spot by the Link exchange. Nor does the routine case of new
| data arriving — Terminal's sync.completed and vehicle.added webhooks queue a
| pass as it happens. This is the floor under both of those, for the connection
| whose webhook was never delivered.
|
*/
Schedule::command('eld:sync-active')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Queue
|--------------------------------------------------------------------------
|
| The worker, started from the scheduler rather than supervised.
|
| Both queues are intermittent: the VIN sweep is finished, so `vin` now only
| sees the daily incremental pass, the hourly requeue and the odd fleet-stats
| refresh a profile view dispatches, while `default` sees a mail and an
| extraction whenever a broker chases an insurance agency. Neither justifies a
| process sitting idle around the clock, and until now the only worker on this
| box was an unsupervised `nohup` that would not survive a reboot.
|
| --stop-when-empty means this normally exits in well under a second and does
| nothing at all when there is nothing to do. If it dies, the next minute
| starts a fresh one — which is more supervision than it had before.
|
| `default` is named first because Laravel drains queues in order, and a broker
| waiting on a request should not queue behind a VIN batch or a fleet import.
| `eld` is last for the same reason: a first sync after a carrier connects can
| run for minutes on a large fleet, and nothing is waiting on it. The connection
| named explicitly because QUEUE_CONNECTION is `sync` here; a worker without it
| would watch the wrong connection and sit idle forever. See docs/vin-decoding.md.
|
| Delayed jobs are not "available", so the VIN batches' pacing survives this:
| the worker exits, and the next run picks up whatever has come due.
|
| This is a stopgap for a box with no process supervision. Given systemd or
| supervisor, run a long-lived `queue:work` instead and delete this.
|
*/
Schedule::command('queue:work database --queue=default,vin,eld --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Carrier insurance requests
|--------------------------------------------------------------------------
|
| Closes out the ones no agency ever answered. Nothing else moves a request
| off `pending`, and the resend cooldown in the service will not let a broker
| ask again while one is still open.
|
| Deliberately after the VIN sweep rather than alongside it — both are daily
| and neither is urgent, so they may as well not contend for the same worker.
|
*/
Schedule::command('coi:expire-requests')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Asks the silent agencies again.
|
| Hourly rather than daily because the interval it enforces is measured in
| hours: a daily run would turn a 24-hour cadence into anything between 24 and
| 48 depending on when the request happened to be raised. The command itself
| decides what is due, so running it often is cheap and running it rarely is
| what loses the cadence.
*/
Schedule::command('coi:chase-requests')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();