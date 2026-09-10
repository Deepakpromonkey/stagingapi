<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\CarrierPortal\InviteCarrierUserRequest;
use App\Http\Requests\CarrierPortal\UpdateCarrierUserRequest;
use App\Http\Resources\CarrierInvitationResource;
use App\Http\Resources\CarrierRoleResource;
use App\Http\Resources\CarrierUserResource;
use App\Services\Carrier\CarrierInvitationService;
use App\Services\Carrier\CarrierRoleService;

/**
 * The carrier's own team.
 *
 * Managing users is a sensitive write, so every route here sits behind
 * `manage-carrier-users` — the Carrier Owner/Admin seat and nothing else.
 * Listing is open to the whole account so staff can see who they work with.
 */
class CarrierUserController extends BaseController
{
    public function __construct(
        protected CarrierInvitationService $carrierInvitationService,
        protected CarrierRoleService $carrierRoleService
    ) {}

    public function index()
    {
        $users = $this->carrierInvitationService->listUsers(auth()->user());

        return $this->success([
            'users' => CarrierUserResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Seats the caller may hand out. Empty for anyone but the owner.
     */
    public function roles()
    {
        $roles = $this->carrierRoleService->assignableBy(auth()->user());

        return $this->success(
            CarrierRoleResource::collection($roles)
        );
    }

    /**
     * Create a colleague's login and email them their credentials.
     */
    public function invite(InviteCarrierUserRequest $request)
    {
        $invitation = $this->carrierInvitationService->invite(
            $request->validated(),
            $request->user()
        );

        return $this->success(
            new CarrierInvitationResource($invitation),
            'Invitation sent successfully.',
            201
        );
    }

    /**
     * Change a colleague's seat, contact details or access.
     */
    public function update(UpdateCarrierUserRequest $request, string $uuid)
    {
        $carrierUser = $this->carrierInvitationService->updateUser(
            $request->user(),
            $uuid,
            $request->validated()
        );

        return $this->success(
            new CarrierUserResource($carrierUser),
            'User updated successfully.'
        );
    }
}
