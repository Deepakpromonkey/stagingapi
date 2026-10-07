<?php

namespace App\Models\Eld;

use Illuminate\Database\Eloquent\Model;

class EldWebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'type',
        'terminal_connection_id',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
