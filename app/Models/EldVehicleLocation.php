<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a truck was last seen. One row per vehicle, overwritten each sync —
 * the trail lives at Terminal, not here.
 */
class EldVehicleLocation extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'vehicle_terminal_id',
        'latitude',
        'longitude',
        'speed',
        'heading',
        'description',
        'located_at',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'located_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'speed' => 'float',
        'heading' => 'float',
    ];

    public function connection()
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }
}
