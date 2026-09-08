<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inspection extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'inspections';

    protected $fillable = [
        'unique_id', 'report_number', 'row_id', 'report_state', 'dot_number',
        'insp_date', 'insp_level_id', 'county_code_state', 'time_weight',
        'driver_oos_total', 'vehicle_oos_total', 'total_hazmat_sent',
        'oos_total', 'hazmat_oos_total', 'hazmat_placard_req',
        'unit_type_desc', 'unit_make', 'unit_license', 'unit_license_state', 'vin', 'unit_decal_number',
        'unit_type_desc2', 'unit_make2', 'unit_license2', 'unit_license_state2', 'vin2', 'unit_decal_number2',
        'unsafe_insp', 'fatigued_insp', 'dr_fitness_insp', 'subt_alcohol_insp', 'vh_maint_insp', 'hm_insp',
        'basic_viol', 'unsafe_viol', 'fatigued_viol', 'dr_fitness_viol', 'subt_alcohol_viol', 'vh_maint_viol', 'hm_viol',
    ];

    protected $casts = [
        'insp_date' => 'date',
        'hazmat_placard_req' => 'boolean',
        'unsafe_insp' => 'boolean',
        'fatigued_insp' => 'boolean',
        'dr_fitness_insp' => 'boolean',
        'subt_alcohol_insp' => 'boolean',
        'vh_maint_insp' => 'boolean',
        'hm_insp' => 'boolean',
        'time_weight' => 'decimal:4',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    public function units(): HasMany
    {
        return $this->hasMany(InspectionUnit::class, 'inspection_id', 'unique_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(InspectionViolation::class, 'inspection_unique_id', 'unique_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(InspectionCitation::class, 'inspection_id', 'unique_id');
    }

    public function violationDetails(): HasMany
    {
        return $this->hasMany(ViolationDetail::class, 'unique_id', 'unique_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeWithOos($query)
    {
        return $query->where('oos_total', '>', 0);
    }

    public function scopeLevel($query, int $level)
    {
        return $query->where('insp_level_id', $level);
    }

    public function scopeHazmat($query)
    {
        return $query->where('hm_insp', true);
    }
}
