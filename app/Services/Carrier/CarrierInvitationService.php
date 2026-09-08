<?php

namespace App\Services\Carrier;

use App\Mail\CarrierInvitationMail;
use App\Models\CarrierInvitation;
use App\Models\CarrierUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A carrier owner bringing their own dispatchers and drivers into the portal.
 *
 * Works exactly like the broker invitation flow: the login is created straight
 * away and the temporary password is emailed, so there is no accept step and
 * nothing to expire. The invitation row is the audit trail of who seated whom.
 */
class CarrierInvitationService
{
    public function __construct(
        protected CarrierRoleService $carrierRoleService
    ) {}

    /**
     * Invite someone into the caller's carrier account.
     */
    public function invite(array $data, CarrierUser $invitedBy): CarrierInvitation
    {
        // Re-checked at the service boundary so the rule holds even when an
        // invite is created outside the HTTP request.
        $role = $this->carrierRoleService->resolveAssignable($invitedBy, $data['role_id']);

        if (! $invitedBy->carrier_company_id) {
            throw ValidationException::withMessages([
                'email' => ['Your account is not linked to a carrier yet.'],
            ]);
        }

        // Letters and digits only — this gets typed by hand out of an email,
        // often on a phone in a truck.
        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false, spaces: false);

        $invitation = DB::transaction(function () use ($data, $invitedBy, $role, $temporaryPassword) {

            $account = $invitedBy->carrierCompany;

            $invitedUser = CarrierUser::create([
                'uuid' => Str::uuid(),
                'carrier_company_id' => $account->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,

                // Snapshot of the carrier, same as the owner's row carries.
                'legal_name' => $account->legal_name,
                'dot_number' => $account->dot_number,

                'status' => true,

                // Only onboarding creates owners. An invited user is a member,
                // whatever seat they are given.
                'is_owner' => false,

                'invited_by' => $invitedBy->id,
            ]);

            $invitedUser->assignRole($role);

            return CarrierInvitation::create([
                'uuid' => Str::uuid(),
                'carrier_company_id' => $account->id,
                'carrier_user_id' => $invitedUser->id,
                'role_id' => $role->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => strtolower(trim($data['email'])),
                'token' => Str::random(64),
                'expires_at' => now()->addDays((int) config('carrier_rbac.invitation_lifetime_days')),

                // The account exists from this moment, so there is nothing
                // left for the invitee to accept.
                'accepted_at' => now(),

                'created_by' => $invitedBy->id,
            ])->load(['carrierCompany', 'role', 'creator', 'carrierUser']);
        });

        $this->sendInvitationMail($invitation, $temporaryPassword);

        return $invitation;
    }

    /**
     * Delivery failures must not roll back the account that was just created,
     * so this runs outside the transaction.
     */
    protected function sendInvitationMail(CarrierInvitation $invitation, string $temporaryPassword): void
    {
        $portalUrl = rtrim((string) config('carrier_connect.portal_url'), '/').'/login';

        try {
            Mail::to($invitation->email)
                ->send(new CarrierInvitationMail($invitation, $temporaryPassword, $portalUrl));
        } catch (\Throwable $e) {
            Log::error('Carrier invitation email failed', [
                'email' => $invitation->email,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment('local')) {
            Log::info('============ Carrier Invitation ============');
            Log::info('To Email', ['email' => $invitation->email]);
            Log::info('Temporary Password', ['password' => $temporaryPassword]);
            Log::info('Invitation', [
                'uuid' => $invitation->uuid,
                'carrier' => $invitation->carrierCompany?->displayName(),
                'role' => $invitation->role->name,
            ]);
            Log::info('============================================');
        }
    }

    /**
     * Everyone inside the caller's carrier account.
     */
    public function listUsers(CarrierUser $carrierUser, int $perPage = 15)
    {
        return CarrierUser::with('roles')
            ->where('carrier_company_id', $carrierUser->carrier_company_id)
            ->orderByDesc('is_owner')
            ->orderBy('first_name')
            ->paginate($perPage);
    }

    /**
     * Change a colleague's seat, contact details or access.
     *
     * The owner's own seat is not editable here — demoting the only owner
     * would leave the account with nobody able to sign or get paid.
     */
    public function updateUser(CarrierUser $actor, string $uuid, array $data): CarrierUser
    {
        $target = CarrierUser::with('roles')
            ->where('carrier_company_id', $actor->carrier_company_id)
            ->where('uuid', $uuid)
            ->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'uuid' => ['No such user in your carrier account.'],
            ]);
        }

        if ($target->is_owner && $target->id !== $actor->id) {
            throw ValidationException::withMessages([
                'uuid' => ['The account owner cannot be edited by another user.'],
            ]);
        }

        if ($target->id === $actor->id && array_key_exists('role_id', $data)) {
            throw ValidationException::withMessages([
                'role_id' => ['You cannot change your own role.'],
            ]);
        }

        if ($target->id === $actor->id && array_key_exists('status', $data)) {
            throw ValidationException::withMessages([
                'status' => ['You cannot disable your own account.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $data) {

            if (isset($data['role_id'])) {
                $role = $this->carrierRoleService->resolveAssignable($actor, $data['role_id']);

                $target->syncRoles([$role]);
            }

            $target->fill(
                collect($data)->only(['first_name', 'last_name', 'phone', 'status'])->toArray()
            )->save();

            // Switching someone off ends their session immediately rather than
            // at the end of whatever they are doing.
            if (array_key_exists('status', $data) && ! $data['status']) {
                $target->tokens()->delete();
            }

            return $target->fresh()->load('roles.permissions', 'carrierCompany');
        });
    }
}
