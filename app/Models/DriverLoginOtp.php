<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time sign-in code for the driver app.
 *
 * Keyed by phone rather than by driver, because the first code a driver is ever
 * sent is issued before any driver record exists.
 */
class DriverLoginOtp extends Model
{
    protected $fillable = [
        'phone_e164',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /** Never leaves the application, even if a caller serialises the model. */
    protected $hidden = [
        'code_hash',
    ];
}
