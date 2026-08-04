<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViolationDetail extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'violation_details';

    protected $fillable = [
        'unique_id', 'insp_date', 'dot_number', 'viol_code', 'basic_desc', 'row_id',
        'oos_indicator', 'oos_weight', 'severity_weight', 'time_weight',
        'total_severity_wght', 'section_desc', 'group_desc', 'viol_unit',
    ];

    protected $casts = [
        'insp_date' => 'date',
        'oos_indicator' => 'boolean',
        'oos_weight' => 'decimal:4',
        'severity_weight' => 'decimal:4',
        'time_weight' => 'decimal:4',
        'total_severity_wght' => 'decimal:4',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'unique_id', 'unique_id');
    }

    public function scopeOos($query)
    {
        return $query->where('oos_indicator', true);
    }

    public function scopeForBasic($query, string $basic)
    {
        return $query->where('basic_desc', $basic);
    }
}
