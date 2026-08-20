<?php

namespace App\Models;

use App\Models\Carriers\Carrier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An incident a broker company reported against a carrier.
 *
 * `is_private` keeps the report inside the reporting company; anything else is
 * readable by any broker looking at that carrier's profile.
 */
class CarrierReport extends Model
{
    protected $table = 'carrier_reports';

    protected $guarded = [];

    protected $casts = [
        'incidents' => 'array',
        'is_private' => 'boolean',
        'incident_date' => 'date',
        'emailed_at' => 'datetime',
    ];

    /**
     * The incident checklist, slug => label.
     *
     * Single source of truth: validation, the /carrier-reports/incidents
     * endpoint the modal renders from, and the email all read this, so the list
     * can never drift between the three.
     */
    public const INCIDENTS = [
        'back_solicited_shipper_carrier' => 'Back-Solicited Shipper/Carrier',
        'held_load_hostage' => 'Held Load Hostage',
        'in_transit_agreement_modification' => 'In-Transit Agreement Modification',
        'payment_issue' => 'Payment Issue - non-payment, delayed payment or excessive short-pay',
        'unethical_or_deceptive_business_practices' => 'Unethical or Deceptive Business Practices',
        'theft_or_unjustified_loss_of_freight' => 'Theft or Unjustified Loss of Freight',
        'unresolved_claim_issue' => 'Unresolved Claim Issue',
        'broken_seal_tampered_load' => 'Broken Seal / Tampered Load',
        'poor_quality_or_damaged_equipment' => 'Poor Quality or Damaged Equipment',
        'trailer_misuse' => 'Trailer Misuse',
        'wrong_equipment' => 'Wrong Equipment',
        'fraudulent_activity_identity_theft' => 'Fraudulent Activity - Identity Theft or Misrepresentation of Identity',
        'fraudulent_activity_other' => 'Fraudulent Activity - Other',
        'operated_under_alias' => 'Operated Under Alias',
        'operated_without_bond_or_trust_fund' => 'Operated Without Bond or Trust Fund',
        'subcontracted_freight_without_authority' => 'Subcontracted Freight without Broker or Freight Forwarder Authority',
        'unauthorized_or_incomplete_subcontracting' => 'Unauthorized or Incomplete Subcontracting or Leasing Arrangement',
        'unauthorized_re_brokering' => 'Unauthorized Re-brokering of Shipment (Double Brokering)',
        'cancelled_after_accepting_load' => 'Cancelled After Accepting/Tendering Load',
        'no_show_without_notification' => 'No Show without Notification',
        'pickup_or_delivery_service_failure' => 'Pickup or Delivery Service Failure',
        'poor_communication' => 'Poor communication - check calls, tracking, load status, load details, etc',
        'eld_disabled_or_rejected' => 'ELD - Disabled or Rejected ELD Connection',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $report) {
            $report->uuid ??= (string) Str::uuid();
        });
    }

    /** The stored slugs turned back into their human labels. */
    public function incidentLabels(): array
    {
        return array_values(array_map(
            fn (string $slug) => self::INCIDENTS[$slug] ?? $slug,
            $this->incidents ?? []
        ));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }

    /** Evidence the broker attached when filing. */
    public function documents(): HasMany
    {
        return $this->hasMany(CarrierReportDocument::class, 'carrier_report_id');
    }
}
