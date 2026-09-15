<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->role();

        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,

            // Added in main branch.
            'country_code' => $this->country_code,

            'designation' => $this->designation,
            'is_owner' => $this->is_owner,
            'status' => $this->status,

            'profile_image' => $this->profile_image
                ? Storage::disk('s3')->url($this->profile_image)
                : null,

            // True while the user is still on the temporary password from
            // their invitation email — the client should route them to the
            // change-password screen.
            'must_change_password' => (bool) $this->must_change_password,

            'role' => $role ? [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'level' => $role->level,
            ] : null,

            // Effective risk authority: the user override when set,
            // otherwise the role default.
            'capabilities' => [
                'can_override_soft' => $this->canOverrideSoft(),
                'can_override_gate' => $this->canOverrideGate(),
                'payment_release_limit' => $this->paymentReleaseLimit(),
            ],

            'company' => new CompanyResource($this->whenLoaded('company')),

            /*
            | When the seat was created and last touched.
            |
            | Both the raw timestamp and a pre-formatted string: the profile
            | screen renders "Onboarding Date" / "Last Updated" straight from
            | these, and used to show them blank because the resource carried
            | neither.
            */
            'added_on' => $this->created_at?->toIso8601String(),
            'added_on_formatted' => $this->created_at?->format('d M, Y'),
            'updated_on' => $this->updated_at?->toIso8601String(),
            'updated_on_formatted' => $this->updated_at?->format('d M, Y'),
        ];
    }
}