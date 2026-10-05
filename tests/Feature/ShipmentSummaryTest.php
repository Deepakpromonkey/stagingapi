<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

class ShipmentSummaryTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function company(): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Company '.Str::random(6),
            'status' => true,
        ]);
    }

    private function user(Company $company): User
    {
        $user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam-'.Str::random(8).'@example.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => false,
        ]);

        $user->assignRole(Role::where('slug', 'agent')->firstOrFail());

        return $user->fresh();
    }

    private function shipments(User $user, string $status, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Shipment::create([
                'uuid' => (string) Str::orderedUuid(),
                'company_id' => $user->company_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'shipment_no' => 'SHP-'.Str::random(10),
                'tracking_method' => 'driver_phone',
                'status' => $status,
            ]);
        }
    }

    public function test_counts_every_shipment_of_the_company_by_status_past_one_page(): void
    {
        $user = $this->user($this->company());

        // More than the fifteen rows the list endpoint returns per page.
        $this->shipments($user, 'active', 18);
        $this->shipments($user, 'completed', 4);
        $this->shipments($user, 'draft', 2);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/shipments/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 24)
            ->assertJsonPath('data.by_status.active', 18)
            ->assertJsonPath('data.by_status.completed', 4)
            ->assertJsonPath('data.by_status.draft', 2);
    }

    public function test_another_companys_shipments_are_not_counted(): void
    {
        $mine = $this->user($this->company());
        $theirs = $this->user($this->company());

        $this->shipments($mine, 'active', 1);
        $this->shipments($theirs, 'active', 5);

        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/shipments/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_a_company_with_no_shipments_gets_zero(): void
    {
        Sanctum::actingAs($this->user($this->company()));

        $this->getJson('/api/v1/shipments/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertExactJson([
                'status' => true,
                'message' => 'Shipment summary retrieved successfully.',
                'data' => ['total' => 0, 'by_status' => []],
            ]);
    }
}
