<?php

namespace Tests\Feature;

use App\Models\CarrierConnectRequest;
use App\Models\Company;
use App\Models\Eld\EldConnection;
use App\Models\Eld\EldDriver;
use App\Models\Eld\EldVehicle;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Eld\EldTrackingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Start / stop / track, and the geofence milestones that ride on top of the
 * same location writes. Terminal is faked with Http::fake() the same way
 * EldFleetSyncTest fakes it — nothing here reaches the real sandbox.
 */
class EldShipmentTrackingTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private string $base = 'https://api.sandbox.withterminal.com/tsp/v1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'subscriptions.require_subscription_for_loads' => false,
            'services.terminal.enabled' => true,
            'services.terminal.secret_key' => 'sk_sandbox_test',
            'services.terminal.publishable_key' => 'pk_sandbox_test',
            'services.terminal.base_url' => $this->base,
            'services.terminal.retry_delay_ms' => 0,
        ]);

        Http::preventStrayRequests();
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
            'status' => 'active',
        ], $attributes));
    }

    /**
     * A booked ELD shipment, not yet started — the state every test in this
     * file begins from, since start()/stop()/track() all act on an already
     * -created shipment rather than creating one themselves.
     */
    private function bookedShipment(User $user, EldConnection $connection, EldVehicle $vehicle, EldDriver $driver, array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::orderedUuid(),
            'tracking_token' => Str::random(48),
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'shipment_no' => 'SHP-TEST-'.Str::random(6),
            'tracking_method' => 'eld',
            'origin' => 'Dallas, TX',
            'destination' => 'Austin, TX',
            'eld_connection_id' => $connection->id,
            'eld_vehicle_id' => $vehicle->id,
            'eld_driver_id' => $driver->id,
            'eld_vehicle_terminal_id' => $vehicle->terminal_id,
            'eld_driver_terminal_id' => $driver->terminal_id,
            'tracking_interval_seconds' => 300,
            'status' => 'draft',
        ], $attributes));
    }

    /**
     * Terminal's answer to GET /vehicles/locations for one vehicle, at a
     * given lat/lng. Matches TerminalClient::latestVehicleLocations()'s
     * actual request shape.
     */
    private function fakeLocation(EldVehicle $vehicle, float $lat, float $lng): void
    {
        Http::fake([
            $this->base.'/vehicles/locations*' => Http::response([
                'results' => [[
                    'provider' => 'samsara',
                    'vehicle' => $vehicle->terminal_id,
                    'locatedAt' => now()->toIso8601ZuluString(),
                    'location' => ['latitude' => $lat, 'longitude' => $lng],
                    'address' => ['formatted' => 'Test Location'],
                    'speed' => 55.0,
                    'heading' => 90,
                    'odometer' => 12345,
                ]],
            ]),
        ]);
    }

    // ─────────────────────────────── start() ───────────────────────────────

    public function test_start_marks_the_shipment_active_and_stamps_tracking_started_at(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver);

        $this->fakeLocation($vehicle, 32.7767, -96.7970);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/shipments/{$shipment->uuid}/eld/start");

        $response->assertOk();

        $shipment->refresh();
        $this->assertSame('active', $shipment->status);
        $this->assertNotNull($shipment->eld_tracking_started_at);
        $this->assertNull($shipment->eld_tracking_stopped_at);
    }

    public function test_start_rejects_a_shipment_that_is_not_set_up_for_eld(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver, [
            'tracking_method' => 'driver_phone',
            'eld_connection_id' => null,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/shipments/{$shipment->uuid}/eld/start")->assertStatus(422);
    }

    public function test_start_rejects_a_shipment_already_being_tracked(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver, [
            'status' => 'active',
            'eld_tracking_started_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/shipments/{$shipment->uuid}/eld/start")->assertStatus(409);
    }

    // ─────────────────────────────── stop() ───────────────────────────────

    public function test_stop_marks_the_shipment_completed(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver, [
            'status' => 'active',
            'eld_tracking_started_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/shipments/{$shipment->uuid}/eld/stop")->assertOk();

        $shipment->refresh();
        $this->assertSame('completed', $shipment->status);
        $this->assertNotNull($shipment->eld_tracking_stopped_at);
    }

    public function test_stop_rejects_a_shipment_that_was_never_started(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/shipments/{$shipment->uuid}/eld/stop")->assertStatus(409);
    }

    // ─────────────────────────────── track() ───────────────────────────────

    public function test_track_returns_the_current_position_once_started(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver);

        $this->fakeLocation($vehicle, 32.7767, -96.7970);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/shipments/{$shipment->uuid}/eld/start")->assertOk();

        $response = $this->getJson("/api/v1/shipments/{$shipment->uuid}/eld/track");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');
        $this->assertNotNull($response->json('data.current'), 'the inline poll on start() should have already saved a position');
        $this->assertEquals(32.7767, (float) $response->json('data.current.latitude'));
    }

    // ──────────────────────────── Milestones ────────────────────────────

    public function test_milestone_advances_to_arrived_at_origin_once_the_truck_is_within_the_geofence(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        // Dallas, TX — the truck's very next ping lands one block away.
        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver, [
            'origin_lat' => 32.7767,
            'origin_lng' => -96.7970,
            'destination_lat' => 30.2672, // Austin — nowhere near the fake ping below
            'destination_lng' => -97.7431,
            'status' => 'active',
            'eld_tracking_started_at' => now()->subMinutes(5),
        ]);

        // Sanity check on the starting state before the poll below changes it.
        $this->assertSame('arrived_at_origin', $shipment->eldMilestone());

        // A ping ~0.1 miles from origin — well inside the 1-mile radius.
        $this->fakeLocation($vehicle, 32.7770, -96.7975);

        app(EldTrackingService::class)->pollConnection($connection);

        $shipment->refresh();
        $this->assertNotNull($shipment->arrived_at_origin_at);
        $this->assertNull($shipment->arrived_at_destination_at);
        $this->assertSame('in_transit', $shipment->eldMilestone());
    }

    public function test_milestone_does_not_advance_when_the_truck_is_far_from_either_point(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver, [
            'origin_lat' => 32.7767,
            'origin_lng' => -96.7970,
            'destination_lat' => 30.2672,
            'destination_lng' => -97.7431,
            'status' => 'active',
            'eld_tracking_started_at' => now()->subMinutes(5),
        ]);

        // New York — nowhere near either origin or destination.
        $this->fakeLocation($vehicle, 40.7128, -74.0060);

        app(EldTrackingService::class)->pollConnection($connection);

        $shipment->refresh();
        $this->assertNull($shipment->arrived_at_origin_at);
        $this->assertNull($shipment->arrived_at_destination_at);
        $this->assertSame('arrived_at_origin', $shipment->eldMilestone(), 'still waiting to reach the first milestone');
    }

    public function test_milestone_is_delivered_once_the_shipment_is_stopped(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver, [
            'status' => 'completed',
            'eld_tracking_started_at' => now()->subHours(3),
            'eld_tracking_stopped_at' => now(),
            'arrived_at_origin_at' => now()->subHours(3),
            'arrived_at_destination_at' => now()->subMinutes(10),
        ]);

        $this->assertSame('delivered', $shipment->eldMilestone());
    }

    public function test_milestone_is_null_before_tracking_has_started(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);

        $shipment = $this->bookedShipment($user, $connection, $vehicle, $driver);

        $this->assertNull($shipment->eldMilestone());
    }
}
