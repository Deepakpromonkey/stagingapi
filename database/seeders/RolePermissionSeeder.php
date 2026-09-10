<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the broker permission matrix defined in config/rbac.php into the
 * database. Safe to run repeatedly.
 */
class RolePermissionSeeder extends Seeder
{
    protected string $guard = 'web';

    public function run(): void
    {
        $this->createPermissions();

        $this->syncRoles();

        $this->retireLegacyRoles();

        $this->backfillOwners();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Every permission across all groups in the matrix.
     */
    protected function createPermissions(): void
    {
        foreach ($this->allPermissionNames() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->guard,
            ]);
        }
    }

    protected function syncRoles(): void
    {
        foreach (config('rbac.roles') as $slug => $definition) {

            $role = Role::where('slug', $slug)->first()
                ?? Role::where('name', $definition['name'])->where('guard_name', $this->guard)->first()
                ?? $this->claimLegacyRole($slug)
                ?? new Role;

            $role->forceFill([
                'slug' => $slug,
                'name' => $definition['name'],
                'guard_name' => $this->guard,
                'description' => $definition['description'],
                'level' => $definition['level'],
                'can_override_soft' => $definition['can_override_soft'],
                'can_override_gate' => $definition['can_override_gate'],
                'payment_release_limit' => $definition['payment_release_limit'],
                'is_active' => true,
            ])->save();

            $role->syncPermissions($definition['permissions']);

            $this->command?->info("Role synced: {$definition['name']} ({$slug})");
        }
    }

    /**
     * Reuse a legacy role row so existing user assignments survive the rename
     * (e.g. "Company Admin" becomes "Owner/Admin").
     */
    protected function claimLegacyRole(string $slug): ?Role
    {
        $legacyName = array_search($slug, config('rbac.legacy_role_map', []), true);

        if ($legacyName === false) {
            return null;
        }

        return Role::where('name', $legacyName)
            ->where('guard_name', $this->guard)
            ->whereNull('slug')
            ->first();
    }

    /**
     * Roles outside the matrix are deactivated (not deleted) unless nothing
     * references them, so historic assignments keep resolving.
     */
    protected function retireLegacyRoles(): void
    {
        $legacy = Role::whereNull('slug')->get();

        foreach ($legacy as $role) {

            $inUse = DB::table('model_has_roles')->where('role_id', $role->id)->exists()
                || DB::table('invitations')->where('role_id', $role->id)->exists();

            if ($inUse) {
                $role->forceFill(['is_active' => false])->save();

                $this->command?->warn("Legacy role kept but deactivated: {$role->name}");

                continue;
            }

            $role->delete();

            $this->command?->warn("Legacy role removed: {$role->name}");
        }
    }

    /**
     * Company creators predating the RBAC rollout have no seat assigned.
     */
    protected function backfillOwners(): void
    {
        $ownerRole = Role::where('slug', config('rbac.owner_role'))->first();

        if (! $ownerRole) {
            return;
        }

        User::where('is_owner', true)
            ->doesntHave('roles')
            ->get()
            ->each(function (User $user) use ($ownerRole) {
                $user->assignRole($ownerRole);

                $this->command?->info("Owner seat assigned to: {$user->email}");
            });
    }

    protected function allPermissionNames(): array
    {
        return collect(config('rbac.permissions'))
            ->flatMap(fn (array $group) => array_keys($group))
            ->unique()
            ->values()
            ->all();
    }
}
