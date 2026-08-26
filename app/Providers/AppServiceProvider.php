<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Abilities whose answer comes from the per-user override capability
     * column when it is set, rather than from the role's permissions.
     */
    protected array $capabilityAbilities = [
        'override-soft-gate' => 'can_override_soft',
        'override-knockout-gate-dual-control' => 'can_override_gate',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         | The Anthropic client reads ANTHROPIC_API_KEY off the process
         | environment when constructed bare, which is not the same thing as
         | the application's .env once config is cached — php-fpm does not
         | necessarily carry it. Bound here so the key comes from config like
         | every other credential in the application.
         */
        $this->app->singleton(AnthropicClient::class, function () {
            return new AnthropicClient(apiKey: config('services.anthropic.key'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Override capability is granted independently of the seat type: a
        // user-level value wins, otherwise the check falls through to the
        // role's permissions.
        Gate::before(function ($user, string $ability) {

            if (! $user instanceof User) {
                return null;
            }

            $column = $this->capabilityAbilities[$ability] ?? null;

            if ($column === null) {
                return null;
            }

            $override = $user->getAttributes()[$column] ?? null;

            return $override === null ? null : (bool) $override;
        });
    }
}
