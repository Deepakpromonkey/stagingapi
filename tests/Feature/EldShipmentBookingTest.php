<?php

namespace Tests\Feature;

use App\Models\CarrierConnectRequest;
use App\Models\Company;
use App\Models\Eld\EldConnection;
use App\Models\Eld\EldDriver;
use App\Models\Eld\EldVehicle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Booking a load through a connected carrier's ELD — the fleet dropdowns and
 * POST /shipments itself.
 *
 * The Terminal API is never called in this file. Everything here reads
 * eld_vehicles/eld_drivers rows that are already synced into our own tables
 * — that's the whole point of the fleet endpoint (see EldFleetController's
 * own docblock) — so there is nothing to fake with Http::fake() and no
 * Http::preventStrayRequests() guard needed.
 */
class EldShipmentBookingTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config(['subscriptions.require_subscription_for_loads' => false]);
    }

    // ───────────────────────────── Fixtures ─────────────────────────────

    private function company(): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);
    }

    private function brokerUser(Company $company): User
    {
        $user = User::create([
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

        $user->assignRole(Role::where('slug', 'agent')->firstOrFail());

        return $user->fresh();
    }

    /**
     * A connected carrier, linked to $company the same way a real onboarding
     * would: an EldConnection plus a CarrierConnectRequest carrying the
     * company_id — EldFleetController's whereHas('connectRequests', ...)
     * is what this fixture exists to satisfy.
     */
    private function eldConnection(Company $company, array $attributes = []): EldConnection
    {
        $connection = EldConnection::create(array_merge([
            'uuid' => Str::uuid(),
            'carrier_dot_number' => '1234567',
            'carrier_legal_name' => "Frank's Trucking",
            'provider' => 'Samsara',
            'terminal_connection_id' => 'conn_test_'.Str::random(10),
            'connection_token' => 'con_tkn_test_'.Str::random(10),
            'status' => EldConnection::STATUS_CONNECTED,
            'sync_status' => EldConnection::SYNC_COMPLETED,
        ], $attributes));

        CarrierConnectRequest::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'eld_connection_id' => $connection->id,
            'carrier_dot_number' => $connection->carrier_dot_number,
            'carrier_row_id' => '99001',
            'carrier_legal_name' => $connection->carrier_legal_name,
            'carrier_email' => 'frank@franks.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'sent_on' => now(),
        ]);

        return $connection;
    }

    private function eldVehicle(EldConnection $connection, array $attributes = []): EldVehicle
    {
        return EldVehicle::create(array_merge([
            'eld_connection_id' => $connection->id,
            'terminal_id' => 'vcl_'.Str::random(20),
            'name' => 'Truck 1',
            'vin' => '1XPBD49X4ND775405',
            'license_plate' => 'ABC1234',
            'make' => 'PETERBILT',
            'model' => '579',
            'year' => '2022',
            'status' => 'active',
        ], $attributes));
    }

    private function eldDriver(EldConnection $connection, array $attributes = []): EldDriver
    {
        return EldDriver::create(array_merge([
            'eld_connection_id' => $connection->id,
            'terminal_id' => 'drv_'.Str::random(20),
            'first_name' => 'Arthur',
            'last_name' => 'Wilson',
            'phone' => '+12125555555',
            'status' => 'active',
        ], $attributes));
    }

    private function bookingPayload(EldConnection $connection, EldVehicle $vehicle, EldDriver $driver, array $overrides = []): array
    {
        return array_merge([
            'tracking_method' => 'eld',
            'origin' => 'Dallas, TX',
            'destination' => 'Austin, TX',
            'eld_connection_uuid' => $connection->uuid,
            'eld_vehicle_terminal_id' => $vehicle->terminal_id,
            'eld_driver_terminal_id' => $driver->terminal_id,
            'pro_number' => 'PRO123456',
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '09:00',
            'pickup_timezone' => 'Central',
            'delivery_date' => now()->addDays(3)->toDateString(),
            'delivery_time' => '17:00',
            'delivery_timezone' => 'Eastern',
        ], $overrides);
    }

    // ────────────────────────── Fleet endpoints ──────────────────────────

    public function test_carriers_endpoint_lists_only_connected_carriers_for_this_company(): void
    {
        $company = $this->company();
        $otherCompany = $this->company();

        $mine = $this->eldConnection($company);
        $this->eldConnection($otherCompany, ['carrier_legal_name' => 'Someone Elses Fleet']);

        Sanctum::actingAs($this->brokerUser($company));

        $response = $this->getJson('/api/v1/eld/carriers');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('carrier_name');

        $this->assertTrue($names->contains("Frank's Trucking"));
        $this->assertFalse($names->contains('Someone Elses Fleet'));
        $this->assertSame((string) $mine->uuid, $response->json('data.0.connection_uuid'));
    }

    public function test_fleet_endpoint_returns_only_active_vehicles_and_drivers(): void
    {
        $company = $this->company();
        $connection = $this->eldConnection($company);

        $this->eldVehicle($connection, ['name' => 'Active Truck', 'status' => 'active']);
        $this->eldVehicle($connection, ['name' => 'Retired Truck', 'status' => 'inactive']);
        $this->eldVehicle($connection, ['name' => 'No Status Truck', 'status' => null]);

        $this->eldDriver($connection, ['first_name' => 'Active', 'status' => 'active']);
        $this->eldDriver($connection, ['first_name' => 'Terminated', 'status' => 'terminated']);

        Sanctum::actingAs($this->brokerUser($company));

        $response = $this->getJson("/api/v1/eld/carriers/{$connection->uuid}/fleet");

        $response->assertOk();

        $vehicleNames = collect($response->json('data.vehicles'))->pluck('name');
        $this->assertTrue($vehicleNames->contains('Active Truck'));
        $this->assertTrue($vehicleNames->contains('No Status Truck'), 'a null status should count as active');
        $this->assertFalse($vehicleNames->contains('Retired Truck'));

        $driverNames = collect($response->json('data.drivers'))->pluck('name');
        $this->assertTrue($driverNames->contains('Active Wilson') || $driverNames->contains('Active '));
        $this->assertFalse($driverNames->contains('Terminated Wilson'));
    }

    public function test_fleet_endpoint_is_not_reachable_for_another_companys_connection(): void
    {
        $owner = $this->company();
        $stranger = $this->company();
        $connection = $this->eldConnection($owner);

        Sanctum::actingAs($this->brokerUser($stranger));

        $this->getJson("/api/v1/eld/carriers/{$connection->uuid}/fleet")->assertNotFound();
    }

    // ──────────────────────────── Booking ────────────────────────────

    public function test_broker_can_book_a_shipment_through_a_connected_carriers_eld(): void
    {
        $company = $this->company();
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection, ['name' => 'Truck 42', 'license_plate' => 'XYZ999']);
        $driver = $this->eldDriver($connection, ['phone' => '+19995551234']);

        Sanctum::actingAs($this->brokerUser($company));

        $response = $this->postJson('/api/v1/shipments', $this->bookingPayload($connection, $vehicle, $driver));

        $response->assertCreated();

        // Denormalised straight from the ELD connection/fleet, not typed by
        // the broker — this is resolveEldSelection()'s whole job.
        $response->assertJsonPath('data.carrier_name', "Frank's Trucking");
        $response->assertJsonPath('data.carrier_dot', '1234567');
        $response->assertJsonPath('data.truck_number', 'Truck 42');
        $response->assertJsonPath('data.driver_phone_1', '+19995551234');
        $response->assertJsonPath('data.tracking_method', 'eld');
        $response->assertJsonPath('data.status', 'draft');

        $this->assertNotNull($response->json('data.public_tracking_url'), 'a shareable link should exist from creation');

        $this->assertDatabaseHas('shipments', [
            'company_id' => $company->id,
            'eld_connection_id' => $connection->id,
            'eld_vehicle_id' => $vehicle->id,
            'eld_driver_id' => $driver->id,
        ]);
    }

    public function test_booking_rejects_a_connection_uuid_belonging_to_another_company(): void
    {
        $owner = $this->company();
        $thief = $this->company();

        $connection = $this->eldConnection($owner);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        Sanctum::actingAs($this->brokerUser($thief));

        $response = $this->postJson('/api/v1/shipments', $this->bookingPayload($connection, $vehicle, $driver));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['eld_connection_uuid']);

        $this->assertDatabaseMissing('shipments', ['eld_connection_id' => $connection->id]);
    }

    public function test_booking_rejects_a_vehicle_not_on_the_selected_carriers_fleet(): void
    {
        $company = $this->company();
        $connection = $this->eldConnection($company);
        $realVehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        Sanctum::actingAs($this->brokerUser($company));

        $payload = $this->bookingPayload($connection, $realVehicle, $driver, [
            'eld_vehicle_terminal_id' => 'vcl_does_not_exist_on_this_fleet',
        ]);

        $response = $this->postJson('/api/v1/shipments', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['eld_vehicle_terminal_id']);
    }

    public function test_origin_and_destination_are_required_for_an_eld_tracked_load(): void
    {
        $company = $this->company();
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        Sanctum::actingAs($this->brokerUser($company));

        $payload = $this->bookingPayload($connection, $vehicle, $driver, [
            'origin' => null,
            'destination' => null,
        ]);

        $response = $this->postJson('/api/v1/shipments', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['origin', 'destination']);
    }
}
