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
     * Create the invited user straight away and email them a temporary
     * password. They must change it before they can use anything else.
     */
    public function sendInvitation(array $data, $user)
    {
        // Re-check assignability at the service boundary so the rule holds
        // even when the invite is created outside the HTTP request.
        $role = $this->roleService->resolveAssignable($user, $data['role_id']);

        // Letters and digits only — this gets typed by hand from an email.
        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false, spaces: false);

        $invitation = DB::transaction(function () use ($data, $user, $role, $temporaryPassword) {

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
                'password' => Hash::make($temporaryPassword),
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
                // left for the invitee to accept.
                'accepted_at' => now(),

                'created_by' => $user->id,
            ]);

            return $invitation->load(['company', 'role', 'creator', 'user']);
        });

        $this->sendInvitationMail($invitation, $temporaryPassword);

        return $invitation;
    }

    /**
     * Send the invitation again to someone who never got it, or lost it.
     *
     * The account already exists — the invite flow creates it up front — so
     * this mints a fresh temporary password, extends the invitation window
     * and re-delivers the same email.
     *
     * Only for people still sitting on the temporary password. Once someone
     * has chosen their own, resending would silently overwrite it; they want
     * a password reset, not another invitation.
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

        // Same alphabet as the original invite — this gets typed by hand out
        // of an email, so no symbols.
        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false, spaces: false);

        DB::transaction(function () use ($invitedUser, $invitation, $temporaryPassword) {

            $invitedUser->update([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ]);

            // The old temporary password is dead now, so any session opened
            // with it goes too.
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
        if (! $this->sendInvitationMail($invitation, $temporaryPassword)) {
            throw ValidationException::withMessages([
                'email' => ['We could not deliver the invitation email. Please try again shortly.'],
            ]);
        }

        return $invitation;
    }

    /**
     * Delivery failures must not roll back the account that was just created,
     * so this runs outside the transaction.
     *
     * Returns whether the message actually went out, for callers that need to
     * report a failure rather than only log it.
     */
    protected function sendInvitationMail(Invitation $invitation, string $temporaryPassword): bool
    {
        $delivered = true;

        try {
            Mail::to($invitation->email)
                ->send(new InvitationMail($invitation, $temporaryPassword));
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
            Log::info('Temporary Password', ['password' => $temporaryPassword]);
            Log::info('Invitation Data', [
                'uuid' => $invitation->uuid,
                'company' => $invitation->company->company_name,
                'role' => $invitation->role->name,
            ]);
            Log::info('==================================================');
        }

        return $delivered;
    }

    public function acceptInvitation(array $data)
    {
        $invitation = Invitation::with([
            'role',
            'company',
        ])
            ->where('token', $data['token'])
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages([
                'token' => ['Invalid invitation token.'],
            ]);
        }

        if ($invitation->accepted_at) {
            throw ValidationException::withMessages([
                'token' => ['Invitation already accepted.'],
            ]);
        }

        if ($invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => ['Invitation has expired.'],
            ]);
        }

        return DB::transaction(function () use ($invitation, $data) {

            $user = User::create([
                'uuid' => Str::uuid(),
                'company_id' => $invitation->company_id,
                'first_name' => $invitation->first_name,
                'last_name' => $invitation->last_name,
                'phone' => $invitation->phone,
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
                'is_owner' => false,
                'status' => true,

                // Carry the invite-time override capability onto the user.
                'can_override_soft' => $invitation->can_override_soft,
                'can_override_gate' => $invitation->can_override_gate,
            ]);

            $user->assignRole($invitation->role);

            $invitation->update([
                'accepted_at' => now(),
            ]);

            $token = $user->createToken('broker-api')->plainTextToken;

            return [
                'token' => $token,
                'user' => $user->load('company.subscription', 'roles.permissions', 'permissions'),
            ];
        });
    }
}
