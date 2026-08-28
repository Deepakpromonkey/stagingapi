<?php

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    /**
     * Create the invited user straight away and email them a link to choose
     * their own password. The account exists from this moment but cannot be
     * signed into until that link is used.
     */
    public function sendInvitation(array $data, $user)
    {
        // Re-check assignability at the service boundary so the rule holds
        // even when the invite is created outside the HTTP request.
        $role = $this->roleService->resolveAssignable($user, $data['role_id']);

        $invitation = DB::transaction(function () use ($data, $user, $role) {

            $canOverrideSoft = isset($data['can_override_soft'])
                ? (bool) $data['can_override_soft']
                : null;

            $canOverrideGate = isset($data['can_override_gate'])
                ? (bool) $data['can_override_gate']
                : null;

            $invitedUser = User::create([
                'uuid' => Str::uuid(),
                'company_id' => $user->company_id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => strtolower($data['email']),
                'password' => $this->unusablePassword(),
                'is_owner' => false,
                'status' => true,
                'must_change_password' => true,

                // Null keeps the role default; a value grants or revokes the
                // override for this user specifically.
                'can_override_soft' => $canOverrideSoft,
                'can_override_gate' => $canOverrideGate,
            ]);

            $invitedUser->assignRole($role);

            $invitation = Invitation::create([
                'uuid' => Str::uuid(),

                'company_id' => $user->company_id,

                'user_id' => $invitedUser->id,

                'role_id' => $role->id,

                'can_override_soft' => $canOverrideSoft,

                'can_override_gate' => $canOverrideGate,

                'first_name' => $data['first_name'],

                'last_name' => $data['last_name'] ?? null,

                'phone' => $data['phone'] ?? null,

                'email' => strtolower($data['email']),

                'token' => Str::random(64),

                'expires_at' => now()->addDays(7),

                // The account exists from this moment, so there is nothing
                // left for the invitee to accept. What the token still gates
                // is choosing a password -- see acceptInvitation(), which
                // reads must_change_password rather than this column.
                'accepted_at' => now(),

                'created_by' => $user->id,
            ]);

            return $invitation->load(['company', 'role', 'creator', 'user']);
        });

        $this->sendInvitationMail($invitation);

        return $invitation;
    }

    /**
     * Send the invitation again to someone who never got it, or lost it.
     *
     * The account already exists — the invite flow creates it up front — so
     * this mints a fresh token, extends the invitation window and
     * re-delivers the same email.
     *
     * Only for people who have not chosen a password yet. Once someone has
     * one of their own, resending would silently lock them out; they want a
     * password reset, not another invitation.
     */
    public function resendInvitation(User $actor, string $userUuid): Invitation
    {
        $invitedUser = User::where('company_id', $actor->company_id)
            ->where('uuid', $userUuid)
            ->first();

        if (! $invitedUser) {
            throw ValidationException::withMessages([
                'uuid' => ['No such user in your company.'],
            ]);
        }

        if (! $invitedUser->must_change_password) {
            throw ValidationException::withMessages([
                'uuid' => ['This user has already set their own password. Ask them to use "Forgot password" instead.'],
            ]);
        }

        $invitation = Invitation::where('user_id', $invitedUser->id)
            ->latest('id')
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages([
                'uuid' => ['There is no invitation on record for this user.'],
            ]);
        }

        DB::transaction(function () use ($invitedUser, $invitation) {

            // Rotating this invalidates anything the old link could have set
            // up, and keeps the account unusable until the new link is used.
            $invitedUser->update([
                'password' => $this->unusablePassword(),
                'must_change_password' => true,
            ]);

            // Any session opened before the resend goes too.
            $invitedUser->tokens()->delete();

            $invitation->update([
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7),
            ]);
        });

        $invitation->load(['company', 'role', 'creator', 'user']);

        // Unlike the first send, a failure here has to reach the caller. The
        // admin pressed "Resend" precisely because delivery is in doubt, and
        // a success toast over a bounced email is worse than no button.
        if (! $this->sendInvitationMail($invitation)) {
            throw ValidationException::withMessages([
                'email' => ['We could not deliver the invitation email. Please try again shortly.'],
            ]);
        }

        return $invitation;
    }

    /**
     * A password nobody holds. The invitee sets a real one through the link;
     * until then there is no credential in existence that opens this account,
     * which is the whole point of not mailing one.
     */
    protected function unusablePassword(): string
    {
        return Hash::make(Str::random(64));
    }

    /**
     * Where the invitee lands to choose their password.
     */
    protected function acceptUrl(Invitation $invitation): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/accept-invitation?token='.urlencode($invitation->token);
    }

    /**
     * Delivery failures must not roll back the account that was just created,
     * so this runs outside the transaction.
     *
     * Returns whether the message actually went out, for callers that need to
     * report a failure rather than only log it.
     */
    protected function sendInvitationMail(Invitation $invitation): bool
    {
        $delivered = true;

        $acceptUrl = $this->acceptUrl($invitation);

        try {
            Mail::to($invitation->email)
                ->send(new InvitationMail($invitation, $acceptUrl));
        } catch (\Exception $e) {
            $delivered = false;

            Log::error('Invitation email failed', [
                'email' => $invitation->email,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment('local')) {
            Log::info('================ Invitation Email ================');
            Log::info('To Email', ['email' => $invitation->email]);
            Log::info('Accept URL', ['url' => $acceptUrl]);
            Log::info('Invitation Data', [
                'uuid' => $invitation->uuid,
                'company' => $invitation->company->company_name,
                'role' => $invitation->role->name,
            ]);
            Log::info('==================================================');
        }

        return $delivered;
    }

    /**
     * Consume an invitation link: set the password on the account the invite
     * already created, and sign them in.
     *
     * The gate is the user's own must_change_password flag rather than the
     * invitation's accepted_at, which is stamped at creation time because the
     * account is provisioned up front. Once a password is chosen the flag
     * clears and the link stops working on its own.
     */
    public function acceptInvitation(array $data)
    {
        $invitation = Invitation::with([
            'role',
            'company',
            'user',
        ])
            ->where('token', $data['token'])
            ->first();

        if (! $invitation || ! $invitation->user) {
            throw ValidationException::withMessages([
                'token' => ['Invalid invitation token.'],
            ]);
        }

        if ($invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => ['This invitation has expired. Ask your administrator to send a new one.'],
            ]);
        }

        if (! $invitation->user->must_change_password) {
            throw ValidationException::withMessages([
                'token' => ['This invitation has already been used. Use "Forgot password" if you need to get back in.'],
            ]);
        }

        return DB::transaction(function () use ($invitation, $data) {

            $user = $invitation->user;

            $user->update([
                'password' => Hash::make($data['password']),
                'must_change_password' => false,
            ]);

            // Burn the link. A second visit gets the "already used" message
            // above rather than a second chance at the password.
            $invitation->update([
                'token' => Str::random(64),
                'accepted_at' => now(),
            ]);

            // Nothing opened before this moment stays open.
            $user->tokens()->delete();

            $token = $user->createToken('broker-api')->plainTextToken;

            return [
                'token' => $token,
                'user' => $user->load('company.subscription', 'roles.permissions', 'permissions'),
            ];
        });
    }
}
