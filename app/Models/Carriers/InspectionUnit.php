<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionUnit extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'inspection_units';

    protected $fillable = [
        'change_date', 'inspection_id', 'insp_unit_id', 'row_id', 'insp_unit_type_id',
        'insp_unit_number', 'insp_unit_make', 'insp_unit_company',
        'insp_unit_license', 'insp_unit_license_state', 'insp_unit_vehicle_id_number',
        'insp_unit_decal', 'insp_unit_decal_number',
    ];

    protected $casts = ['change_date' => 'date'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id', 'unique_id');
    }
}
