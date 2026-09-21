<?php

namespace App\Models\Eld;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EldDriver extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'terminal_id',
        'first_name',
        'last_name',
        'username',
        'phone',
        'license_number',
        'license_state',
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
