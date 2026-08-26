<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Carrier insurance requests
|--------------------------------------------------------------------------
|
| Closes out the ones no agency ever answered. Nothing else moves a request
| off `pending`, and the resend cooldown in the service will not let a broker
| ask again while one is still open.
|
*/
Schedule::command('coi:expire-requests')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->onOneServer();
