<?php

use App\Http\Controllers\Api\V1\Connect\CarrierConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| The link in the approval email, which is only ever sent to the carrier's
| FMCSA-registered address. Opening it is the carrier consenting to onboarding
| running through a different inbox, and is the only thing that releases the
| invitation to that address.
|
| Registered ahead of the invitation route below purely for readability — the
| two cannot collide, since {token} matches a single segment.
*/
Route::get('/carrier/connect/approve-email/{token}', [CarrierConnectController::class, 'approveAlternateEmail'])
    ->middleware('throttle:30,1')
    ->name('carrier.connect.approve-email');

/*
| The link in the carrier invitation email. Landing here proves the carrier
| controls the mailbox, so it marks the email verified before handing off to
| the onboarding wizard on the frontend.
*/
Route::get('/carrier/connect/{token}', [CarrierConnectController::class, 'open'])
    ->middleware('throttle:30,1')
    ->name('carrier.connect.open');
