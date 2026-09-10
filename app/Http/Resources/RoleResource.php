<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'level' => $this->level,
            'risk_authority' => $this->riskAuthority(),

            // Override capability is a risk decision layered on the seat,
            // not a UI permission — surfaced separately for that reason.
            'capabilities' => [
                'can_override_soft' => (bool) $this->can_override_soft,
                'can_override_gate' => (bool) $this->can_override_gate,
                'payment_release_limit' => $this->payment_release_limit,
            ],

            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')
            ),
        ];
    }
}
