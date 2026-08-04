<?php

namespace Tests\Feature;

use App\Models\CarrierCompany;
use App\Models\CarrierConnectDocument;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierUser;
use App\Models\Company;
use App\Models\Role;
use Database\Seeders\CarrierRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CarrierPortalProfileTest extends TestCase
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
            'legal_name' => 'Summit Freight Logistics LLC',
            'dot_number' => '3456789',
            'phone' => '5551112222',
            'status' => true,
        ], $attributes));
    }

    protected function carrierUser(CarrierCompany $company, string $roleSlug, array $attributes = []): CarrierUser
    {
        $user = CarrierUser::create(array_merge([
            'uuid' => Str::uuid(),
            'carrier_company_id' => $company->id,
            'email' => $roleSlug.'@summitfreight.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => $roleSlug === 'carrier_owner',
        ], $attributes));

        $user->assignRole(Role::where('slug', $roleSlug)->firstOrFail());

        return $user->fresh();
    }

    protected function connectRequest(array $attributes = []): CarrierConnectRequest
    {
        $broker = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'company_email' => 'ops@northwind.test',
        ]);

        return CarrierConnectRequest::create(array_merge([
            'uuid' => Str::uuid(),
            'company_id' => $broker->id,
            'carrier_row_id' => (string) Str::uuid(),
            'carrier_dot_number' => '3456789',
            'carrier_legal_name' => 'Summit Freight Logistics LLC',
            'carrier_email' => 'owner@summitfreight.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_NEW,
        ], $attributes));
    }

    protected function itemsOf(array $body): array
    {
        return collect($body['data']['completeness']['items'])
            ->pluck('completed', 'key')
            ->all();
    }

    public function test_a_brand_new_carrier_has_only_its_company_details_done(): void
    {
        $company = $this->carrierCompany();

        Sanctum::actingAs($this->carrierUser($company, 'carrier_owner'), [CarrierUser::TOKEN_ABILITY]);

        $response = $this->getJson('/api/v1/carrier-portal/profile')->assertOk();

        // 1 of 8 steps.
        $response->assertJsonPath('data.completeness.completed_steps', 1)
            ->assertJsonPath('data.completeness.total_steps', 8)
            ->assertJsonPath('data.completeness.percentage', 12);

        $items = $this->itemsOf($response->json());

        $this->assertTrue($items['company_authority_info']);
        $this->assertFalse($items['carrier_agreement_signed']);
        $this->assertFalse($items['bank_account_connected']);
    }

    public function test_it_reports_the_percentage_and_every_checklist_item(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        // Guarded on the model, so it is never mass-assigned — the onboarding
        // stamps it the same way.
        $owner->forceFill(['email_verified_at' => now()])->save();

        // Five of eight: company info, COI, W-9, email, bank.
        $request = $this->connectRequest([
            'carrier_user_id' => $owner->id,
            'status' => CarrierConnectRequest::STATUS_BANK_VERIFIED,
            'stripe_verified_at' => now(),
        ]);

        foreach (['coi', 'w9'] as $type) {
            CarrierConnectDocument::create([
                'carrier_connect_request_id' => $request->id,
                'type' => $type,
                'disk' => 's3',
                'path' => "docs/{$type}.pdf",
                'name' => "{$type}.pdf",
            ]);
        }

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $response = $this->getJson('/api/v1/carrier-portal/profile')->assertOk();

        $response->assertJsonPath('data.completeness.completed_steps', 5)
            ->assertJsonPath('data.completeness.total_steps', 8)
            // 5/8 is 62.5 — floored, so the carrier is never told 63%.
            ->assertJsonPath('data.completeness.percentage', 62);

        $this->assertSame([
            'company_authority_info' => true,
            'insurance_coi' => true,
            'w9_on_file' => true,
            'carrier_agreement_signed' => false,
            'email_verified' => true,
            'phone_verified' => false,
            'identity_verified' => false,
            'bank_account_connected' => true,
        ], $this->itemsOf($response->json()));
    }

    public function test_a_fully_onboarded_carrier_reaches_one_hundred_percent(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $request = $this->connectRequest([
            'carrier_user_id' => $owner->id,
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
            'didit_status' => 'Approved',
            'stripe_verified_at' => now(),
            'signed_at' => now(),
        ]);

        foreach (['coi', 'w9'] as $type) {
            CarrierConnectDocument::create([
                'carrier_connect_request_id' => $request->id,
                'type' => $type,
                'disk' => 's3',
                'path' => "docs/{$type}.pdf",
                'name' => "{$type}.pdf",
            ]);
        }

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/carrier-portal/profile')
            ->assertOk()
            ->assertJsonPath('data.completeness.percentage', 100)
            ->assertJsonPath('data.completeness.completed_steps', 8);
    }

    public function test_progress_made_with_one_broker_counts_for_the_carrier_as_a_whole(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        // Identity cleared with the first broker...
        $this->connectRequest([
            'carrier_user_id' => $owner->id,
            'didit_status' => 'Approved',
        ]);

        // ...and the agreement signed with a second. Both count.
        $this->connectRequest([
            'carrier_user_id' => $owner->id,
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'signed_at' => now(),
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $items = $this->itemsOf(
            $this->getJson('/api/v1/carrier-portal/profile')->assertOk()->json()
        );

        $this->assertTrue($items['identity_verified']);
        $this->assertTrue($items['carrier_agreement_signed']);
    }

    public function test_invited_staff_see_the_carriers_profile_not_an_empty_one(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        $this->connectRequest([
            'carrier_user_id' => $owner->id,
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'signed_at' => now(),
        ]);

        // A dispatcher who has never touched an onboarding.
        Sanctum::actingAs($this->carrierUser($company, 'carrier_staff'), [CarrierUser::TOKEN_ABILITY]);

        $items = $this->itemsOf(
            $this->getJson('/api/v1/carrier-portal/profile')->assertOk()->json()
        );

        $this->assertTrue($items['carrier_agreement_signed']);
    }

    public function test_the_company_card_carries_the_carriers_identity(): void
    {
        $company = $this->carrierCompany();

        Sanctum::actingAs($this->carrierUser($company, 'carrier_owner'), [CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/carrier-portal/profile')
            ->assertOk()
            ->assertJsonPath('data.company.legal_name', 'Summit Freight Logistics LLC')
            ->assertJsonPath('data.company.dot_number', '3456789')
            ->assertJsonPath('data.company.status', 'active')
            ->assertJsonStructure([
                'data' => [
                    'company' => [
                        'legal_name', 'dba_name', 'dot_number', 'mc_number',
                        'fleet' => ['power_units', 'drivers'],
                        'domicile' => ['city', 'state'],
                        'status', 'phone', 'email',
                    ],
                    'completeness' => [
                        'percentage', 'completed_steps', 'total_steps',
                        'items' => [['key', 'label', 'completed']],
                    ],
                ],
            ]);
    }

    public function test_another_carriers_onboarding_never_counts_towards_this_profile(): void
    {
        $company = $this->carrierCompany();

        $owner = $this->carrierUser($company, 'carrier_owner');

        // Fully onboarded, but a different trucking company.
        $otherCarrier = $this->carrierCompany(['legal_name' => 'Other Freight Inc', 'dot_number' => '9999999']);

        $otherOwner = $this->carrierUser($otherCarrier, 'carrier_owner', ['email' => 'someone@otherfreight.test']);

        $this->connectRequest([
            'carrier_user_id' => $otherOwner->id,
            'carrier_dot_number' => '9999999',
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'signed_at' => now(),
            'stripe_verified_at' => now(),
        ]);

        Sanctum::actingAs($owner, [CarrierUser::TOKEN_ABILITY]);

        $items = $this->itemsOf(
            $this->getJson('/api/v1/carrier-portal/profile')->assertOk()->json()
        );

        $this->assertFalse($items['carrier_agreement_signed']);
        $this->assertFalse($items['bank_account_connected']);
    }

    public function test_a_driver_can_read_the_profile(): void
    {
        $company = $this->carrierCompany();

        Sanctum::actingAs($this->carrierUser($company, 'carrier_driver'), [CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/carrier-portal/profile')->assertOk();
    }

    public function test_the_endpoint_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/api/v1/carrier-portal/profile')->assertUnauthorized();
    }
}
