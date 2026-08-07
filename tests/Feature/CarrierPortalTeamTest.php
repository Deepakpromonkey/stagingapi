<?php

namespace Tests\Feature;

use App\Mail\CarrierInvitationMail;
use App\Models\CarrierCompany;
use App\Models\CarrierUser;
use App\Models\Role;
use App\Services\RoleService;
use Database\Seeders\CarrierRolePermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CarrierPortalTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CarrierRolePermissionSeeder::class);
    }

    protected function company(array $attributes = []): CarrierCompany
    {
        return CarrierCompany::create(array_merge([
            'uuid' => Str::uuid(),
            'legal_name' => 'Acme Trucking LLC',
            'dot_number' => '1234567',
            'status' => true,
        ], $attributes));
    }

    protected function carrierUser(CarrierCompany $company, string $roleSlug, array $attributes = []): CarrierUser
    {
        $user = CarrierUser::create(array_merge([
            'uuid' => Str::uuid(),
            'carrier_company_id' => $company->id,
            'first_name' => 'Pat',
            'last_name' => 'Rivera',
            'email' => $roleSlug.'@acmetrucking.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => $roleSlug === 'carrier_owner',
        ], $attributes));

        $user->assignRole(Role::where('slug', $roleSlug)->firstOrFail());

        return $user->fresh();
    }

    protected function actingAsCarrier(CarrierUser $user): CarrierUser
    {
        Sanctum::actingAs($user, [CarrierUser::TOKEN_ABILITY]);

        return $user;
    }

    protected function roleId(string $slug): int
    {
        return Role::where('slug', $slug)->firstOrFail()->id;
    }

    // ─────────────────────────────────────────────────────────────────────
    // The matrix itself
    // ─────────────────────────────────────────────────────────────────────

    public function test_each_seat_holds_exactly_the_permissions_the_matrix_gives_it(): void
    {
        $expected = [
            'carrier_driver' => [
                'view-own-carrier-profile',
                'view-assigned-loads',
                'view-own-payments',
            ],
            'carrier_staff' => [
                'view-own-carrier-profile',
                'view-assigned-loads',
                'view-own-payments',
                'manage-eld-consent',
                'upload-onboarding-documents',
                'manage-assigned-loads',
                'view-carrier-payments',
                'edit-carrier-contact-info',
            ],
            'carrier_owner' => [
                'view-own-carrier-profile',
                'view-assigned-loads',
                'view-own-payments',
                'manage-eld-consent',
                'upload-onboarding-documents',
                'manage-assigned-loads',
                'view-carrier-payments',
                'edit-carrier-contact-info',
                'manage-carrier-payout-banking',
                'edit-carrier-legal-identity',
                'complete-carrier-kyc',
                'sign-carrier-agreements',
                'manage-carrier-users',
            ],
        ];

        foreach ($expected as $slug => $permissions) {
            $role = Role::where('slug', $slug)->firstOrFail();

            $this->assertSame(
                collect($permissions)->sort()->values()->all(),
                $role->permissions->pluck('name')->sort()->values()->all(),
                "Permissions for {$slug} do not match the matrix."
            );
        }
    }

    public function test_every_sensitive_write_belongs_to_the_owner_seat_alone(): void
    {
        $sensitive = array_keys(config('carrier_rbac.permissions.sensitive'));

        foreach (['carrier_driver', 'carrier_staff'] as $slug) {

            $held = Role::where('slug', $slug)->firstOrFail()
                ->permissions->pluck('name')->all();

            foreach ($sensitive as $permission) {
                $this->assertNotContains(
                    $permission,
                    $held,
                    "{$slug} must not hold the sensitive permission {$permission}."
                );
            }
        }
    }

    public function test_carrier_roles_are_not_offered_to_brokers(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $brokerSlugs = app(RoleService::class)->all()->pluck('slug');

        $this->assertFalse($brokerSlugs->contains('carrier_owner'));
        $this->assertTrue($brokerSlugs->contains('owner_admin'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Inviting
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_owner_can_invite_a_staff_user_and_they_are_emailed_credentials(): void
    {
        Mail::fake();

        $company = $this->company();

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_owner'));

        $response = $this->postJson('/api/v1/carrier-portal/users/invite', [
            'first_name' => 'Dana',
            'last_name' => 'Blake',
            'email' => 'dana@acmetrucking.test',
            'phone' => '5551234567',
            'role_id' => $this->roleId('carrier_staff'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'dana@acmetrucking.test')
            ->assertJsonPath('data.role.slug', 'carrier_staff');

        $invited = CarrierUser::where('email', 'dana@acmetrucking.test')->firstOrFail();

        $this->assertSame($company->id, $invited->carrier_company_id);
        $this->assertTrue($invited->must_change_password);
        $this->assertFalse($invited->is_owner);
        $this->assertSame('carrier_staff', $invited->roleSlug());

        Mail::assertSent(CarrierInvitationMail::class, function (CarrierInvitationMail $mail) {
            return $mail->hasTo('dana@acmetrucking.test')
                && strlen($mail->temporaryPassword) === 12;
        });
    }

    public function test_an_invited_user_can_sign_in_and_is_held_to_the_password_change(): void
    {
        Mail::fake();

        $company = $this->company();

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_owner'));

        $this->postJson('/api/v1/carrier-portal/users/invite', [
            'first_name' => 'Dana',
            'email' => 'dana@acmetrucking.test',
            'role_id' => $this->roleId('carrier_staff'),
        ])->assertStatus(201);

        $temporaryPassword = null;

        Mail::assertSent(CarrierInvitationMail::class, function (CarrierInvitationMail $mail) use (&$temporaryPassword) {
            $temporaryPassword = $mail->temporaryPassword;

            return true;
        });

        $invited = CarrierUser::where('email', 'dana@acmetrucking.test')->firstOrFail();

        // Two-factor is on by default, so signing in raises a challenge — the
        // credentials themselves are what is being checked here.
        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => 'dana@acmetrucking.test',
            'password' => $temporaryPassword,
        ])->assertOk()->assertJsonPath('data.requires_otp', true);

        // Everything but the change-password screen stays shut.
        $this->actingAsCarrier($invited);

        $this->getJson('/api/v1/carrier-portal/users')
            ->assertStatus(403)
            ->assertJsonPath('errors.must_change_password', true);

        $this->getJson('/api/v1/carrier-portal/me')->assertOk();
    }

    public function test_staff_and_drivers_cannot_invite_anyone(): void
    {
        $company = $this->company();

        foreach (['carrier_staff', 'carrier_driver'] as $slug) {

            $this->actingAsCarrier($this->carrierUser($company, $slug));

            $this->postJson('/api/v1/carrier-portal/users/invite', [
                'first_name' => 'Dana',
                'email' => 'dana-'.$slug.'@acmetrucking.test',
                'role_id' => $this->roleId('carrier_driver'),
            ])->assertStatus(403);
        }

        $this->assertSame(0, CarrierUser::where('email', 'like', 'dana-%')->count());
    }

    public function test_a_broker_role_cannot_be_assigned_to_a_carrier_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $company = $this->company();

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_owner'));

        $response = $this->postJson('/api/v1/carrier-portal/users/invite', [
            'first_name' => 'Dana',
            'email' => 'dana@acmetrucking.test',
            'role_id' => Role::where('slug', 'owner_admin')->firstOrFail()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');

        // One mistake, one message — `bail` stops the assignability closure
        // from piling a second error onto the same field.
        $this->assertCount(1, $response->json('errors.role_id'));
    }

    public function test_an_email_that_already_has_a_login_cannot_be_invited_again(): void
    {
        $company = $this->company();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->actingAsCarrier($owner);

        $this->postJson('/api/v1/carrier-portal/users/invite', [
            'first_name' => 'Dana',
            'email' => $owner->email,
            'role_id' => $this->roleId('carrier_staff'),
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Listing & editing
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_team_list_is_scoped_to_the_callers_own_carrier(): void
    {
        $company = $this->company();

        $other = $this->company(['dot_number' => '7654321', 'legal_name' => 'Other Freight Inc']);

        $this->carrierUser($other, 'carrier_owner', ['email' => 'someone@otherfreight.test']);

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->carrierUser($company, 'carrier_driver');

        $this->actingAsCarrier($owner);

        $emails = collect(
            $this->getJson('/api/v1/carrier-portal/users')->assertOk()->json('data.users')
        )->pluck('email');

        $this->assertCount(2, $emails);
        $this->assertFalse($emails->contains('someone@otherfreight.test'));
    }

    public function test_the_owner_can_change_a_colleagues_seat(): void
    {
        $company = $this->company();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $driver = $this->carrierUser($company, 'carrier_driver');

        $this->actingAsCarrier($owner);

        $this->putJson('/api/v1/carrier-portal/users/'.$driver->uuid, [
            'role_id' => $this->roleId('carrier_staff'),
        ])->assertOk()->assertJsonPath('data.role.slug', 'carrier_staff');

        $this->assertSame('carrier_staff', $driver->fresh()->roleSlug());
    }

    public function test_the_owner_cannot_change_their_own_seat_or_switch_themselves_off(): void
    {
        $company = $this->company();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->actingAsCarrier($owner);

        $this->putJson('/api/v1/carrier-portal/users/'.$owner->uuid, [
            'role_id' => $this->roleId('carrier_driver'),
        ])->assertStatus(422);

        $this->putJson('/api/v1/carrier-portal/users/'.$owner->uuid, [
            'status' => false,
        ])->assertStatus(422);

        $this->assertSame('carrier_owner', $owner->fresh()->roleSlug());
    }

    public function test_switching_a_user_off_ends_their_session(): void
    {
        $company = $this->company();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $staff = $this->carrierUser($company, 'carrier_staff');

        // Real tokens on both sides: Sanctum::actingAs would pin the auth
        // resolver for the whole test and the revoked token would never
        // actually be presented.
        $ownerToken = $owner->createToken('carrier-portal', [CarrierUser::TOKEN_ABILITY])->plainTextToken;

        $staffToken = $staff->createToken('carrier-portal', [CarrierUser::TOKEN_ABILITY])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->putJson('/api/v1/carrier-portal/users/'.$staff->uuid, ['status' => false])
            ->assertOk();

        $this->assertSame(0, $staff->fresh()->tokens()->count());

        // Laravel caches the resolved user on the guard for the rest of the
        // test method, so without this the next request would be answered as
        // the owner no matter what token it carries.
        $this->app['auth']->forgetGuards();

        // The revoked token is dead on the wire too.
        $this->withHeader('Authorization', 'Bearer '.$staffToken)
            ->getJson('/api/v1/carrier-portal/me')
            ->assertUnauthorized();
    }

    public function test_a_user_from_another_carrier_cannot_be_edited(): void
    {
        $company = $this->company();

        $other = $this->company(['dot_number' => '7654321']);

        $outsider = $this->carrierUser($other, 'carrier_driver', ['email' => 'outsider@otherfreight.test']);

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_owner'));

        $this->putJson('/api/v1/carrier-portal/users/'.$outsider->uuid, [
            'first_name' => 'Hacked',
        ])->assertStatus(422);

        $this->assertNotSame('Hacked', $outsider->fresh()->first_name);
    }

    public function test_assignable_roles_are_listed_for_the_owner_and_closed_to_everyone_else(): void
    {
        $company = $this->company();

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_owner'));

        $slugs = collect(
            $this->getJson('/api/v1/carrier-portal/roles')->assertOk()->json('data')
        )->pluck('slug');

        $this->assertEqualsCanonicalizing(
            ['carrier_driver', 'carrier_staff', 'carrier_owner'],
            $slugs->all()
        );

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_staff'));

        $this->getJson('/api/v1/carrier-portal/roles')->assertStatus(403);
    }

    public function test_me_reports_the_seat_and_its_permissions(): void
    {
        $company = $this->company();

        $this->actingAsCarrier($this->carrierUser($company, 'carrier_staff'));

        $response = $this->getJson('/api/v1/carrier-portal/me')->assertOk();

        $response->assertJsonPath('data.role.slug', 'carrier_staff')
            ->assertJsonPath('data.carrier.dot_number', '1234567')
            ->assertJsonPath('data.is_owner', false);

        $permissions = collect($response->json('data.permissions'));

        $this->assertTrue($permissions->contains('upload-onboarding-documents'));
        $this->assertFalse($permissions->contains('manage-carrier-payout-banking'));
    }
}
