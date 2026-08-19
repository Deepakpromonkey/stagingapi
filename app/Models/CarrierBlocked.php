<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierBlocked extends Model
{
    protected $table = 'carrier_blockeds';
    protected $fillable = ['company_id', 'carrier_id', 'user_id'];

    public function carrier()
    {
        return $this->belongsTo(Carriers\Carrier::class, 'carrier_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}