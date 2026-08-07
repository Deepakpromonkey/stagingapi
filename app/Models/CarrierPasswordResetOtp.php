<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierPasswordResetOtp extends Model
{
    protected $fillable = [
        'carrier_user_id',
        'otp_session',
        'otp',
        'reset_token',
        'expires_at',
        'verified_at',
        'attempts',
        'ip_address',
    ];

    protected $hidden = [
        'otp',
        'reset_token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
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
