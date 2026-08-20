<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SignupOtp extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_PHONE = 'phone';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_PHONE,
    ];

    protected $fillable = [
        'otp_session',
        'channel',
        'destination',
        'otp',
        'verification_token',
        'expires_at',
        'verified_at',
        'consumed_at',
        'attempts',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /*
    | Hidden rather than merely unused. These rows are never serialised to the
    | client today, but the hashed code and the token that stands in for it are
    | the only two secrets here, and a future toJson() should not leak them by
    | accident.
    */
    protected $hidden = [
        'otp',
        'verification_token',
    ];

    /** A code that is still live: not yet expired, and not already proved. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('verified_at')
            ->where('expires_at', '>', now());
    }

    /** A proof that has been earned and not yet spent on an account. */
    public function scopeUnspent(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at')
            ->whereNull('consumed_at');
    }
}
