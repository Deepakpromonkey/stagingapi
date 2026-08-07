<?php

namespace App\Models\Carriers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Carrier extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $primaryKey = 'id';

    protected $fillable = [
        'dot_number', 'legal_name', 'dba_name', 'carrier_operation',
        'hm_flag', 'pc_flag', 'row_id',
        'phy_street', 'phy_city', 'phy_state', 'phy_zip', 'phy_country',
        'mailing_street', 'mailing_city', 'mailing_state', 'mailing_zip', 'mailing_country',
        'telephone', 'fax', 'email_address',
        'mcs150_date', 'mcs150_mileage', 'mcs150_mileage_year',
        'recent_mileage', 'recent_mileage_year', 'vmt_source_id',
        'add_date', 'oic_state', 'nbr_power_unit', 'driver_total',
        'private_only', 'authorized_for_hire', 'exempt_for_hire',
        'private_property', 'private_passenger_business', 'private_passenger_nonbusiness',
        'migrant', 'us_mail', 'federal_government', 'state_government',
        'local_government', 'indian_tribe', 'op_other',
    ];

    protected $casts = [
        'hm_flag' => 'boolean',
        'pc_flag' => 'boolean',
        'mcs150_date' => 'date',
        'add_date' => 'date',
        'private_only' => 'boolean',
        'authorized_for_hire' => 'boolean',
        'exempt_for_hire' => 'boolean',
        'private_property' => 'boolean',
        'private_passenger_business' => 'boolean',
        'private_passenger_nonbusiness' => 'boolean',
        'migrant' => 'boolean',
        'us_mail' => 'boolean',
        'federal_government' => 'boolean',
        'state_government' => 'boolean',
        'local_government' => 'boolean',
        'indian_tribe' => 'boolean',
        'op_other' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /** FMCSA operating authority record */
    public function authority(): HasOne
    {
        return $this->hasOne(CarrierAuthority::class, 'dot_number', 'dot_number');
    }

    /** Out-of-service orders issued against this carrier */
    public function oosOrders(): HasMany
    {
        return $this->hasMany(CarrierOosOrder::class, 'dot_number', 'dot_number');
    }

    /** Authority suspension / revocation orders */
    public function authorityOrders(): HasMany
    {
        return $this->hasMany(CarrierAuthorityOrder::class, 'dot_number', 'dot_number');
    }

    /** Authority history / docket actions */
    public function authorityHistory(): HasMany
    {
        return $this->hasMany(CarrierAuthorityHistory::class, 'dot_number', 'dot_number');
    }

    /** Primary contacts */
    public function contacts(): HasMany
    {
        return $this->hasMany(CarrierContact::class, 'dot_number', 'dot_number');
    }

    /** SMS BASIC performance measures */
    public function smsMeasures(): HasOne
    {
        return $this->hasOne(SmsMeasure::class, 'dot_number', 'dot_number');
    }

    /** Roadside inspections */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'dot_number', 'dot_number');
    }

    /** Crash summary records */
    public function crashes(): HasMany
    {
        return $this->hasMany(Crash::class, 'dot_number', 'dot_number');
    }

    /** Detailed crash records (MCMIS) */
    public function crashDetails(): HasMany
    {
        return $this->hasMany(CrashDetail::class, 'dot_number', 'dot_number');
    }

    /** Active insurance filings */
    public function insuranceFilings(): HasMany
    {
        return $this->hasMany(InsuranceFiling::class, 'dot_number', 'dot_number');
    }

    /** Pending / rejected insurance filings */
    public function insuranceFilingsPending(): HasMany
    {
        return $this->hasMany(InsuranceFilingPending::class, 'dot_number', 'dot_number');
    }

    /** Historical insurance filings */
    public function insuranceFilingsHistory(): HasMany
    {
        return $this->hasMany(InsuranceFilingHistory::class, 'dot_number', 'dot_number');
    }

    /** Violation details across all inspections */
    public function violationDetails(): HasMany
    {
        return $this->hasMany(ViolationDetail::class, 'dot_number', 'dot_number');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeInterstate($query)
    {
        return $query->where('carrier_operation', 'A');
    }

    public function scopeHazmat($query)
    {
        return $query->where('hm_flag', true);
    }

    public function scopePassengerCarrier($query)
    {
        return $query->where('pc_flag', true);
    }

    public function carrierDetail()
    {
        return $this->hasOne(CarrierDetail::class, 'dot_number', 'dot_number');
    }

    public function brokerInsurance(): HasMany
    {
        return $this->hasMany(
            BrokerInsurance::class,
            'prefix_docket_number',
            'dot_number'
        );
    }
}
