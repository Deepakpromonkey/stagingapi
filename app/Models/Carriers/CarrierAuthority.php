<?php

namespace App\Models\Carriers;

use App\Casts\FmcsaFlag;
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

    /**
     * These are varchar columns holding 'Y' or 'N', not tinyints. Laravel's
     * `boolean` cast does `(bool) $value`, so 'N' came back true — every
     * carrier read as having a pending application, a pending revocation and
     * a cargo/bond requirement. FmcsaFlag decodes them properly.
     *
     * bipd_file / cargo_file / bond_file are deliberately left uncast: the
     * first is a coverage amount in thousands ('00000' = nothing on file) and
     * the other two are 'Y'/'N'. Fmcsa::onFile() reads all three.
     */
    protected $casts = [
        'common_app_pend' => FmcsaFlag::class,
        'contract_app_pend' => FmcsaFlag::class,
        'broker_app_pend' => FmcsaFlag::class,
        'common_rev_pend' => FmcsaFlag::class,
        'contract_rev_pend' => FmcsaFlag::class,
        'broker_rev_pend' => FmcsaFlag::class,
        'property_chk' => FmcsaFlag::class,
        'passenger_chk' => FmcsaFlag::class,
        'hhg_chk' => FmcsaFlag::class,
        'private_auth_chk' => FmcsaFlag::class,
        'enterprise_chk' => FmcsaFlag::class,
        'cargo_req' => FmcsaFlag::class,
        'bond_req' => FmcsaFlag::class,
        'undeliverable_mail' => FmcsaFlag::class,
        'min_cov_amount' => 'decimal:2',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'dot_number', 'dot_number');
    }
}
