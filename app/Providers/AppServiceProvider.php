<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

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
         | FMCSA filings come from the external carrier database. Bound to an
         | interface so a test can stand in for it — reading that host from the
         | suite would be reading production on every run.
         */
        $this->app->bind(
            \App\Services\Coi\CarrierFilingLookup::class,
            \App\Services\Coi\DatabaseCarrierFilingLookup::class,
        );

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
        /*
         | SendGrid over its HTTP API instead of SMTP.
         |
         | Every outbound port SMTP needs (25, 465, 587) is blocked on this
         | network - confirmed directly: all three time out, while HTTPS to
         | SendGrid itself returns a normal response. The SMTP mailer was
         | never going to work here no matter how MAIL_HOST/MAIL_PORT were
         | set, because the block is on the port, not the destination. The
         | API transport sends the exact same mail through the exact same
         | SendGrid account, just over 443 like any other HTTPS request this
         | app already makes - see the 'sendgrid_api' mailer in
         | config/mail.php.
         |
         | Laravel wires up Mailgun, Postmark, SES and a few others by
         | default but not SendGrid, even with the bridge package installed,
         | so the transport has to be registered by hand here.
         */
        Mail::extend('sendgrid_api', function (array $config) {
            return (new SendgridTransportFactory)->create(
                new Dsn('sendgrid+api', 'default', $config['key'] ?? null)
            );
        });

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
