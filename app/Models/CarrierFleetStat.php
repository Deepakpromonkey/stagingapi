<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Precomputed fleet age figures for one carrier, refreshed off the request
 * path by carrier:refresh-fleet-stats.
 */
class CarrierFleetStat extends Model
{
    protected $table = 'carrier_fleet_stats';

    protected $primaryKey = 'dot_number';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'dot_number', 'avg_power_age', 'avg_trailer_age',
        'power_units', 'trailers', 'vins_total', 'vins_decoded', 'computed_at',
    ];

    protected $casts = [
        'avg_power_age' => 'float',
        'avg_trailer_age' => 'float',
        'power_units' => 'integer',
        'trailers' => 'integer',
        'vins_total' => 'integer',
        'vins_decoded' => 'integer',
        'computed_at' => 'datetime',
    ];

    /**
     * The shape the carrier profile endpoint hands to the frontend.
     */
    public function toPayload(): array
    {
        return [
            'avg_power_age' => $this->avg_power_age,
            'avg_trailer_age' => $this->avg_trailer_age,
            'power_units' => $this->power_units,
            'trailers' => $this->trailers,
            'vins_total' => $this->vins_total,
            'vins_decoded' => $this->vins_decoded,
            'computed_at' => $this->computed_at?->toIso8601String(),
        ];
    }

    public static function emptyPayload(): array
    {
        return [
            'avg_power_age' => null,
            'avg_trailer_age' => null,
            'power_units' => 0,
            'trailers' => 0,
            'vins_total' => 0,
            'vins_decoded' => 0,
            'computed_at' => null,
        ];
    }
}
