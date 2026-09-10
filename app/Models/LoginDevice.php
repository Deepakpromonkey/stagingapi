<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_name',
        'browser',
        'platform',
        'ip_address',
        'user_agent',
        'is_current',
        'last_login_at',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
