<?php

namespace App\Http\Controllers\Api\V1\Invitation;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Invitation\AcceptInvitationRequest;
use App\Http\Requests\Invitation\InviteUserRequest;
use App\Http\Resources\AuthUserResource;
use App\Http\Resources\InvitationResource;
use App\Services\InvitationService;

class InvitationController extends BaseController
{
    public function __construct(
        protected InvitationService $invitationService
    ) {}

    public function store(InviteUserRequest $request)
    {
        $invitation = $this->invitationService->sendInvitation(
            $request->validated(),
            auth()->user()
        );

        return $this->success(
            new InvitationResource($invitation),
            'Invitation created successfully.',
            201
        );
    }

    public function accept(AcceptInvitationRequest $request)
    {
        $data = $this->invitationService->acceptInvitation(
            $request->validated()
        );

        return $this->success([
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),
        ], 'Invitation accepted successfully.');
    }
}
