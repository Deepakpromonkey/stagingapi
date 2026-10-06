<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a DollarTraq staff account the drayage administration permissions.
 *
 * Imports and rollback are platform operations, not something any broker
 * company's seat should carry, so manage-drayage-directory is in no role
 * and is granted to named accounts here instead. Export comes with it.
 *
 *   php artisan drayage:grant ops@promonkey.tech
 *   php artisan drayage:grant ops@promonkey.tech --revoke
 */
class DrayageGrant extends Command
{
    protected $signature = 'drayage:grant {email} {--revoke : Remove the permissions instead}';

    protected $description = 'Grant (or revoke) drayage import/rollback rights for a staff account';

    private const PERMISSIONS = ['manage-drayage-directory', 'export-drayage-directory'];

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error('No broker user has that email address.');

            return self::FAILURE;
        }

        foreach (self::PERMISSIONS as $name) {
            if (! Permission::where('name', $name)->where('guard_name', 'web')->exists()) {
                $this->components->error("Permission {$name} does not exist yet. Run: php artisan db:seed --class=RolePermissionSeeder");

                return self::FAILURE;
            }
        }

        $this->option('revoke')
            ? $user->revokePermissionTo(self::PERMISSIONS)
            : $user->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->components->info(($this->option('revoke') ? 'Revoked from ' : 'Granted to ').'user #'.$user->id.'.');

        return self::SUCCESS;
    }
}
