<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EldVehicle extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'terminal_id',
        'source_id',
        'provider',
        'status',
        'vin',
        'name',
        'make',
        'model',
        'year',
        'license_plate_state',
        'license_plate_number',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'year' => 'integer',
    ];

    public function connection()
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }

    public function location()
    {
        return $this->hasOne(EldVehicleLocation::class, 'vehicle_terminal_id', 'terminal_id')
            ->whereColumn('eld_connection_id', 'eld_connection_id');
    }
}
