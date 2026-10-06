<?php

namespace Tests\Feature;

use App\Jobs\NotifyTeamsOfAuditEvent;
use App\Services\AuditLog;
use App\Services\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Posting the audit trail to a Teams channel: that it is queued rather than
 * sent on the request, that the card carries no credentials, and that a
 * channel which is down or unconfigured costs nothing.
 */
class TeamsAuditAlertTest extends TestCase
{
    // Both traits define migrateFreshUsing(); ours is the one that keeps the
    // MySQL-only migrations out of the sqlite run.
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const WEBHOOK = 'https://prod-00.westus.logic.azure.com/workflows/test-only/triggers/manual/paths/invoke';

    private function configureChannel(array $overrides = []): void
    {
        config(array_merge([
            'services.teams.webhook_url' => self::WEBHOOK,
            'services.teams.enabled' => true,
            'services.teams.timeout' => 5,
            'services.teams.events' => ['*'],
        ], $overrides));
    }

    public function test_nothing_is_sent_when_no_channel_is_configured(): void
    {
        config(['services.teams.webhook_url' => null]);

        Queue::fake();

        AuditLog::record(AuditLog::LOGIN_SUCCEEDED);

        Queue::assertNothingPushed();
        $this->assertFalse(TeamsNotifier::enabled());
    }

    public function test_the_alert_is_queued_rather_than_sent_on_the_request(): void
    {
        $this->configureChannel();

        Queue::fake();

        AuditLog::record(AuditLog::LOGIN_SUCCEEDED);

        // Queued, and on its own queue so it cannot delay a user-facing job.
        Queue::assertPushed(NotifyTeamsOfAuditEvent::class, function ($job) {
            return $job->queue === 'audit' && $job->connection === 'database';
        });
    }

    public function test_every_event_is_posted_by_default_including_sign_ins(): void
    {
        $this->configureChannel();

        $this->assertTrue(TeamsNotifier::shouldPost(AuditLog::LOGIN_SUCCEEDED));
        $this->assertTrue(TeamsNotifier::shouldPost(AuditLog::LOGOUT));
        $this->assertTrue(TeamsNotifier::shouldPost(AuditLog::USER_REMOVED));
    }

    public function test_the_channel_can_be_narrowed_without_a_code_change(): void
    {
        $this->configureChannel([
            'services.teams.events' => ['login.failed', 'user.removed'],
        ]);

        $this->assertTrue(TeamsNotifier::shouldPost(AuditLog::LOGIN_FAILED));
        $this->assertTrue(TeamsNotifier::shouldPost(AuditLog::USER_REMOVED));

        // Quietened, but still recorded in the trail.
        $this->assertFalse(TeamsNotifier::shouldPost(AuditLog::LOGIN_SUCCEEDED));
    }

    public function test_the_card_is_an_adaptive_card_in_the_envelope_workflows_expects(): void
    {
        $this->configureChannel();

        Http::fake([self::WEBHOOK => Http::response('', 202)]);

        (new TeamsNotifier)->post(
            AuditLog::ROLE_CHANGED,
            'Sam Okafor (sam@northwind.test)',
            'Ada Brown (ada@northwind.test)',
            ['from_role' => 'Agent', 'to_role' => 'Compliance Manager', 'ip' => '203.0.113.9'],
            'Mon, Sep 29, 2026 2:20 PM'
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame('message', $body['type']);

            $attachment = $body['attachments'][0];
            $this->assertSame(
                'application/vnd.microsoft.card.adaptive',
                $attachment['contentType']
            );
            $this->assertSame('AdaptiveCard', $attachment['content']['type']);

            // A role change should read as a sentence, not as an event name.
            $this->assertSame('Role changed', $attachment['content']['body'][0]['text']);

            $facts = collect($attachment['content']['body'][1]['facts'])
                ->pluck('value', 'title');

            $this->assertSame('Sam Okafor (sam@northwind.test)', $facts['By']);
            $this->assertSame('Ada Brown (ada@northwind.test)', $facts['Account']);
            $this->assertSame('Compliance Manager', $facts['To Role']);

            return true;
        });
    }

    public function test_a_credential_can_never_reach_the_channel(): void
    {
        $this->configureChannel();

        Http::fake([self::WEBHOOK => Http::response('', 202)]);

        // Sent the way the app sends it: through AuditLog, which strips
        // credentials before anything is stored or announced.
        AuditLog::record(AuditLog::LOGIN_FAILED, null, null, [
            'email' => 'driver@example.com',
            'password' => 'hunter2',
            'otp_code' => '482915',
            'token' => 'sanctum-plain-text-token',
        ]);

        $entry = Activity::query()->latest('id')->first();

        (new TeamsNotifier)->post(
            $entry->event,
            null,
            null,
            $entry->properties->toArray(),
            'Mon, Sep 29, 2026 2:20 PM'
        );

        Http::assertSent(function (Request $request) {
            $payload = json_encode($request->data());

            $this->assertStringNotContainsString('hunter2', $payload);
            $this->assertStringNotContainsString('482915', $payload);
            $this->assertStringNotContainsString('sanctum-plain-text-token', $payload);

            // What is safe is still useful.
            $this->assertStringContainsString('driver@example.com', $payload);

            return true;
        });
    }

    public function test_a_channel_that_rejects_the_post_does_not_throw(): void
    {
        $this->configureChannel();

        Http::fake([self::WEBHOOK => Http::response('no', 500)]);

        $sent = (new TeamsNotifier)->post(
            AuditLog::LOGIN_FAILED,
            null,
            null,
            ['reason' => 'bad_password'],
            'Mon, Sep 29, 2026 2:20 PM'
        );

        $this->assertFalse($sent);
    }

    public function test_a_channel_that_cannot_be_reached_at_all_does_not_throw(): void
    {
        $this->configureChannel();

        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        // The job calls this directly, so this is the path that runs when the
        // webhook host is unreachable rather than merely unhappy.
        $sent = (new TeamsNotifier)->post(
            AuditLog::LOGIN_SUCCEEDED,
            null,
            null,
            [],
            'Mon, Sep 29, 2026 2:20 PM'
        );

        $this->assertFalse($sent);
    }

    public function test_the_trail_entry_survives_a_channel_that_cannot_be_queued(): void
    {
        $this->configureChannel();

        $user = User::create([
            'uuid' => Str::uuid(),
            'first_name' => 'Audit',
            'last_name' => 'Tester',
            'email' => Str::random(8).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'status' => true,
        ]);

        // No jobs table on this connection, so dispatching blows up — which
        // is the point: the record must not go down with the notification.
        config(['queue.connections.database.table' => 'no_such_jobs_table']);

        Log::spy();

        AuditLog::record(AuditLog::LOGIN_SUCCEEDED, $user, $user);

        // Proves the failure really happened, rather than the test passing
        // because the dispatch quietly succeeded.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'Teams audit alert could not be queued')
            ->once();

        $this->assertSame(1, Activity::count());
        $this->assertSame(
            AuditLog::LOGIN_SUCCEEDED,
            Activity::query()->latest('id')->first()->event
        );
    }
}
