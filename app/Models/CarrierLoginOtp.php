<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierLoginOtp extends Model
{
    protected $fillable = [
        'carrier_user_id',
        'otp_session',
        'otp',
        'expires_at',
        'attempts',
        'device_uuid',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function carrierUser()
    {
        return $this->belongsTo(CarrierUser::class, 'carrier_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
