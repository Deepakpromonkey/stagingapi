<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShipmentStop extends Model
{
    use HasFactory;

    protected $guarded = []; 

    protected $casts = [
        'events' => 'array',  
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}