<?php

namespace App\Http\Resources;

use App\Models\CarrierConnectRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One broker a carrier is connected to, as the carrier sees it.
 *
 * Carrier-facing, so it carries the broker's public identity and the carrier's
 * own onboarding progress — never the broker's internal scoring, notes, or the
 * onboarding token.
 */
class CarrierBrokerConnectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,

            'broker' => [
                'uuid' => $this->company?->uuid,
                'company_name' => $this->company?->company_name,
                'company_email' => $this->company?->company_email,
                'company_phone' => $this->company?->company_phone,
                'website' => $this->company?->website,
                'logo' => $this->company?->logo,
                'city' => $this->company?->city,
                'state' => $this->company?->state,
                'country' => $this->company?->country,
            ],

            // The teammate at the broker who raised the onboarding — the
            // carrier's point of contact.
            'contact' => $this->when($this->user !== null, fn () => [
                'name' => trim($this->user->first_name.' '.($this->user->last_name ?? '')),
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),

            'status' => $this->status,

            // Onboarding finished and the agreement signed: this broker can
            // book the carrier.
            'is_active' => $this->status === CarrierConnectRequest::STATUS_COMPLETED,

            'invited_at' => $this->sent_on?->toIso8601String(),
            'connected_at' => $this->signed_at?->toIso8601String(),

            // What the carrier still has outstanding, in the wizard's order.
            'progress' => [
                'email_verified' => $this->email_verified_at !== null,
                'mobile_verified' => $this->mobile_verified_at !== null,
                'identity_verified' => $this->didit_status === 'Approved',
                'banking_verified' => $this->stripe_verified_at !== null,
                'questionnaire_completed' => $this->questionnaire_completed_at !== null,
                'agreement_signed' => $this->signed_at !== null,
            ],

            'uses_factoring_company' => (bool) $this->uses_factoring_company,
            'factoring_company_name' => $this->factoring_company_name,
        ];
    }
}
