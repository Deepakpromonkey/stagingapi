<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Managing the broker's own team: changing a seat, removing someone, and
 * resending an invitation that never arrived.
 */
class BrokerTeamManagementTest extends TestCase
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

    protected function company(array $attributes = []): Company
    {
        return Company::create(array_merge([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ], $attributes));
    }

    protected function brokerUser(Company $company, string $roleSlug, array $attributes = []): User
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

    protected function roleId(string $slug): int
    {
        return Role::where('slug', $slug)->firstOrFail()->id;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Changing a seat
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_owner_can_change_a_teammates_role(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/users/{$agent->uuid}", [
            'role_id' => $this->roleId('senior_agent'),
        ])->assertOk()
            ->assertJsonPath('data.role.slug', 'senior_agent');

        $this->assertSame('senior_agent', $agent->fresh()->roleSlug());
    }

    public function test_changing_a_seat_ends_that_users_session(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent');

        $agent->createToken('broker-api');
        $this->assertSame(1, $agent->tokens()->count());

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/users/{$agent->uuid}", [
            'role_id' => $this->roleId('viewer'),
        ])->assertOk();

        $this->assertSame(0, $agent->fresh()->tokens()->count());
    }

    public function test_editing_contact_details_alone_leaves_the_session_alive(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent');

        $agent->createToken('broker-api');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/users/{$agent->uuid}", [
            'first_name' => 'Renamed',
        ])->assertOk();

        $this->assertSame('Renamed', $agent->fresh()->first_name);
        $this->assertSame(1, $agent->fresh()->tokens()->count(), 'A name change should not sign anyone out.');
    }

    public function test_a_compliance_manager_cannot_hand_out_a_seat_above_their_own(): void
    {
        $company = $this->company();
        $manager = $this->brokerUser($company, 'compliance_manager');
        $agent = $this->brokerUser($company, 'agent');

        Sanctum::actingAs($manager);

        $this->putJson("/api/v1/users/{$agent->uuid}", [
            'role_id' => $this->roleId('owner_admin'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        $this->assertSame('agent', $agent->fresh()->roleSlug());
    }

    public function test_nobody_can_change_their_own_seat(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/users/{$owner->uuid}", [
            'role_id' => $this->roleId('viewer'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        $this->assertSame('owner_admin', $owner->fresh()->roleSlug());
    }

    public function test_a_user_from_another_company_cannot_be_touched(): void
    {
        $owner = $this->brokerUser($this->company(), 'owner_admin');

        $stranger = $this->brokerUser(
            $this->company(['company_name' => 'Rival Freight']),
            'agent'
        );

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/users/{$stranger->uuid}", [
            'role_id' => $this->roleId('viewer'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('uuid');

        $this->deleteJson("/api/v1/users/{$stranger->uuid}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');

        $this->assertSame('agent', $stranger->fresh()->roleSlug());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Removing someone
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_owner_can_delete_a_teammate_and_they_drop_out_of_the_list(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent');

        $agent->createToken('broker-api');

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/users/{$agent->uuid}")->assertOk();

        // Soft delete: gone from every query, but the row survives so the
        // work they did still points somewhere.
        $this->assertNull(User::find($agent->id));
        $this->assertNotNull(User::withTrashed()->find($agent->id)->deleted_at);

        $this->assertSame(0, $agent->tokens()->count(), 'Deleting must end their session.');

        $listed = $this->getJson('/api/v1/users')->assertOk()->json('data.users');

        $this->assertNotContains($agent->uuid, array_column($listed, 'uuid'));
    }

    public function test_the_company_owner_cannot_be_deleted(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        // A second owner_admin seat — the guard is about `is_owner`, the
        // person who created the company, not about the role.
        $admin = $this->brokerUser($company, 'owner_admin', ['is_owner' => false]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$owner->uuid}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');

        $this->assertNotNull(User::find($owner->id));
    }

    public function test_you_cannot_delete_yourself(): void
    {
        $company = $this->company();
        $admin = $this->brokerUser($company, 'owner_admin', ['is_owner' => false]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$admin->uuid}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');

        $this->assertNotNull(User::find($admin->id));
    }

    public function test_a_deleted_teammates_email_can_be_invited_again(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');
        $agent = $this->brokerUser($company, 'agent', ['email' => 'returning@northwind.test']);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/users/{$agent->uuid}")->assertOk();

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Returning',
            'last_name' => 'Colleague',
            'email' => 'returning@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resending an invitation
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_invitation_can_be_resent_with_a_fresh_password_and_token(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Casey',
            'last_name' => 'Nolan',
            'email' => 'casey@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();

        $invited = User::where('email', 'casey@northwind.test')->firstOrFail();
        $invitation = Invitation::where('user_id', $invited->id)->firstOrFail();

        $originalPassword = $invited->password;
        $originalToken = $invitation->token;

        // A session opened on the old temporary password must not survive it.
        $invited->createToken('broker-api');

        Mail::assertSent(InvitationMail::class, 1);

        $this->postJson("/api/v1/users/{$invited->uuid}/resend-invitation")->assertOk();

        Mail::assertSent(InvitationMail::class, 2);

        $invited->refresh();
        $invitation->refresh();

        $this->assertNotSame($originalPassword, $invited->password);
        $this->assertNotSame($originalToken, $invitation->token);
        $this->assertTrue((bool) $invited->must_change_password);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertSame(0, $invited->tokens()->count());
    }

    public function test_an_invitation_cannot_be_resent_once_the_password_is_their_own(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        // Settled in: chose their own password long ago.
        $settled = $this->brokerUser($company, 'agent', ['must_change_password' => false]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/users/{$settled->uuid}/resend-invitation")
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');

        Mail::assertNothingSent();

        $this->assertSame($settled->password, $settled->fresh()->password);
    }

    public function test_an_invitation_cannot_be_resent_to_another_companys_user(): void
    {
        Mail::fake();

        $owner = $this->brokerUser($this->company(), 'owner_admin');

        $stranger = $this->brokerUser(
            $this->company(['company_name' => 'Rival Freight']),
            'agent',
            ['must_change_password' => true]
        );

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/users/{$stranger->uuid}/resend-invitation")
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');

        Mail::assertNothingSent();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Who is allowed near any of this
    // ─────────────────────────────────────────────────────────────────────

    public function test_seats_without_user_management_are_shut_out(): void
    {
        $company = $this->company();
        $agent = $this->brokerUser($company, 'agent');
        $target = $this->brokerUser($company, 'viewer');

        Sanctum::actingAs($agent);

        $this->putJson("/api/v1/users/{$target->uuid}", [
            'role_id' => $this->roleId('senior_agent'),
        ])->assertForbidden();

        $this->deleteJson("/api/v1/users/{$target->uuid}")->assertForbidden();

        $this->postJson("/api/v1/users/{$target->uuid}/resend-invitation")->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Listing
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_list_honours_the_requested_page_size(): void
    {
        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        foreach (range(1, 12) as $n) {
            $this->brokerUser($company, 'agent', ['first_name' => 'Agent'.$n]);
        }

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/users?per_page=25')->assertOk();

        $this->assertSame(25, $response->json('data.pagination.per_page'));
        $this->assertCount(13, $response->json('data.users'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Accepting an invitation
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_invitation_email_carries_a_link_and_never_a_password(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Priya',
            'email' => 'priya@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();

        $invitation = Invitation::where('email', 'priya@northwind.test')->firstOrFail();

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($invitation) {
            $body = $mail->render();

            return str_contains($mail->acceptUrl, $invitation->token)
                && str_contains($body, $invitation->token)
                && ! str_contains(strtolower($body), 'temporary password');
        });
    }

    public function test_the_invitation_link_sets_a_password_and_signs_them_in(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Priya',
            'email' => 'priya@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();

        $invitation = Invitation::where('email', 'priya@northwind.test')->firstOrFail();
        $token = $invitation->token;

        $response = $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));

        $invited = User::where('email', 'priya@northwind.test')->firstOrFail();

        $this->assertTrue(Hash::check('chosen-password', $invited->password));
        $this->assertFalse((bool) $invited->must_change_password);

        // The link is spent: the token it carried no longer opens anything.
        $this->assertNotSame($token, $invitation->fresh()->token);
    }

    public function test_an_invitation_link_cannot_be_used_twice(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Priya',
            'email' => 'priya@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();

        $token = Invitation::where('email', 'priya@northwind.test')->firstOrFail()->token;

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertOk();

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        // The first password still stands.
        $invited = User::where('email', 'priya@northwind.test')->firstOrFail();
        $this->assertTrue(Hash::check('chosen-password', $invited->password));
    }

    public function test_an_expired_invitation_link_is_refused(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Priya',
            'email' => 'priya@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();

        $invitation = Invitation::where('email', 'priya@northwind.test')->firstOrFail();

        $invitation->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $invitation->token,
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertTrue(
            (bool) User::where('email', 'priya@northwind.test')->firstOrFail()->must_change_password
        );
    }

    public function test_a_resent_invitation_invalidates_the_first_link(): void
    {
        Mail::fake();

        $company = $this->company();
        $owner = $this->brokerUser($company, 'owner_admin');

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'first_name' => 'Priya',
            'email' => 'priya@northwind.test',
            'role_id' => $this->roleId('agent'),
        ])->assertCreated();

        $invited = User::where('email', 'priya@northwind.test')->firstOrFail();
        $staleToken = Invitation::where('user_id', $invited->id)->firstOrFail()->token;

        $this->postJson("/api/v1/users/{$invited->uuid}/resend-invitation")->assertOk();

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $staleToken,
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }
}
