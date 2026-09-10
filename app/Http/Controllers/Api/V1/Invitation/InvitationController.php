<?php

namespace App\Http\Controllers\Api\V1\Invitation;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Invitation\AcceptInvitationRequest;
use App\Http\Requests\Invitation\InviteUserRequest;
use App\Http\Resources\AuthUserResource;
use App\Http\Resources\InvitationResource;
use App\Services\InvitationService;
use Illuminate\Http\Request;

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

    /**
     * Send the invitation email again, with a fresh temporary password.
     *
     * Keyed by the *user's* uuid rather than the invitation's — that is what
     * the team list already holds, and there is exactly one account per
     * invitation.
     */
    public function resend(Request $request, string $uuid)
    {
        $invitation = $this->invitationService->resendInvitation(
            $request->user(),
            $uuid
        );

        return $this->success(
            new InvitationResource($invitation),
            'Invitation resent successfully.'
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
