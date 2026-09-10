<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Crash extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'crashes';

    protected $fillable = [
        'report_number', 'report_seq_no', 'row_id', 'dot_number', 'report_date', 'report_state',
        'fatalities', 'injuries', 'tow_away', 'hazmat_released',
        'trafficway_desc', 'access_control_desc', 'road_surface_condition_desc',
        'weather_condition_desc', 'light_condition_desc',
        'vehicle_id_number', 'vehicle_license_number', 'vehicle_license_state',
        'severity_weight', 'time_weight', 'citation_issued_desc', 'seq_num', 'not_preventable',
    ];

    protected $casts = [
        'report_date' => 'date',
        'tow_away' => 'boolean',
        'hazmat_released' => 'boolean',
        'not_preventable' => 'boolean',
        'severity_weight' => 'decimal:4',
        'time_weight' => 'decimal:4',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    /** Detailed MCMIS record for the same crash report */
    public function detail(): HasOne
    {
        return $this->hasOne(CrashDetail::class, 'report_number', 'report_number');
    }

    public function scopePreventable($query)
    {
        return $query->where('not_preventable', false);
    }

    public function scopeWithFatalities($query)
    {
        return $query->where('fatalities', '>', 0);
    }

    public function scopeHazmat($query)
    {
        return $query->where('hazmat_released', true);
    }
}
