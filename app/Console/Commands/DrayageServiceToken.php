<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnsureBrokerUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A read-only API token for a service that reads the drayage directory -
 * Fleetra, today.
 *
 * The token belongs to a dedicated service account: no company, no role, a
 * random password nobody holds, an address on the .invalid TLD so nothing
 * can ever be mailed to it, and read-drayage-directory as its only
 * permission. The token itself carries only the drayage:read ability, which
 * EnsureBrokerUser confines to the drayage read routes.
 *
 * The plain token is printed once. Running it again issues another; pass
 * --revoke to delete every token the account holds.
 *
 *   php artisan drayage:service-token
 *   php artisan drayage:service-token --name=fleetra --revoke
 */
class DrayageServiceToken extends Command
{
    protected $signature = 'drayage:service-token
        {--name=fleetra : Service name, used for the account and the token}
        {--revoke : Delete the service account\'s tokens instead of issuing one}';

    protected $description = 'Issue a read-only drayage API token for a service such as Fleetra';

    public function handle(): int
    {
        $name = Str::slug($this->option('name'));
        $email = "{$name}-drayage@service.dollartraq.invalid";

        if (! Permission::where('name', 'read-drayage-directory')->where('guard_name', 'web')->exists()) {
            $this->components->error('Permission read-drayage-directory does not exist yet. Run: php artisan db:seed --class=RolePermissionSeeder');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($this->option('revoke')) {
            $count = $user ? $user->tokens()->delete() : 0;
            $this->components->info("Deleted {$count} token(s) for {$name}.");

            return self::SUCCESS;
        }

        $user ??= User::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => null,
            'first_name' => Str::title($name),
            'last_name' => 'service',
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => false,
        ]);

        $user->syncPermissions(['read-drayage-directory']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $token = $user->createToken("{$name}-drayage", [EnsureBrokerUser::DRAYAGE_READ])->plainTextToken;

        $this->components->info("Token for {$name} (shown once):");
        $this->line($token);
        $this->newLine();
        $this->line('Send it as: Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
