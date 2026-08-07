<?php

namespace Database\Seeders;

use App\Models\CarrierUser;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the carrier permission matrix defined in config/carrier_rbac.php into
 * the database, under the `carrier` guard. Safe to run repeatedly.
 *
 * The broker matrix is seeded separately by RolePermissionSeeder; the guard is
 * what keeps the two apart in the shared roles / permissions tables.
 */
class CarrierRolePermissionSeeder extends Seeder
{
    protected string $guard;

    public function __construct()
    {
        $this->guard = config('carrier_rbac.guard');
    }

    public function run(): void
    {
        $this->createPermissions();

        $this->syncRoles();

        $this->backfillOwners();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

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
        foreach (config('carrier_rbac.roles') as $slug => $definition) {

            $role = Role::where('slug', $slug)->first() ?? new Role;

            $role->forceFill([
                'slug' => $slug,
                'name' => $definition['name'],
                'guard_name' => $this->guard,
                'description' => $definition['description'],
                'level' => $definition['level'],
                // Broker-only risk columns on the shared table. A carrier seat
                // has no say over broker gating.
                'can_override_soft' => false,
                'can_override_gate' => false,
                'payment_release_limit' => null,
                'is_active' => true,
            ])->save();

            $role->syncPermissions($definition['permissions']);

            $this->command?->info("Carrier role synced: {$definition['name']} ({$slug})");
        }
    }

    /**
     * Portal accounts that predate the carrier matrix hold no seat. Each one
     * was the whole carrier, so each one is an owner.
     */
    protected function backfillOwners(): void
    {
        $ownerRole = Role::where('slug', config('carrier_rbac.owner_role'))->first();

        if (! $ownerRole) {
            return;
        }

        CarrierUser::where('is_owner', true)
            ->doesntHave('roles')
            ->get()
            ->each(function (CarrierUser $carrierUser) use ($ownerRole) {
                $carrierUser->assignRole($ownerRole);

                $this->command?->info("Carrier owner seat assigned to: {$carrierUser->email}");
            });
    }

    protected function allPermissionNames(): array
    {
        return collect(config('carrier_rbac.permissions'))
            ->flatMap(fn (array $group) => array_keys($group))
            ->unique()
            ->values()
            ->all();
    }
}
