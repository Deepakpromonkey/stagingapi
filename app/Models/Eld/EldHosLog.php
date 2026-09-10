<?php

namespace App\Models\Eld;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EldHosLog extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'terminal_id',
        'driver_terminal_id',
        'vehicle_terminal_id',
        'duty_status',
        'started_at',
        'ended_at',
        'duration_seconds',
        'payload',
        'terminal_modified_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'terminal_modified_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }
}
