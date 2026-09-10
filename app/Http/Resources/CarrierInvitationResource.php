<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarrierInvitationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,

            'role' => $this->whenLoaded(
                'role',
                fn () => new CarrierRoleResource($this->role)
            ),

            'carrier' => $this->whenLoaded(
                'carrierCompany',
                fn () => [
                    'uuid' => $this->carrierCompany->uuid,
                    'legal_name' => $this->carrierCompany->legal_name,
                    'dot_number' => $this->carrierCompany->dot_number,
                ]
            ),

            'invited_by' => $this->whenLoaded(
                'creator',
                fn () => $this->creator?->displayName()
            ),

            // The login exists from the moment the invite is sent, so this is
            // stamped on creation.
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
