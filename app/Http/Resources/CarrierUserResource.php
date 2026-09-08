<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarrierUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->displayName(),
            'email' => $this->email,
            'legal_name' => $this->legal_name,
            'dot_number' => $this->dot_number,
            'phone' => $this->phone,
            'profile_image' => $this->profileImageUrl(),
            'status' => (bool) $this->status,
            'is_owner' => (bool) $this->is_owner,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,

            // The portal must send the carrier to the change-password screen
            // while this is true; every other endpoint is closed to them.
            'must_change_password' => (bool) $this->must_change_password,

            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // The trucking company this person belongs to.
            'carrier' => $this->whenLoaded(
                'carrierCompany',
                fn () => [
                    'uuid' => $this->carrierCompany?->uuid,
                    'legal_name' => $this->carrierCompany?->legal_name,
                    'dot_number' => $this->carrierCompany?->dot_number,
                    'phone' => $this->carrierCompany?->phone,
                ]
            ),

            // Carriers hold exactly one seat.
            'role' => $this->whenLoaded(
                'roles',
                fn () => $this->role()
                    ? new CarrierRoleResource($this->role())
                    : null
            ),

            // Flat list for the portal to gate its own screens with.
            'permissions' => $this->whenLoaded(
                'roles',
                fn () => $this->getAllPermissions()->pluck('name')->values()
            ),

            // Broker connections are not listed here: they belong to the
            // carrier company, not to one login, so an invited dispatcher
            // would see an empty list. GET /carrier-portal/brokers.
        ];
    }
}
