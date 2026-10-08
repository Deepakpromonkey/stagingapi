<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Models\CarrierConnectRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Observers\CarrierConnectRequestObserver;
use App\Observers\ShipmentObserver;
use App\Services\Ops\OpsAlert;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        /*
         | The drayage directory decodes its search index once per request:
         | scoped, so the list endpoint's search, facets and export share one
         | copy, and a queue worker or Octane process starts each job clean.
         */
        $this->app->scoped(\App\Services\Drayage\DrayageDirectoryService::class);

        /*
         | One DT score calculator per request or queued job, so the national
         | benchmarks it reads are fetched once per request rather than once
         | per carrier, and never outlive the request that read them.
         */
        $this->app->scoped(\App\Services\DtScore\DtScore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Live updates over Reverb, in place of the pages polling for them.
        Shipment::observe(ShipmentObserver::class);
        CarrierConnectRequest::observe(CarrierConnectRequestObserver::class);

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

        $this->registerOpsAlerts();

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

    /**
     * Failed jobs and scheduled tasks, and a health check that means it.
     * See config/ops.php.
     */
    private function registerOpsAlerts(): void
    {
        /*
         | GET /up is what the watcher polls. Out of the box it only proves PHP
         | boots; with these it also proves both databases answer, so a dead
         | MySQL shows as down instead of a healthy 200.
         */
        Event::listen(DiagnosingHealth::class, function () {
            DB::connection()->getPdo();
            DB::connection('external_db')->getPdo();
        });

        // A job that has used up its retries - each attempt's exception has
        // already been reported, this says it was given up on.
        Event::listen(JobFailed::class, function (JobFailed $event) {
            app(OpsAlert::class)->send(OpsAlert::CRITICAL, 'Background job failed: '.class_basename($event->job->resolveName()), [
                'Job' => $event->job->resolveName(),
                'Queue' => $event->job->getQueue(),
                'Error' => \Illuminate\Support\Str::limit(class_basename($event->exception).': '.$event->exception->getMessage(), 500),
            ], 'job|'.$event->job->resolveName().'|'.get_class($event->exception));
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $command = trim(\Illuminate\Support\Str::after((string) $event->task->command, 'artisan')) ?: $event->task->getSummaryForDisplay();

            app(OpsAlert::class)->send(OpsAlert::CRITICAL, 'Scheduled task failed: '.\Illuminate\Support\Str::limit(trim($command, "' "), 60), [
                'Task' => $event->task->getSummaryForDisplay(),
                'Error' => \Illuminate\Support\Str::limit(class_basename($event->exception).': '.$event->exception->getMessage(), 500),
            ], 'schedule|'.$event->task->getSummaryForDisplay());
        });
    }
}
