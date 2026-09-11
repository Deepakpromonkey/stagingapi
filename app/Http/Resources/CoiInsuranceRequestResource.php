<?php

namespace App\Http\Resources;

use App\Models\CoiInsuranceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the carrier profile's insurance card needs to render the track button.
 *
 * The recipient address is included because a broker chasing a slow agency
 * wants to know who was actually asked; the reply body is not, and is fetched
 * only when they open it.
 *
 * @mixin CoiInsuranceRequest
 */
class CoiInsuranceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'dot_number' => $this->dot_number,
            'carrier_name' => $this->carrier_name,
            'carrier_mc' => $this->carrier_mc,

            'status' => $this->status,
            'status_label' => $this->statusLabel(),

            'recipient_email' => $this->recipient_email,
            'recipient_source' => $this->recipient_source,

            'insurance_expiry_date' => $this->insurance_expiry_date?->toDateString(),

            'sent_at' => $this->sent_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            // Only set on a failure, and it is the reason the card shows.
            'last_error' => $this->last_error,

            // Present once a reply has arrived — this is what the "view
            // response" link on the card opens.
            'response_uuid' => $this->whenLoaded(
                'latestResponse',
                fn () => $this->latestResponse?->uuid,
            ),
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            CoiInsuranceRequest::STATUS_PENDING => 'Pending',
            CoiInsuranceRequest::STATUS_RESPONDED => 'Reading reply',
            CoiInsuranceRequest::STATUS_SUCCESS => 'Received',
            CoiInsuranceRequest::STATUS_AWAITING => 'Awaiting certificate',
            CoiInsuranceRequest::STATUS_EXPIRED => 'No response',
            default => 'Failed',
        };
    }
}
