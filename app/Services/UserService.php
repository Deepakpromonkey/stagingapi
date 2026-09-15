<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    /**
     * The company's team.
     *
     * `per_page` is capped — the list screen offers 10/25/50/100 and there is
     * no reason to let a hand-written query pull the whole table.
     */
    public function list($user, ?int $perPage = null)
    {
        $perPage = min(max((int) ($perPage ?: 10), 1), 100);

        return User::with('roles')
            ->where('company_id', $user->company_id)
            ->orderBy('first_name')
            ->paginate($perPage);
    }

    /**
     * Change a teammate's seat and contact details.
     *
     * The guards mirror the carrier portal: you cannot re-seat yourself, you
     * cannot touch the account owner unless you are them, and the role has to
     * be one you are actually allowed to hand out.
     */
    public function update(User $actor, string $uuid, array $data): User
    {
        $target = $this->findTeammate($actor, $uuid);

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

            $roleChanged = false;

            if (isset($data['role_id'])) {
                $role = $this->roleService->resolveAssignable($actor, $data['role_id']);

                $roleChanged = $target->role()?->id !== $role->id;

                // One seat per user — sync rather than assign, so the old role
                // is dropped instead of a second one stacking on top.
                $target->syncRoles([$role]);
            }

            $target->fill(
                collect($data)
                    ->only([
                        'first_name',
                        'last_name',
                        'phone',
                        'country_code',
                        'designation',
                        'status',
                    ])
                    ->toArray()
            )->save();

            // A seat change alters what the person may do, and switching them
            // off should take effect now rather than whenever their token
            // happens to expire. Either way, drop the sessions.
            if ($roleChanged || (array_key_exists('status', $data) && ! $data['status'])) {
                $target->tokens()->delete();
            }

            return $target->fresh()->load('roles.permissions');
        });
    }

    /**
     * Remove a teammate.
     *
     * Soft delete: the loads they booked, the payments they released and the
     * invitations they sent all keep pointing at a real row, but the person
     * disappears from the team list and can no longer authenticate.
     */
    public function delete(User $actor, string $uuid): User
    {
        $target = $this->findTeammate($actor, $uuid);

        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'uuid' => ['You cannot delete your own account.'],
            ]);
        }

        if ($target->is_owner) {
            throw ValidationException::withMessages([
                'uuid' => ['The account owner cannot be deleted.'],
            ]);
        }

        return DB::transaction(function () use ($target) {

            // Cut the session first. Deleting the row on its own would leave a
            // live bearer token sitting in their browser until it expired.
            $target->tokens()->delete();

            // `users.email` is uniquely indexed at the database level, and that
            // index does not know about soft deletes — so the row we are about
            // to hide would go on reserving the address forever, and someone
            // removed by mistake could never be invited back. Tombstone it
            // instead: the original is still legible in the value, and the
            // live address is released.
            $target->email = Str::limit('deleted+' . $target->id . '+' . $target->email, 255, '');

            $target->save();

            $target->delete();

            return $target;
        });
    }

    /**
     * Resolve a uuid to a user inside the actor's own company.
     *
     * @throws ValidationException
     */
    protected function findTeammate(User $actor, string $uuid): User
    {
        $target = User::with('roles')
            ->where('company_id', $actor->company_id)
            ->where('uuid', $uuid)
            ->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'uuid' => ['No such user in your company.'],
            ]);
        }

        return $target;
    }
}