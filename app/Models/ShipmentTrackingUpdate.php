<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One "Send Updates To" window on a shipment: when the tracking update goes
 * out, how long to keep tracking, and how often. A shipment has as many of
 * these as the broker added rows for.
 */
class ShipmentTrackingUpdate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'date_time' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
