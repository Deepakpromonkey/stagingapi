<?php

namespace App\Services\Carrier;

use App\Models\CarrierUser;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Seats on the carrier side. The broker equivalent is RoleService; both read
 * their rules from config, and both are scoped to their own Spatie guard so
 * neither can hand out the other's roles.
 */
class CarrierRoleService
{
    protected function guard(): string
    {
        return config('carrier_rbac.guard');
    }

    /**
     * Every active carrier seat, least to most senior.
     */
    public function all(): Collection
    {
        return Role::active()
            ->where('guard_name', $this->guard())
            ->with('permissions')
            ->orderBy('level')
            ->get();
    }

    /**
     * Seats this person may hand out. Only the owner may seat anyone.
     */
    public function assignableBy(CarrierUser $carrierUser): Collection
    {
        $slugs = $this->assignableSlugs($carrierUser);

        if (empty($slugs)) {
            return Role::query()->whereRaw('1 = 0')->get();
        }

        return Role::active()
            ->where('guard_name', $this->guard())
            ->slugs($slugs)
            ->with('permissions')
            ->orderBy('level')
            ->get();
    }

    public function assignableSlugs(CarrierUser $carrierUser): array
    {
        return config('carrier_rbac.assignable.'.$carrierUser->roleSlug(), []);
    }

    public function canAssign(CarrierUser $carrierUser, Role $role): bool
    {
        return in_array($role->slug, $this->assignableSlugs($carrierUser), true);
    }

    /**
     * Resolve a role id to a seat the caller is actually allowed to assign.
     *
     * @throws ValidationException
     */
    public function resolveAssignable(CarrierUser $carrierUser, int|string $roleId): Role
    {
        $role = Role::active()
            ->where('guard_name', $this->guard())
            ->find($roleId);

        if (! $role || ! $this->canAssign($carrierUser, $role)) {
            throw ValidationException::withMessages([
                'role_id' => ['You are not allowed to assign this role.'],
            ]);
        }

        return $role;
    }

    public function findBySlug(string $slug): ?Role
    {
        return Role::where('slug', $slug)
            ->where('guard_name', $this->guard())
            ->first();
    }

    /**
     * The seat a carrier owner gets when their account is provisioned.
     */
    public function ownerRole(): ?Role
    {
        return $this->findBySlug(config('carrier_rbac.owner_role'));
    }
}
