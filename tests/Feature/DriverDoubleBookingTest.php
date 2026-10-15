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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * A driver can't be put on two loads at once.
 *
 * Booking a load (POST /shipments) and saving its stops (POST
 * /shipments/{uuid}/stops) both refuse it, 422 with a `driver_schedule` error,
 * when one of its drivers is already on another open load of the company at an
 * overlapping time.
 *
 * The load every test books against, unless it says otherwise: driver
 * 555-010-2020 from Oct 20, 2026 9:00 AM Central to Oct 22, 2026 5:00 PM
 * Eastern - 14:00 to 21:00 UTC, daylight saving on both ends.
 */
class DriverDoubleBookingTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const DRIVER = '5550102020';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'subscriptions.require_subscription_for_loads' => false,
            'driver_app.notify_on_new_load' => false,
        ]);

        Http::preventStrayRequests();

        $this->user = $this->broker($this->company());

        Sanctum::actingAs($this->user);
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

    private function broker(Company $company): User
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
     * A load already on the books, with its trip sheet's stops - by default
     * the one described above.
     */
    private function bookedLoad(array $attributes = [], ?array $stops = null): Shipment
    {
        $load = Shipment::create(array_merge([
            'uuid' => (string) Str::orderedUuid(),
            'tracking_token' => Str::random(48),
            'company_id' => $this->user->company_id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'shipment_no' => 'SHP-TEST-'.Str::random(6),
            'pro_number' => 'PRO-A1',
            'tracking_method' => 'driver_phone',
            'driver_phone_1' => self::DRIVER,
            'status' => 'active',
        ], $attributes));

        $stops ??= [
            ['stop_type' => 'Pickup', 'start_date' => '2026-10-20', 'start_time' => '09:00', 'start_timezone' => 'Central'],
            ['stop_type' => 'Delivery', 'end_date' => '2026-10-22', 'end_time' => '17:00', 'end_timezone' => 'Eastern'],
        ];

        foreach (array_values($stops) as $i => $stop) {
            $load->stops()->create(['stop_number' => $i + 1, 'address' => 'Stop '.($i + 1)] + $stop);
        }

        return $load;
    }

    /**
     * The pickup and delivery the trip sheet sends with POST /shipments - its
     * first stop's start window and its last stop's end window.
     *
     * @param  array{0: string, 1: string, 2: string}  $pickup  [date, time, timezone]
     * @param  array{0: string, 1: string, 2: string}  $delivery
     */
    private function schedule(array $pickup, array $delivery): array
    {
        return [
            'pickup_date' => $pickup[0], 'pickup_time' => $pickup[1], 'pickup_timezone' => $pickup[2],
            'delivery_date' => $delivery[0], 'delivery_time' => $delivery[1], 'delivery_timezone' => $delivery[2],
        ];
    }

    private function book(array $payload)
    {
        return $this->postJson('/api/v1/shipments', [
            'tracking_method' => 'driver_phone',
            'country_code_1' => '+1',
        ] + $payload);
    }

    /** A stop as the trip sheet posts it: "" for the window it doesn't have. */
    private function stop(string $type, array $timing): array
    {
        return array_merge([
            'stop_type' => $type,
            'address' => $type === 'Pickup' ? '4200 Diplomacy Rd, Dallas, TX 75261, USA' : '2075 W Buckeye Rd, Phoenix, AZ 85009, USA',
            'latitude' => $type === 'Pickup' ? 32.834 : 33.436,
            'longitude' => $type === 'Pickup' ? -96.944 : -112.103,
            'start_date' => '', 'start_time' => '', 'start_timezone' => '',
            'end_date' => '', 'end_time' => '', 'end_timezone' => '',
        ], $timing);
    }

    private function saveStops(string $uuid, array $stops)
    {
        return $this->postJson("/api/v1/shipments/{$uuid}/stops", ['stops_data' => json_encode($stops)]);
    }

    private function assertRefused($response, Shipment $clashingLoad): void
    {
        $response->assertStatus(422)->assertJsonValidationErrors('driver_schedule');

        $message = $response->json('errors.driver_schedule.0');

        $this->assertStringContainsString("is already on load {$clashingLoad->shipment_no}", $message);
        $this->assertSame($message, $response->json('message'), 'the forms show `message`');
    }

    // ───────────────────────────── Booking ─────────────────────────────

    public function test_a_driver_already_on_an_overlapping_load_is_refused_and_nothing_is_created(): void
    {
        $booked = $this->bookedLoad();

        $response = $this->book([
            'driver_phone_1' => '(555) 010-2020',
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']));

        $this->assertRefused($response, $booked);
        $this->assertSame(
            "The driver on (555) 010-2020 is already on load {$booked->shipment_no} (PRO PRO-A1) "
                .'from Oct 20, 2026 9:00 AM CDT to Oct 22, 2026 5:00 PM EDT. '
                ."Change this load's dates, or assign another driver.",
            $response->json('message')
        );

        $this->assertSame(1, Shipment::count(), 'the refused load must not exist');
    }

    public function test_the_same_driver_is_matched_however_the_number_was_typed(): void
    {
        $booked = $this->bookedLoad(['driver_phone_1' => '+1 555.010.2020']);

        foreach (['5550102020', '+15550102020', '555-010-2020', '(555) 010-2020'] as $typed) {
            $response = $this->book([
                'driver_phone_1' => $typed,
            ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']));

            $this->assertRefused($response, $booked);
        }
    }

    public function test_every_driver_slot_counts_on_both_loads(): void
    {
        $booked = $this->bookedLoad(['driver_phone_1' => '5550109999', 'driver_phone_2' => self::DRIVER, 'team_load' => true]);

        $overlapping = $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']);

        $this->assertRefused($this->book(['driver_phone_1' => self::DRIVER] + $overlapping), $booked);
        $this->assertRefused($this->book(['driver_phone_1' => '5550107777', 'driver_phone_3' => self::DRIVER] + $overlapping), $booked);
    }

    public function test_the_driver_can_take_a_load_that_starts_when_the_last_one_ends(): void
    {
        $this->bookedLoad();

        // 4 PM Central is 5 PM Eastern, the moment the booked load ends.
        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-22', '15:59', 'Central'], ['2026-10-23', '12:00', 'Central']))->assertStatus(422);

        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-22', '16:00', 'Central'], ['2026-10-23', '12:00', 'Central']))->assertCreated();

        // And one that ends before it starts.
        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-17', '08:00', 'Central'], ['2026-10-19', '12:00', 'Central']))->assertCreated();
    }

    public function test_another_driver_can_take_the_same_dates(): void
    {
        $this->bookedLoad();

        $this->book([
            'driver_phone_1' => '5550109999',
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']))->assertCreated();
    }

    public function test_a_completed_or_cancelled_load_no_longer_holds_its_driver(): void
    {
        $this->bookedLoad(['status' => 'completed']);
        $this->bookedLoad(['status' => 'cancelled']);

        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']))->assertCreated();
    }

    public function test_a_draft_load_holds_its_driver(): void
    {
        // Booked, but the driver hasn't started it yet.
        $booked = $this->bookedLoad(['status' => 'draft']);

        $this->assertRefused($this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central'])), $booked);
    }

    public function test_another_companys_loads_are_not_looked_at(): void
    {
        $other = $this->broker($this->company());

        $this->bookedLoad(['company_id' => $other->company_id, 'created_by' => $other->id, 'updated_by' => $other->id]);

        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']))->assertCreated();
    }

    public function test_a_pickup_with_no_time_holds_its_whole_day(): void
    {
        // The trip sheet's pickup time is optional.
        $booked = $this->bookedLoad([], [
            ['stop_type' => 'Pickup', 'start_date' => '2026-10-26'],
            ['stop_type' => 'Delivery', 'end_date' => '2026-10-27', 'end_time' => '17:00', 'end_timezone' => 'Central'],
        ]);

        $response = $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-25', '08:00', 'Central'], ['2026-10-26', '07:00', 'Central']));

        $this->assertRefused($response, $booked);
        $this->assertStringContainsString('from Oct 26, 2026 to Oct 27, 2026 5:00 PM CDT', $response->json('message'));

        // Over by the time that day starts.
        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-24', '08:00', 'Central'], ['2026-10-25', '23:00', 'Central']))->assertCreated();
    }

    public function test_a_load_with_no_dates_yet_is_not_checked_until_its_stops_are_saved(): void
    {
        $this->bookedLoad();

        $this->book(['driver_phone_1' => self::DRIVER])->assertCreated();
    }

    public function test_a_trip_sheet_whose_stops_were_never_saved_holds_nobody(): void
    {
        // Booked with a pickup and delivery, then the save was given up on
        // before its stops went in - and there is no cancelling it.
        $this->bookedLoad([
            'pickup_date' => '2026-10-20', 'pickup_time' => '09:00', 'pickup_timezone' => 'Central',
            'delivery_date' => '2026-10-22', 'delivery_time' => '17:00', 'delivery_timezone' => 'Eastern',
        ], []);

        $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']))->assertCreated();
    }

    // ─────────────────────────── Saving the stops ───────────────────────────

    public function test_the_stops_are_checked_too_and_nothing_is_saved_when_they_clash(): void
    {
        $booked = $this->bookedLoad();

        // Booked without a pickup and delivery, as an older page would.
        $uuid = $this->book(['driver_phone_1' => self::DRIVER])->assertCreated()->json('data.uuid');
        $load = Shipment::where('uuid', $uuid)->firstOrFail();

        $this->assertRefused($this->saveStops($uuid, [
            $this->stop('Pickup', ['start_date' => '2026-10-21', 'start_time' => '08:00', 'start_timezone' => 'Central']),
            $this->stop('Delivery', ['end_date' => '2026-10-23', 'end_time' => '12:00', 'end_timezone' => 'Central']),
        ]), $booked);

        $this->assertSame(0, $load->stops()->count());

        // Fixed, the same load takes them.
        $this->saveStops($uuid, [
            $this->stop('Pickup', ['start_date' => '2026-10-24', 'start_time' => '08:00', 'start_timezone' => 'Central']),
            $this->stop('Delivery', ['end_date' => '2026-10-25', 'end_time' => '12:00', 'end_timezone' => 'Central']),
        ])->assertOk();

        $this->assertSame(2, $load->stops()->count());
    }

    public function test_an_intermediate_stop_counts(): void
    {
        $booked = $this->bookedLoad();

        $uuid = $this->book(['driver_phone_1' => self::DRIVER])->assertCreated()->json('data.uuid');

        $this->assertRefused($this->saveStops($uuid, [
            $this->stop('Pickup', ['start_date' => '2026-10-15', 'start_time' => '08:00', 'start_timezone' => 'Central']),
            $this->stop('Intermediate', ['end_date' => '2026-10-21', 'end_time' => '10:00', 'end_timezone' => 'Central']),
            $this->stop('Delivery', ['end_date' => '2026-10-16', 'end_time' => '12:00', 'end_timezone' => 'Central']),
        ]), $booked);
    }

    public function test_a_trip_sheet_does_not_clash_with_its_own_load(): void
    {
        $uuid = $this->book([
            'driver_phone_1' => self::DRIVER,
        ] + $this->schedule(['2026-10-20', '09:00', 'Central'], ['2026-10-22', '17:00', 'Eastern']))
            ->assertCreated()
            ->json('data.uuid');

        $this->saveStops($uuid, [
            $this->stop('Pickup', ['start_date' => '2026-10-20', 'start_time' => '09:00', 'start_timezone' => 'Central']),
            $this->stop('Delivery', ['end_date' => '2026-10-22', 'end_time' => '17:00', 'end_timezone' => 'Eastern']),
        ])->assertOk();

        // The pickup and delivery it was booked with are kept on the load.
        $load = Shipment::where('uuid', $uuid)->firstOrFail();

        $this->assertSame('2026-10-20', $load->pickup_date->toDateString());
        $this->assertSame('17:00', $load->delivery_time);
        $this->assertSame('Eastern', $load->delivery_timezone);
    }

    // ─────────────────────────────── ELD ───────────────────────────────

    public function test_an_eld_driver_is_held_by_their_load_even_with_no_phone(): void
    {
        $connection = EldConnection::create([
            'uuid' => Str::uuid(),
            'carrier_dot_number' => '1234567',
            'carrier_legal_name' => "Frank's Trucking",
            'provider' => 'Samsara',
            'terminal_connection_id' => 'conn_test_'.Str::random(10),
            'connection_token' => 'con_tkn_test_'.Str::random(10),
            'status' => EldConnection::STATUS_CONNECTED,
            'sync_status' => EldConnection::SYNC_COMPLETED,
        ]);

        CarrierConnectRequest::create([
            'uuid' => Str::uuid(),
            'company_id' => $this->user->company_id,
            'eld_connection_id' => $connection->id,
            'carrier_dot_number' => $connection->carrier_dot_number,
            'carrier_row_id' => '99001',
            'carrier_legal_name' => $connection->carrier_legal_name,
            'carrier_email' => 'frank@franks.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'sent_on' => now(),
        ]);

        $vehicle = EldVehicle::create([
            'eld_connection_id' => $connection->id,
            'terminal_id' => 'vcl_'.Str::random(20),
            'name' => 'Truck 1',
            'status' => 'active',
        ]);

        $driver = fn (string $name) => EldDriver::create([
            'eld_connection_id' => $connection->id,
            'terminal_id' => 'drv_'.Str::random(20),
            'first_name' => $name,
            'last_name' => 'Wilson',
            'phone' => null,
            'status' => 'active',
        ]);

        $arthur = $driver('Arthur');
        $bea = $driver('Bea');

        $booking = fn (EldDriver $who, array $pickup, array $delivery) => $this->postJson('/api/v1/shipments', [
            'tracking_method' => 'eld',
            'origin' => 'Dallas, TX',
            'destination' => 'Austin, TX',
            'eld_connection_uuid' => $connection->uuid,
            'eld_vehicle_terminal_id' => $vehicle->terminal_id,
            'eld_driver_terminal_id' => $who->terminal_id,
        ] + $this->schedule($pickup, $delivery));

        $first = $booking($arthur, ['2026-10-20', '09:00', 'Central'], ['2026-10-22', '17:00', 'Eastern'])->assertCreated();

        $this->assertRefused(
            $booking($arthur, ['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']),
            Shipment::where('uuid', $first->json('data.uuid'))->firstOrFail()
        );

        $this->assertStringStartsWith('This driver is already on load', $this->postJson('/api/v1/shipments', [
            'tracking_method' => 'eld',
            'origin' => 'Dallas, TX',
            'destination' => 'Austin, TX',
            'eld_connection_uuid' => $connection->uuid,
            'eld_vehicle_terminal_id' => $vehicle->terminal_id,
            'eld_driver_terminal_id' => $arthur->terminal_id,
        ] + $this->schedule(['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central']))->json('message'));

        $booking($bea, ['2026-10-21', '08:00', 'Central'], ['2026-10-23', '12:00', 'Central'])->assertCreated();
    }
}
