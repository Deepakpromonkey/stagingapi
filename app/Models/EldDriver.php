<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EldDriver extends Model
{
    protected $fillable = [
        'eld_connection_id',
        'terminal_id',
        'source_id',
        'provider',
        'status',
        'first_name',
        'last_name',
        'email',
        'phone',
        'license_number',
        'license_state',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function connection()
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }
}
