<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierInvitation extends Model
{
    protected $fillable = [
        'uuid',
        'carrier_company_id',
        'carrier_user_id',
        'role_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'token',
        'expires_at',
        'accepted_at',
        'created_by',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function carrierCompany()
    {
        return $this->belongsTo(CarrierCompany::class, 'carrier_company_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /** The login created for the invitee. */
    public function carrierUser()
    {
        return $this->belongsTo(CarrierUser::class, 'carrier_user_id');
    }

    /** The carrier owner who sent it. */
    public function creator()
    {
        return $this->belongsTo(CarrierUser::class, 'created_by');
    }
}
