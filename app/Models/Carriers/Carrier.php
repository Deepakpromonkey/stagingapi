<?php

namespace App\Models\Carriers;

use App\Casts\FmcsaFlag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

class Carrier extends Model
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

    protected $primaryKey = 'id';

    protected $fillable = [
        'dot_number', 'legal_name', 'dba_name', 'carrier_operation',
        'hm_flag', 'row_id',
        'phy_street', 'phy_city', 'phy_state', 'phy_zip', 'phy_country',
        'mailing_street', 'mailing_city', 'mailing_state', 'mailing_zip', 'mailing_country',
        'telephone', 'fax', 'email_address',
        'mcs150_date', 'mcs150_mileage', 'mcs150_mileage_year',
        'add_date', 'nbr_power_unit', 'driver_total',
    ];

    /**
     * hm_flag is company_census_file.hm_ind, a tinyint. The MCS-150 operation
     * flags are not on this view at all — see the census() relation.
     */
    protected $casts = [
        'hm_flag' => FmcsaFlag::class,
        'mcs150_date' => 'date',
        'add_date' => 'date',
    ];

    /**
     * Look a carrier up by the `row_id` the application passes around.
     *
     * `carriers` is a view over company_census_file, and it defines
     * `row_id` as CAST(dot_number AS CHAR) — an expression, not a column. A
     * query of `where row_id = ?` therefore wraps the indexed column in a
     * function, which MySQL cannot answer from the `uk_dot` index, so it scans
     * the whole census file and casts every row. Measured against the live
     * database that is ~21 seconds per lookup, versus ~0.6s for the same
     * carrier found by dot_number.
     *
     * Since row_id is only the dot number rendered as a string, matching on
     * dot_number returns exactly the same row and can use the index. Anything
     * non-numeric falls back to the original column so no caller can break.
     */
    public static function findByRowId(?string $rowId): ?self
    {
        $rowId = trim((string) $rowId);

        if ($rowId === '') {
            return null;
        }

        if (ctype_digit($rowId)) {
            return static::where('dot_number', (int) $rowId)->first();
        }

        return static::where('row_id', $rowId)->first();
    }

    /**
     * The carrier id behind a `row_id`, for callers that only need the key.
     *
     * The `carriers` view defines `id` as the dot number itself, so for a
     * numeric row_id the answer is already known — the remote query exists
     * purely to confirm the carrier is real. That confirmation is what costs
     * the time: roughly 1.5s to open the connection to the EC2 host plus 0.6s
     * for the query, on every single request, because the census file is not
     * local and the connection is not persistent.
     *
     * The FMCSA census is a bulk dataset that reloads on a schedule, so whether
     * a DOT number exists is stable for hours. Caching that one fact turns a
     * repeat shortlist add or remove into a local-only operation.
     *
     * Only positive answers are cached. A miss must stay a miss for at most a
     * short while, otherwise a carrier that appears in the next FMCSA load
     * would keep reporting "not found" until the cache expired.
     */
    public static function resolveIdFromRowId(?string $rowId): ?int
    {
        $rowId = trim((string) $rowId);

        if ($rowId === '') {
            return null;
        }

        $key = 'carrier:rowid:'.$rowId;

        $cached = Cache::get($key);

        if ($cached !== null) {
            return (int) $cached;
        }

        $carrier = static::findByRowId($rowId);

        if (! $carrier) {
            return null;
        }

        Cache::put($key, (int) $carrier->id, now()->addHours(12));

        return (int) $carrier->id;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * FMCSA operating authority record.
     *
     * A DOT number can carry several authority rows (re-filings, HHG/passenger
     * dockets), so pin this to the newest or the relation returns whichever row
     * the storage engine happens to hand back first.
     */
    public function authority(): HasOne
    {
        return $this->hasOne(CarrierAuthority::class, 'dot_number', 'dot_number')
            ->ofMany('id', 'max');
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
        return $query->where('hm_flag', 1);
    }

    /** pc_flag lives on the census extract, not the main census file. */
    public function scopePassengerCarrier($query)
    {
        return $query->whereHas('census', fn ($q) => $q->where('pc_flag', 1));
    }

    /**
     * MCS-150 detail record.
     *
     * One row per DOT number now that this reads company_census_file, which the
     * unique key on dot_number guarantees — no de-duplication needed.
     */
    public function carrierDetail(): HasOne
    {
        return $this->hasOne(CarrierDetail::class, 'dot_number', 'dot_number');
    }

    /**
     * MCS-150 operation classification flags (authorized_for_hire, migrant, …).
     *
     * A separate record because they come from the SMS census extract, which
     * covers ~761k of the 4.48M carriers. Null means "not in that extract",
     * not "false".
     */
    public function census(): HasOne
    {
        return $this->hasOne(CarrierCensus::class, 'dot_number', 'dot_number');
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
