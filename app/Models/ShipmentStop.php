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
        // Stored as a JSON array so a long alert list cannot overflow the column.
        'alert_emails' => 'array',

        'requires_otp' => 'boolean',
        'otp_verified_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
