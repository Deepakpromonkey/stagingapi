<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * The authenticated user's own profile — same shape as UserResource plus the
 * flat permission list the frontend uses to show / hide actions.
 */
class AuthUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'permissions' => $this->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values(),
        ];
    }
}
