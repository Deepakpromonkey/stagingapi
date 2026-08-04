<?php

use App\Http\Controllers\Api\V1\Connect\CarrierConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| The link in the carrier invitation email. Landing here proves the carrier
| controls the mailbox, so it marks the email verified before handing off to
| the onboarding wizard on the frontend.
*/
Route::get('/carrier/connect/{token}', [CarrierConnectController::class, 'open'])
    ->middleware('throttle:30,1')
    ->name('carrier.connect.open');
