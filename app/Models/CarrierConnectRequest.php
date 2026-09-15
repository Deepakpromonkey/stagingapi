<?php

namespace App\Models;

use App\Models\Eld\EldConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A carrier onboarding request raised by a broker company.
 *
 * This model is deliberately data-only. Every onboarding rule — link expiry,
 * OTP throttling, Didit identity checks, Stripe payouts, e-sign — lives in
 * App\Http\Controllers\Api\V1\Connect\CarrierConnectController.
 */
class CarrierConnectRequest extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_EMAIL_VERIFIED = 'email_verified';

    public const STATUS_MOBILE_VERIFIED = 'mobile_verified';

    public const STATUS_ID_VERIFIED = 'id_verified';

    public const STATUS_BANK_VERIFIED = 'bank_verified';

    public const STATUS_QUESTIONNAIRE_DONE = 'questionnaire_done';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'prefilled_from_request_id',
        'prefilled_at',
        'carrier_user_id',
        'carrier_row_id',
        'carrier_dot_number',
        'carrier_legal_name',
        'carrier_email',
        'pending_email',
        'pending_email_token',
        'pending_email_requested_at',
        'pending_email_approved_at',
        'carrier_phone',
        'token',
        'status',
        'sent_on',
        'first_visit_at',
        'onboarding_ip',
        'email_verified_at',
        'mobile_verified_at',
        'otp',
        'otp_sent_at',
        'otp_attempts',
        'last_otp_attempt_at',
        'didit_session_id',
        'didit_status',
        'didit_responded_at',
        'didit_response',
        'didit_risk_flagged',
        'didit_registration_ip',
        'identity_skipped_at',
        'stripe_express_account',
        'stripe_verified_at',
        'bank_skipped_at',
        'eld_connection_id',
        'eld_link_state',
        'eld_link_state_at',
        'eld_connected_at',
        'eld_skipped_at',
        'uses_factoring_company',
        'factoring_company_name',
        'factoring_document_disk',
        'factoring_document_path',
        'factoring_document_name',
        'factoring_answered_at',
        'questionnaire_completed_at',
        'agreement_document_id',
        'signature_disk',
        'signature_path',
        'signature_page',
        'signature_x_pct',
        'signature_y_pct',
        'signed_at',
        'portal_account_provisioned_at',
        'portal_account_email',
        'portal_account_error',
    ];

    /**
     * Never leaves the application, even if a caller serialises the model
     * directly instead of going through CarrierConnectRequestResource.
     */
    protected $hidden = [
        'otp',
        'token',

        // Holding this token is what proves the carrier approved the alternate
        // address, so it must never travel anywhere but their FMCSA inbox.
        'pending_email_token',

        'didit_session_id',
        'didit_response',

        // The Link nonce is what proves a return from Terminal is the one we
        // sent the carrier on. Exposing it would make that check worthless.
        'eld_link_state',
    ];

    protected $casts = [
        'sent_on' => 'datetime',
        'prefilled_at' => 'datetime',
        'pending_email_requested_at' => 'datetime',
        'pending_email_approved_at' => 'datetime',
        'first_visit_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'mobile_verified_at' => 'datetime',
        'otp_sent_at' => 'datetime',
        'last_otp_attempt_at' => 'datetime',
        'didit_responded_at' => 'datetime',
        'identity_skipped_at' => 'datetime',
        'stripe_verified_at' => 'datetime',
        'bank_skipped_at' => 'datetime',
        'eld_link_state_at' => 'datetime',
        'eld_connected_at' => 'datetime',
        'eld_skipped_at' => 'datetime',
        'factoring_answered_at' => 'datetime',
        'questionnaire_completed_at' => 'datetime',
        'documents_completed_at' => 'datetime',
        'signed_at' => 'datetime',
        'portal_account_provisioned_at' => 'datetime',
        'didit_response' => 'array',
        'didit_risk_flagged' => 'boolean',
        'uses_factoring_company' => 'boolean',
        'otp_attempts' => 'integer',
        'signature_page' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** The teammate who sent the invitation. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The carrier portal login this onboarding provisioned. */
    public function carrierUser()
    {
        return $this->belongsTo(CarrierUser::class, 'carrier_user_id');
    }

    public function agreementDocument()
    {
        return $this->belongsTo(BrokerAgreementDocument::class, 'agreement_document_id');
    }

    /** Answers to the broker company's onboarding questions. */
    public function answers()
    {
        return $this->hasMany(CarrierConnectAnswer::class, 'carrier_connect_request_id');
    }
 public function documents()
    {
        return $this->hasMany(CarrierConnectDocument::class, 'carrier_connect_request_id');
    }

    /**
     * The carrier's telematics connection.
     *
     * Shared: the same connection may be reached from several brokers' connect
     * requests, because a carrier links their provider account once and grants
     * each broker access to it separately.
     */
    public function eldConnection()
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
