<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One duty-status change from a driver's log.
 */
class EldHosLog extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'terminal_id',
        'source_id',
        'provider',
        'status',
        'driver_terminal_id',
        'vehicle_terminal_id',
        'started_at',
        'ended_at',
        'latitude',
        'longitude',
        'remarks',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function connection()
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }

    public function driver()
    {
        return $this->belongsTo(EldDriver::class, 'driver_terminal_id', 'terminal_id');
    }
}
