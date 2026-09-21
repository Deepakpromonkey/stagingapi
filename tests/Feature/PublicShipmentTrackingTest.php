<?php

namespace Tests\Feature;

use App\Models\CarrierConnectRequest;
use App\Models\Company;
use App\Models\Eld\EldConnection;
use App\Models\Eld\EldDriver;
use App\Models\Eld\EldLocation;
use App\Models\Eld\EldVehicle;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The public tracking link — the one endpoint in the whole API a stranger is
 * expected to call. No Sanctum::actingAs anywhere in this file on purpose:
 * every request here goes out exactly as an unauthenticated customer's
 * browser would send it, token in the URL and nothing else.
 */
class PublicShipmentTrackingTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
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
        return User::create([
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
            'phone' => '+12125555555',
            'status' => 'active',
        ], $attributes));
    }

    private function shipment(User $user, EldConnection $connection, EldVehicle $vehicle, EldDriver $driver, array $attributes = []): Shipment
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
            'carrier_name' => $connection->carrier_legal_name,
            'carrier_dot' => $connection->carrier_dot_number,
            'driver_phone_1' => $driver->phone,
            'eld_connection_id' => $connection->id,
            'eld_vehicle_id' => $vehicle->id,
            'eld_driver_id' => $driver->id,
            'eld_vehicle_terminal_id' => $vehicle->terminal_id,
            'eld_driver_terminal_id' => $driver->terminal_id,
            'tracking_interval_seconds' => 300,
            'status' => 'draft',
        ], $attributes));
    }

    // ─────────────────────────────── Tests ───────────────────────────────

    public function test_an_invalid_token_returns_404_without_revealing_whether_it_ever_existed(): void
    {
        $response = $this->getJson('/api/v1/public/tracking/this-token-does-not-exist');

        $response->assertNotFound();
        $response->assertJsonPath('status', false);
    }

    public function test_a_draft_shipment_reports_pending_with_no_position(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->shipment($user, $connection, $vehicle, $driver);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'pending');
        $response->assertJsonPath('data.current', null);
        $response->assertJsonPath('data.shipment_no', $shipment->shipment_no);
    }

    public function test_an_active_shipment_reports_in_transit_with_its_current_position(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->shipment($user, $connection, $vehicle, $driver, [
            'status' => 'active',
            'eld_tracking_started_at' => now()->subMinutes(20),
        ]);

        EldLocation::create([
            'eld_connection_id' => $connection->id,
            'vehicle_terminal_id' => $vehicle->terminal_id,
            'located_at' => now()->subMinutes(2),
            'latitude' => 32.7767,
            'longitude' => -96.7970,
            'description' => 'Downtown Dallas, TX',
        ]);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'in_transit');
        $this->assertEquals(32.7767, (float) $response->json('data.current.latitude'));
    }

    public function test_a_completed_shipment_reports_delivered_and_stops_showing_a_live_position(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->shipment($user, $connection, $vehicle, $driver, [
            'status' => 'completed',
            'eld_tracking_started_at' => now()->subHours(3),
            'eld_tracking_stopped_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'delivered');
        $this->assertNotNull($response->json('data.delivered_at'));
        $this->assertArrayNotHasKey('current', $response->json('data'));
    }

    public function test_a_cancelled_shipment_reports_cancelled(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection);
        $shipment = $this->shipment($user, $connection, $vehicle, $driver, ['status' => 'cancelled']);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'cancelled');
    }

    /**
     * The whole point of this endpoint's design — see its class docblock.
     * Nothing here should ever be able to identify the carrier, the driver,
     * or this broker's internal ids, no matter what state the shipment is
     * in. Checked against the raw response body, not a specific key list,
     * so a future field added carelessly still fails this test.
     */
    public function test_the_public_payload_never_leaks_carrier_or_driver_identifying_information(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $connection = $this->eldConnection($company, ['carrier_legal_name' => 'Very Distinctive Carrier Name LLC']);
        $vehicle = $this->eldVehicle($connection);
        $driver = $this->eldDriver($connection, ['phone' => '+19995551234', 'first_name' => 'Distinctivename']);
        $shipment = $this->shipment($user, $connection, $vehicle, $driver, [
            'status' => 'active',
            'eld_tracking_started_at' => now()->subMinutes(5),
        ]);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('Very Distinctive Carrier Name', $body);
        $this->assertStringNotContainsString('Distinctivename', $body);
        $this->assertStringNotContainsString('+19995551234', $body);
        $this->assertStringNotContainsString($connection->uuid, $body, 'the internal connection uuid should never appear on the public page');
        $this->assertStringNotContainsString($shipment->uuid, $body, 'the internal shipment uuid should never appear — only the tracking_token is public');
    }
}
