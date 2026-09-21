<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrierCompliance extends Model
{
    protected $fillable = [
        'dot_number',
        'phmsa',
        'carb',
        'smartway'
    ];
}
