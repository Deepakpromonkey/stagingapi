<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The trucking company a set of portal logins belongs to — the carrier-side
 * counterpart of Company.
 */
class CarrierCompany extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'legal_name',
        'dot_number',
        'phone',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(CarrierUser::class, 'carrier_company_id');
    }

    /**
     * The person who signs, gets paid, and manages everyone else.
     */
    public function owner()
    {
        return $this->hasOne(CarrierUser::class, 'carrier_company_id')
            ->where('is_owner', true);
    }

    public function invitations()
    {
        return $this->hasMany(CarrierInvitation::class, 'carrier_company_id');
    }

    public function displayName(): string
    {
        return $this->legal_name ?: ('DOT '.$this->dot_number);
    }
}
