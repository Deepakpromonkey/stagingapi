<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The security audit trail: that an event is recorded with its actor and
 * origin, that credentials never reach it, and that it is pruned after a year.
 */
class AuditTrailTest extends TestCase
{
    // Both traits define migrateFreshUsing(); ours is the one that keeps the
    // MySQL-only migrations out of the sqlite run.
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    public function test_the_activity_log_table_is_created(): void
    {
        $this->assertTrue(Schema::hasTable('activity_log'));

        foreach (['log_name', 'event', 'subject_id', 'causer_id', 'properties', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('activity_log', $column),
                "activity_log is missing the {$column} column"
            );
        }
    }

    public function test_an_event_is_recorded_with_its_actor_subject_and_origin(): void
    {
        $actor = $this->makeUser();
        $subject = $this->makeUser();

        AuditLog::record(
            AuditLog::USER_REMOVED,
            $subject,
            $actor,
            ['removed_email' => $subject->email]
        );

        $entry = Activity::query()->latest('id')->first();

        $this->assertNotNull($entry, 'no audit entry was written');
        $this->assertSame(AuditLog::LOG_NAME, $entry->log_name);
        $this->assertSame(AuditLog::USER_REMOVED, $entry->event);
        $this->assertSame($actor->id, $entry->causer_id);
        $this->assertSame($subject->id, $entry->subject_id);
        $this->assertSame($subject->email, $entry->properties['removed_email']);

        // Where the request came from is attached without the caller asking.
        $this->assertArrayHasKey('ip', $entry->properties);
        $this->assertArrayHasKey('route', $entry->properties);
    }

    public function test_credentials_passed_by_a_caller_are_never_stored(): void
    {
        AuditLog::record(AuditLog::LOGIN_FAILED, null, null, [
            'email' => 'driver@example.com',
            'reason' => AuditLog::REASON_BAD_PASSWORD,
            'password' => 'hunter2',
            'current_password' => 'hunter2',
            'otp_code' => '482915',
            'token' => 'sanctum-plain-text-token',
            'access_token' => 'jwt.value.here',
            'secret' => 'totp-seed',
        ]);

        $entry = Activity::query()->latest('id')->first();
        $properties = $entry->properties->toArray();

        foreach (['password', 'current_password', 'otp_code', 'token', 'access_token', 'secret'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $properties);
        }

        // The whole row, not just the properties, must be free of the values.
        $this->assertStringNotContainsString('hunter2', json_encode($entry->toArray()));
        $this->assertStringNotContainsString('482915', json_encode($entry->toArray()));

        // What is safe to keep is still kept.
        $this->assertSame('driver@example.com', $properties['email']);
        $this->assertSame(AuditLog::REASON_BAD_PASSWORD, $properties['reason']);
    }

    public function test_an_event_with_no_actor_is_not_attributed_to_the_signed_in_user(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        // A failed sign-in has no actor, even when a session happens to exist.
        AuditLog::record(AuditLog::LOGIN_FAILED, null, null, [
            'reason' => AuditLog::REASON_UNKNOWN_EMAIL,
        ]);

        $entry = Activity::query()->latest('id')->first();

        $this->assertNull($entry->causer_id);
    }

    public function test_the_current_user_can_be_recorded_as_the_actor(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        AuditLog::recordByCurrentUser(AuditLog::LOGOUT);

        $entry = Activity::query()->latest('id')->first();

        $this->assertSame($user->id, $entry->causer_id);
        $this->assertSame(AuditLog::LOGOUT, $entry->event);
    }

    public function test_a_write_failure_does_not_break_the_request(): void
    {
        Schema::drop('activity_log');

        AuditLog::record(AuditLog::LOGIN_SUCCEEDED);

        // Reaching here at all is the assertion: auditing must never be the
        // reason a sign-in fails.
        $this->assertTrue(true);
    }

    public function test_the_trail_is_kept_for_a_year(): void
    {
        $this->assertSame(365, config('activitylog.clean_after_days'));
    }

    public function test_the_prune_is_scheduled_and_will_not_stall_on_a_confirmation(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter(fn ($command) => str_contains((string) $command, 'activitylog:clean'));

        $this->assertCount(1, $commands, 'activitylog:clean is not scheduled exactly once');

        // APP_ENV=production on the server, where the command would otherwise
        // stop and ask, with no one there to answer.
        $this->assertStringContainsString('--force', $commands->first());
    }

    /**
     * The User model has no factory, and the audit trail does not care about
     * anything but the id, so keep this to the columns the table requires.
     */
    private function makeUser(): User
    {
        return User::create([
            'uuid' => Str::uuid(),
            'first_name' => 'Audit',
            'last_name' => 'Tester',
            'email' => Str::random(8).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'status' => true,
        ]);
    }
}