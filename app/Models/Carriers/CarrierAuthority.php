<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierAuthority extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $table = 'carrier_authorities';

    protected $fillable = [
        'docket_number', 'dot_number', 'mx_type', 'rfc_number',
        'common_stat', 'contract_stat', 'broker_stat', 'row_id',
        'common_app_pend', 'contract_app_pend', 'broker_app_pend',
        'common_rev_pend', 'contract_rev_pend', 'broker_rev_pend',
        'property_chk', 'passenger_chk', 'hhg_chk', 'private_auth_chk', 'enterprise_chk',
        'min_cov_amount', 'cargo_req', 'bond_req', 'bipd_file', 'cargo_file', 'bond_file',
        'undeliverable_mail', 'legal_name', 'dba_name',
        'bus_street_po', 'bus_colonia', 'bus_city', 'bus_state_code', 'bus_ctry_code', 'bus_zip_code', 'bus_telno', 'bus_fax',
        'mail_street_po', 'mail_colonia', 'mail_city', 'mail_state_code', 'mail_ctry_code', 'mail_zip_code', 'mail_telno', 'mail_fax',
    ];

    protected $casts = [
        'common_app_pend' => 'boolean',
        'contract_app_pend' => 'boolean',
        'broker_app_pend' => 'boolean',
        'common_rev_pend' => 'boolean',
        'contract_rev_pend' => 'boolean',
        'broker_rev_pend' => 'boolean',
        'property_chk' => 'boolean',
        'passenger_chk' => 'boolean',
        'hhg_chk' => 'boolean',
        'private_auth_chk' => 'boolean',
        'enterprise_chk' => 'boolean',
        'cargo_req' => 'boolean',
        'bond_req' => 'boolean',
        'undeliverable_mail' => 'boolean',
        'min_cov_amount' => 'decimal:2',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }
}
