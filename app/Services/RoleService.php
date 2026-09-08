<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RoleService
{
    /**
     * Spatie guard the broker matrix lives under. Carrier seats sit in the
     * `carrier` guard in the same table and must never surface here.
     */
    protected string $guard = 'web';

    /**
     * All active seat types, ordered from least to most senior.
     */
    public function all(): Collection
    {
        return Role::active()
            ->where('guard_name', $this->guard)
            ->with('permissions')
            ->orderBy('level')
            ->get();
    }

    /**
     * Roles the given user is allowed to hand out when inviting or editing a
     * user. Compliance Managers can manage Viewer / Agent / Senior Agent;
     * only an Owner/Admin can create another Owner/Admin or Compliance Manager.
     */
    public function assignableBy(User $user): Collection
    {
        $slugs = $this->assignableSlugs($user);

        if (empty($slugs)) {
            return Role::query()->whereRaw('1 = 0')->get();
        }

        return Role::active()
            ->where('guard_name', $this->guard)
            ->slugs($slugs)
            ->with('permissions')
            ->orderBy('level')
            ->get();
    }

    public function assignableSlugs(User $user): array
    {
        return config('rbac.assignable.'.$user->roleSlug(), []);
    }

    public function canAssign(User $user, Role $role): bool
    {
        return in_array($role->slug, $this->assignableSlugs($user), true);
    }

    /**
     * Resolve a role id to a role the user is actually allowed to assign.
     *
     * @throws ValidationException
     */
    public function resolveAssignable(User $user, int|string $roleId): Role
    {
        $role = Role::active()
            ->where('guard_name', $this->guard)
            ->find($roleId);

        if (! $role || ! $this->canAssign($user, $role)) {
            throw ValidationException::withMessages([
                'role_id' => ['You are not allowed to assign this role.'],
            ]);
        }

        return $role;
    }

    public function findBySlug(string $slug): ?Role
    {
        return Role::where('slug', $slug)->first();
    }
}
