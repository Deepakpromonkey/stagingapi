<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentStop;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Saving a load's trip sheet - POST /shipments/{uuid}/stops.
 *
 * Every stop needs an address and a place on the map the driver side can
 * use, checked before anything is written; and a load's stops are saved
 * once, because the driver's progress is kept against their ids.
 */
class ShipmentStopsTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Http::preventStrayRequests();

        $company = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);

        $this->user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam-'.Str::random(8).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => false,
        ]);

        $this->user->assignRole(Role::where('slug', 'agent')->firstOrFail());

        Sanctum::actingAs($this->user->fresh());
    }

    private function load(array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::orderedUuid(),
            'tracking_token' => Str::random(48),
            'company_id' => $this->user->company_id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'shipment_no' => 'SHP-TEST-'.Str::random(6),
            'tracking_method' => 'driver_phone',
            'status' => 'draft',
        ], $attributes));
    }

    private function pickup(array $overrides = []): array
    {
        return array_merge([
            'stop_type' => 'Pickup',
            'stop_name' => 'Dallas DC',
            'address' => '4200 Diplomacy Rd, Dallas, TX 75261, USA',
            'city' => 'Dallas',
            'state' => 'TX',
            'zipcode' => '75261',
            'country' => 'United States',
            'latitude' => 32.834,
            'longitude' => -96.944,
        ], $overrides);
    }

    private function delivery(array $overrides = []): array
    {
        return array_merge([
            'stop_type' => 'Delivery',
            'stop_name' => 'JMD 2',
            'address' => 'Badshahpur Sohna Rd, Sector 48, Gurugram, Haryana 122018, India',
            'city' => 'Gurugram',
            'state' => 'HR',
            'zipcode' => '122018',
            'country' => 'India',
            'latitude' => 28.4198677,
            'longitude' => 77.0382266,
        ], $overrides);
    }

    private function saveStops(Shipment $load, array $stops)
    {
        // The page posts the stops as one JSON string field.
        return $this->postJson("/api/v1/shipments/{$load->uuid}/stops", [
            'stops_data' => json_encode($stops),
        ]);
    }

    public function test_stops_with_a_place_on_the_map_are_saved(): void
    {
        $load = $this->load();

        $this->saveStops($load, [$this->pickup(), $this->delivery()])->assertOk();

        $stops = $load->stops()->get();

        $this->assertCount(2, $stops);
        $this->assertEqualsWithDelta(28.4198677, (float) $stops[1]->latitude, 0.0000001);
        $this->assertEqualsWithDelta(77.0382266, (float) $stops[1]->longitude, 0.0000001);
    }

    public function test_a_stop_without_a_location_is_refused_by_name_and_nothing_is_saved(): void
    {
        // Load #59: a full address on the delivery, but no coordinates.
        $load = $this->load();

        $this->saveStops($load, [$this->pickup(), $this->delivery(['latitude' => null, 'longitude' => null])])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stop 2 (Delivery): choose the address from the suggestions.')
            ->assertJsonPath('errors', ['stops.1.location' => ['Stop 2 (Delivery): choose the address from the suggestions.']]);

        // All or nothing - the good pickup is not saved on its own either.
        $this->assertSame(0, $load->stops()->count());
    }

    public function test_coordinates_the_driver_side_cannot_use_are_refused(): void
    {
        $bad = [
            ['latitude' => 90.5],
            ['latitude' => -91],
            ['longitude' => 180.1],
            ['longitude' => -181],
            ['latitude' => 'abc'],
            ['latitude' => ''],
            ['latitude' => true],
            ['longitude' => []],
            ['latitude' => null],
        ];

        foreach ($bad as $coordinates) {
            $errors = $this->saveStops($this->load(), [$this->pickup(), $this->delivery($coordinates)])
                ->assertUnprocessable()
                ->json('errors');

            $this->assertSame(
                ['stops.1.location' => ['Stop 2 (Delivery): choose the address from the suggestions.']],
                $errors,
                json_encode($coordinates)
            );
        }

        $this->assertSame(0, ShipmentStop::count());
    }

    public function test_the_ends_of_the_range_and_numeric_strings_are_accepted(): void
    {
        $load = $this->load();

        $this->saveStops($load, [
            $this->pickup(['latitude' => 90, 'longitude' => 180]),
            $this->delivery(['latitude' => '-90', 'longitude' => ' -180.0 ']),
        ])->assertOk();

        $stops = $load->stops()->get();

        $this->assertEqualsWithDelta(-90.0, (float) $stops[1]->latitude, 0.0000001);
        $this->assertEqualsWithDelta(-180.0, (float) $stops[1]->longitude, 0.0000001);
    }

    public function test_a_stop_without_an_address_is_refused(): void
    {
        $load = $this->load();

        $this->saveStops($load, [$this->pickup(['address' => '   ']), $this->delivery()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stop 1 (Pickup): enter the address.');

        $this->assertSame(0, $load->stops()->count());
    }

    public function test_every_problem_is_listed(): void
    {
        $this->saveStops($this->load(), [
            $this->pickup(['address' => '']),
            $this->delivery(['longitude' => null]),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stop 1 (Pickup): enter the address. (and 1 more)')
            ->assertJsonCount(2, 'errors');
    }

    public function test_a_second_save_is_refused_and_the_saved_stops_keep_their_ids(): void
    {
        $load = $this->load();

        $this->saveStops($load, [$this->pickup(), $this->delivery()])->assertOk();

        $ids = $load->stops()->pluck('id')->all();

        // Different stops this time - a retry, an old tab, or a direct call.
        $this->saveStops($load, [$this->pickup(['address' => 'Somewhere else, TX']), $this->delivery()])
            ->assertStatus(409)
            ->assertJsonPath('message', "This load's stops are already saved and can't be replaced.");

        $this->assertSame($ids, $load->stops()->pluck('id')->all());
        $this->assertSame('4200 Diplomacy Rd, Dallas, TX 75261, USA', $load->stops()->first()->address);
    }

    public function test_fewer_than_two_stops_is_refused(): void
    {
        $this->saveStops($this->load(), [$this->pickup()])->assertUnprocessable();
    }

    public function test_another_companys_load_is_not_found(): void
    {
        $other = Company::create(['uuid' => Str::uuid(), 'company_name' => 'Elsewhere Freight', 'status' => true]);

        $this->saveStops($this->load(['company_id' => $other->id]), [$this->pickup(), $this->delivery()])
            ->assertNotFound();
    }
}
