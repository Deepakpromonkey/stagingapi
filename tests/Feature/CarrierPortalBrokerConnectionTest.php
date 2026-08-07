<?php

namespace Tests\Feature;

use App\Models\CarrierCompany;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierUser;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CarrierRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CarrierPortalBrokerConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CarrierRolePermissionSeeder::class);
    }

    protected function carrierCompany(array $attributes = []): CarrierCompany
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
            'email' => $roleSlug.'@acmetrucking.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => $roleSlug === 'carrier_owner',
        ], $attributes));

        $user->assignRole(Role::where('slug', $roleSlug)->firstOrFail());

        return $user->fresh();
    }

    protected function broker(string $name): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => $name,
            'company_email' => Str::slug($name).'@brokers.test',
            'company_phone' => '5550000000',
        ]);
    }

    protected function connectRequest(Company $broker, array $attributes = []): CarrierConnectRequest
    {
        return CarrierConnectRequest::create(array_merge([
            'uuid' => Str::uuid(),
            'company_id' => $broker->id,
            'carrier_row_id' => (string) Str::uuid(),
            'carrier_dot_number' => '1234567',
            'carrier_legal_name' => 'Acme Trucking LLC',
            'carrier_email' => 'dispatch@acmetrucking.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'signed_at' => now(),
        ], $attributes));
    }

    public function test_it_lists_every_broker_the_carrier_has_onboarded_with(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->connectRequest($this->broker('Northwind Logistics'), [
            'carrier_user_id' => $owner->id,
        ]);

        $this->connectRequest($this->broker('Meridian Freight Brokers'), [
            'carrier_user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $response = $this->getJson('/api/v1/carrier-portal/brokers')->assertOk();

        $names = collect($response->json('data.brokers'))->pluck('broker.company_name');

        $this->assertEqualsCanonicalizing(
            ['Northwind Logistics', 'Meridian Freight Brokers'],
            $names->all()
        );

        $response->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.active', 2)
            ->assertJsonPath('data.summary.in_progress', 0);
    }

    public function test_invited_staff_see_the_same_broker_list_as_the_owner(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        // The onboarding is tied to the owner's login, not the dispatcher's.
        $this->connectRequest($this->broker('Northwind Logistics'), [
            'carrier_user_id' => $owner->id,
        ]);

        $staff = $this->carrierUser($company, 'carrier_staff');

        Sanctum::actingAs($staff, [CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/carrier-portal/brokers')
            ->assertOk()
            ->assertJsonPath('data.brokers.0.broker.company_name', 'Northwind Logistics');
    }

    public function test_a_driver_can_read_the_list_too(): void
    {
        $company = $this->carrierCompany();

        $this->connectRequest($this->broker('Northwind Logistics'), [
            'carrier_user_id' => $this->carrierUser($company, 'carrier_owner')->id,
        ]);

        Sanctum::actingAs($this->carrierUser($company, 'carrier_driver'), [CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/carrier-portal/brokers')->assertOk();
    }

    public function test_another_carriers_onboardings_are_never_listed(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->connectRequest($this->broker('Northwind Logistics'), [
            'carrier_user_id' => $owner->id,
        ]);

        // Same broker, a different trucking company.
        $otherCarrier = $this->carrierCompany(['dot_number' => '7654321', 'legal_name' => 'Other Freight Inc']);

        $otherOwner = $this->carrierUser($otherCarrier, 'carrier_owner', ['email' => 'someone@otherfreight.test']);

        $this->connectRequest($this->broker('Sunbelt Transport Brokers'), [
            'carrier_user_id' => $otherOwner->id,
            'carrier_dot_number' => '7654321',
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $names = collect(
            $this->getJson('/api/v1/carrier-portal/brokers')->assertOk()->json('data.brokers')
        )->pluck('broker.company_name');

        $this->assertSame(['Northwind Logistics'], $names->all());
    }

    public function test_an_onboarding_still_in_progress_is_listed_as_inactive(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->connectRequest($this->broker('Northwind Logistics'), [
            'carrier_user_id' => $owner->id,
        ]);

        // Raised against the DOT number before any portal login existed.
        $this->connectRequest($this->broker('Cascade Freight Partners'), [
            'carrier_user_id' => null,
            'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,
            'signed_at' => null,
            'mobile_verified_at' => now(),
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $response = $this->getJson('/api/v1/carrier-portal/brokers')->assertOk();

        $response->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.active', 1)
            ->assertJsonPath('data.summary.in_progress', 1);

        $pending = collect($response->json('data.brokers'))
            ->firstWhere('broker.company_name', 'Cascade Freight Partners');

        $this->assertFalse($pending['is_active']);
        $this->assertTrue($pending['progress']['mobile_verified']);
        $this->assertFalse($pending['progress']['agreement_signed']);
        $this->assertNull($pending['connected_at']);
    }

    public function test_the_list_can_be_filtered_to_active_or_in_progress(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->connectRequest($this->broker('Northwind Logistics'), ['carrier_user_id' => $owner->id]);

        $this->connectRequest($this->broker('Cascade Freight Partners'), [
            'carrier_user_id' => $owner->id,
            'status' => CarrierConnectRequest::STATUS_NEW,
            'signed_at' => null,
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $active = $this->getJson('/api/v1/carrier-portal/brokers?status=active')->assertOk();

        $this->assertSame(['Northwind Logistics'], collect($active->json('data.brokers'))->pluck('broker.company_name')->all());
        $active->assertJsonPath('data.summary.total', 1);

        $pending = $this->getJson('/api/v1/carrier-portal/brokers?status=in_progress')->assertOk();

        $this->assertSame(['Cascade Freight Partners'], collect($pending->json('data.brokers'))->pluck('broker.company_name')->all());
    }

    public function test_it_names_the_broker_teammate_who_raised_the_onboarding(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $broker = $this->broker('Northwind Logistics');

        $agent = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $broker->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam@northwind.test',
            'phone' => '5559876543',
            'password' => Hash::make('secret-password'),
            'status' => true,
        ]);

        $this->connectRequest($broker, [
            'carrier_user_id' => $owner->id,
            'user_id' => $agent->id,
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/carrier-portal/brokers')
            ->assertOk()
            ->assertJsonPath('data.brokers.0.contact.name', 'Sam Okafor')
            ->assertJsonPath('data.brokers.0.contact.email', 'sam@northwind.test');
    }

    public function test_the_onboarding_token_and_otp_are_never_exposed(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $request = $this->connectRequest($this->broker('Northwind Logistics'), [
            'carrier_user_id' => $owner->id,
            'otp' => '123456',
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $body = $this->getJson('/api/v1/carrier-portal/brokers')->assertOk()->getContent();

        $this->assertStringNotContainsString($request->token, $body);
        $this->assertStringNotContainsString('123456', $body);
    }

    public function test_the_endpoint_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/api/v1/carrier-portal/brokers')->assertUnauthorized();
    }
}
