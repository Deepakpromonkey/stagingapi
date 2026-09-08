<?php

namespace App\Models\Eld;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EldVehicle extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'terminal_id',
        'name',
        'vin',
        'make',
        'model',
        'year',
        'license_plate',
        'status',
        'payload',
        'terminal_modified_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'terminal_modified_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }
}
