<?php

namespace App\Models\Eld;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EldLocation extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'vehicle_terminal_id',
        'located_at',
        'latitude',
        'longitude',
        'speed_mph',
        'heading_degrees',
        'odometer_miles',
        'description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'located_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }
}
