<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * What the audit trail actually catches when the endpoints are driven for
 * real: a refused sign-in, a sign-out, a seat change and a removal.
 */
class AuditTrailEventsTest extends TestCase
{
    // Both traits define migrateFreshUsing(); ours is the one that keeps the
    // MySQL-only migrations out of the sqlite run.
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_wrong_password_is_recorded_without_the_password(): void
    {
        $user = $this->brokerUser($this->company(), 'owner_admin');

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'not-the-right-one',
        ])->assertStatus(422);

        $entry = $this->lastAudit();

        $this->assertSame(AuditLog::LOGIN_FAILED, $entry->event);
        $this->assertSame(AuditLog::REASON_BAD_PASSWORD, $entry->properties['reason']);
        $this->assertSame($user->email, $entry->properties['email']);
        $this->assertSame($user->id, $entry->subject_id);

        // Nobody is signed in, so nobody is blamed.
        $this->assertNull($entry->causer_id);

        $this->assertStringNotContainsString('not-the-right-one', json_encode($entry->toArray()));
    }

    public function test_an_address_with_no_account_is_told_apart_in_the_trail_only(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nobody@northwind.test',
            'password' => 'whatever-they-typed',
        ])->assertStatus(422);

        // The caller is told the same thing as a wrong password.
        $this->assertStringContainsString(
            'Invalid email or password',
            $response->getContent()
        );

        $entry = $this->lastAudit();

        $this->assertSame(AuditLog::LOGIN_FAILED, $entry->event);
        $this->assertSame(AuditLog::REASON_UNKNOWN_EMAIL, $entry->properties['reason']);
        $this->assertNull($entry->subject_id);
    }

    public function test_signing_out_is_recorded_against_the_person_who_did_it(): void
    {
        $user = $this->brokerUser($this->company(), 'owner_admin');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/logout')->assertOk();

        $entry = $this->lastAudit();

        $this->assertSame(AuditLog::LOGOUT, $entry->event);
        $this->assertSame($user->id, $entry->causer_id);
        $this->assertSame('broker', $entry->properties['portal']);
    }

    public function test_a_seat_change_records_who_changed_it_and_from_what(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent');

        Sanctum::actingAs($owner);

        $target = Role::where('slug', 'compliance_manager')->firstOrFail();

        $this->putJson("/api/v1/users/{$agent->uuid}", [
            'role_id' => $target->id,
        ])->assertOk();

        $entry = $this->auditFor(AuditLog::ROLE_CHANGED);

        $this->assertNotNull($entry, 'no role change was recorded');
        $this->assertSame($owner->id, $entry->causer_id);
        $this->assertSame($agent->id, $entry->subject_id);
        $this->assertSame($target->name, $entry->properties['to_role']);
        $this->assertNotSame(
            $entry->properties['from_role'],
            $entry->properties['to_role'],
            'a seat change should record two different seats'
        );
    }

    public function test_removing_someone_records_the_address_before_it_is_tombstoned(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent');
        $agentEmail = $agent->email;

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/users/{$agent->uuid}")->assertOk();

        $entry = $this->auditFor(AuditLog::USER_REMOVED);

        $this->assertNotNull($entry, 'no removal was recorded');
        $this->assertSame($owner->id, $entry->causer_id);
        $this->assertSame($agent->id, $entry->subject_id);

        // delete() rewrites the address to free the unique index, so the trail
        // has to have kept the real one.
        $this->assertSame($agentEmail, $entry->properties['removed_email']);
    }

    public function test_every_entry_carries_the_origin_of_the_request(): void
    {
        $user = $this->brokerUser($this->company(), 'owner_admin');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/logout')->assertOk();

        $entry = $this->lastAudit();

        $this->assertNotEmpty($entry->properties['ip']);
        $this->assertSame('POST api/v1/logout', $entry->properties['route']);
    }

    public function test_the_trail_only_ever_uses_its_own_log_name(): void
    {
        $user = $this->brokerUser($this->company(), 'owner_admin');

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/logout')->assertOk();

        $this->assertGreaterThan(0, Activity::count());

        $this->assertSame(
            0,
            Activity::where('log_name', '!=', AuditLog::LOG_NAME)->count(),
            'something wrote to the activity log outside the audit log name'
        );
    }

    private function lastAudit(): ?Activity
    {
        return Activity::query()->latest('id')->first();
    }

    private function auditFor(string $event): ?Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->first();
    }

    private function company(array $attributes = []): Company
    {
        return Company::create(array_merge([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ], $attributes));
    }

    private function brokerUser(Company $company, string $roleSlug, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => $roleSlug.'-'.Str::random(6).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => $roleSlug === 'owner_admin',
        ], $attributes));

        $user->assignRole(Role::where('slug', $roleSlug)->firstOrFail());

        return $user->fresh();
    }
}
