<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrashDetail extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'crash_details';

    protected $fillable = [
        'change_date', 'crash_id', 'report_state', 'row_id', 'report_number', 'report_date', 'report_time',
        'report_seq_no', 'dot_number', 'ci_status_code', 'final_status_date', 'location',
        'city_code', 'city', 'state', 'county_code', 'truck_bus_ind',
        'trafficway_id', 'access_control_id', 'road_surface_condition_id', 'cargo_body_type_id',
        'gvw_rating_id', 'vehicle_identification_number', 'vehicle_license_number', 'vehicle_lic_state',
        'vehicle_hazmat_placard', 'weather_condition_id', 'vehicle_configuration_id', 'light_condition_id',
        'hazmat_released', 'agency', 'vehicles_in_accident', 'fatalities', 'injuries', 'tow_away',
        'federal_recordable', 'state_recordable', 'snet_version_number', 'snet_sequence_id',
        'transaction_code', 'transaction_date', 'upload_first_byte', 'upload_dot_number',
        'upload_search_indicator', 'upload_date', 'add_date',
        'crash_carrier_id', 'crash_carrier_name', 'crash_carrier_street', 'crash_carrier_city',
        'crash_carrier_city_code', 'crash_carrier_state', 'crash_carrier_zip_code', 'crash_colonia',
        'docket_number', 'crash_carrier_interstate', 'no_id_flag', 'state_number', 'state_issuing_number',
        'crash_event_seq_id_desc',
    ];

    protected $casts = [
        'report_date' => 'date',
        'final_status_date' => 'date',
        'transaction_date' => 'date',
        'upload_date' => 'date',
        'add_date' => 'date',
        'vehicle_hazmat_placard' => 'boolean',
        'hazmat_released' => 'boolean',
        'tow_away' => 'boolean',
        'federal_recordable' => 'boolean',
        'state_recordable' => 'boolean',
        'crash_carrier_interstate' => 'boolean',
        'no_id_flag' => 'boolean',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }

    public function crash(): BelongsTo
    {
        return $this->belongsTo(Crash::class, 'report_number', 'report_number');
    }
}
