<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'email' => $this->email,

            'role' => [
                'id' => $this->role->id,
                'slug' => $this->role->slug,
                'name' => $this->role->name,
                'level' => $this->role->level,
            ],

            // Null means the invited user inherits the role's capability.
            'capabilities' => [
                'can_override_soft' => $this->can_override_soft,
                'can_override_gate' => $this->can_override_gate,
            ],

            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'created_at' => $this->created_at,
        ];
    }
}
