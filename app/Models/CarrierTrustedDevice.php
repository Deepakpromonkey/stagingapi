<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CarrierTrustedDevice extends Model
{
    protected $fillable = [
        'carrier_user_id',
        'device_uuid',
        'device_name',
        'browser',
        'platform',
        'ip_address',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function carrierUser()
    {
        return $this->belongsTo(CarrierUser::class, 'carrier_user_id');
    }

    /**
     * Trust that has not run out. A null expiry never expires.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(function ($query) {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
