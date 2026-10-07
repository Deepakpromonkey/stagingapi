<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The public tracking link for a driver_phone load — the non-ELD half of
 * PublicShipmentTrackingController. See PublicShipmentTrackingTest for the
 * ELD half, which this file does not touch or duplicate.
 *
 * driver_locations and shipment_journeys belong to a separate app (the
 * driver mobile app) and are never migrated from this repo — see
 * DriverActivityService's own docblock. They exist for real in the shared
 * MySQL database, but not in the sqlite :memory: this suite runs on, so
 * setUp() creates minimal versions of both here, scoped to this test class's
 * connection only. Nothing under database/migrations changes — a real
 * migration here would risk colliding with that other app's own schema
 * ownership of these tables the day it actually runs migrations against the
 * same database.
 */
class PublicShipmentTrackingDriverPhoneTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->char('shipment_uuid', 36)->nullable();
            $table->decimal('lat', 10, 8);
            $table->decimal('lng', 11, 8);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_journeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('current_step')->nullable();
            $table->timestamp('shipper_arrived_at')->nullable();
            $table->timestamp('receiver_arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
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

    private function shipment(User $user, array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::orderedUuid(),
            'tracking_token' => Str::random(48),
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'shipment_no' => 'SHP-TEST-'.Str::random(6),
            'tracking_method' => 'driver_phone',
            'origin' => 'Dallas, TX',
            'destination' => 'Austin, TX',
            'carrier_name' => 'Very Distinctive Carrier Name LLC',
            'driver_phone_1' => '+19995551234',
            'status' => 'draft',
        ], $attributes));
    }

    private function ping(Shipment $shipment, array $attributes = []): void
    {
        DB::table('driver_locations')->insert(array_merge([
            'driver_id' => 501,
            'shipment_uuid' => $shipment->uuid,
            'lat' => 32.7767,
            'lng' => -96.7970,
            'accuracy' => 12.5,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function journey(Shipment $shipment, array $attributes = []): void
    {
        DB::table('shipment_journeys')->insert(array_merge([
            'shipment_id' => $shipment->id,
            'driver_id' => 501,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    // ─────────────────────────────── Tests ───────────────────────────────

    public function test_a_draft_driver_phone_shipment_reports_pending_with_no_position(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'pending');
        $response->assertJsonPath('data.current', null);
        $response->assertJsonPath('data.milestone', null);
    }

    public function test_an_active_driver_phone_shipment_reports_in_transit_with_its_current_position(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user, ['status' => 'active']);

        $this->ping($shipment, ['lat' => 32.7767, 'lng' => -96.7970]);
        $this->ping($shipment, ['lat' => 32.8000, 'lng' => -96.8200]); // newest

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'in_transit');
        $this->assertEquals(32.8000, (float) $response->json('data.current.latitude'));
        $this->assertEquals(-96.8200, (float) $response->json('data.current.longitude'));
        $this->assertNotNull($response->json('data.current.last_updated_at'));
    }

    public function test_milestone_reflects_shipment_journeys_shipper_and_receiver_arrival(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);

        // No journey row at all yet — still the first stage.
        $notStarted = $this->shipment($user, ['status' => 'active']);
        $r1 = $this->getJson("/api/v1/public/tracking/{$notStarted->tracking_token}");
        $r1->assertJsonPath('data.milestone', 'arrived_at_origin');

        // Shipper arrival recorded — moved to in_transit.
        $pickedUp = $this->shipment($user, ['status' => 'active']);
        $this->journey($pickedUp, ['shipper_arrived_at' => now()->subHour()]);
        $r2 = $this->getJson("/api/v1/public/tracking/{$pickedUp->tracking_token}");
        $r2->assertJsonPath('data.milestone', 'in_transit');

        // Receiver arrival recorded too — final in-progress stage.
        $arrived = $this->shipment($user, ['status' => 'active']);
        $this->journey($arrived, [
            'shipper_arrived_at' => now()->subHours(3),
            'receiver_arrived_at' => now()->subMinutes(10),
        ]);
        $r3 = $this->getJson("/api/v1/public/tracking/{$arrived->tracking_token}");
        $r3->assertJsonPath('data.milestone', 'arrived_at_destination');
    }

    public function test_arrived_at_origin_and_destination_timestamps_stay_null_for_driver_phone(): void
    {
        // Those two columns are ELD-only - a driver_phone load's real
        // arrival times live on shipment_journeys, exposed only through
        // `milestone`, not through these two fields.
        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user, ['status' => 'active']);
        $this->journey($shipment, ['shipper_arrived_at' => now(), 'receiver_arrived_at' => now()]);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertJsonPath('data.arrived_at_origin_at', null);
        $response->assertJsonPath('data.arrived_at_destination_at', null);
    }

    public function test_a_completed_driver_phone_shipment_reports_delivered_and_stops_showing_a_live_position(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user, ['status' => 'completed']);
        $this->ping($shipment);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'delivered');
        $this->assertNotNull($response->json('data.delivered_at'));
        $this->assertArrayNotHasKey('current', $response->json('data'));
    }

    public function test_a_cancelled_driver_phone_shipment_reports_cancelled(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user, ['status' => 'cancelled']);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'cancelled');
    }

    public function test_the_public_payload_never_leaks_driver_identifying_information(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user, [
            'status' => 'active',
            'carrier_name' => 'Very Distinctive Carrier Name LLC',
            'driver_phone_1' => '+19995551234',
        ]);
        $this->ping($shipment, ['driver_id' => 999888]);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('Very Distinctive Carrier Name', $body);
        $this->assertStringNotContainsString('+19995551234', $body);
        $this->assertStringNotContainsString('999888', $body, 'the driver_id behind a ping should never reach the public payload');
        $this->assertStringNotContainsString($shipment->uuid, $body, 'the internal shipment uuid should never appear — only the tracking_token is public');
    }

    /**
     * The one guarantee that actually matters here: neither the driver
     * app's tables being unreachable, nor simply not existing yet in a
     * given environment, may ever turn the one endpoint a stranger can call
     * into a 500.
     */
    public function test_missing_driver_app_tables_degrade_to_no_position_instead_of_a_server_error(): void
    {
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('shipment_journeys');

        $company = $this->company();
        $user = $this->brokerUser($company);
        $shipment = $this->shipment($user, ['status' => 'active']);

        $response = $this->getJson("/api/v1/public/tracking/{$shipment->tracking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.state', 'in_transit');
        $response->assertJsonPath('data.current', null);
        $response->assertJsonPath('data.milestone', 'arrived_at_origin');
    }
}
