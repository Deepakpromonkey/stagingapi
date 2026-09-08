<?php

namespace Tests\Feature;

use App\Models\Eld\EldConnection;
use App\Models\Eld\EldDriver;
use App\Models\Eld\EldHosLog;
use App\Models\Eld\EldLocation;
use App\Models\Eld\EldSyncCheckpoint;
use App\Models\Eld\EldVehicle;
use App\Services\Eld\EldSyncService;
use App\Services\Eld\TerminalRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Pulling a connection's fleet out of Terminal.
 *
 * Terminal bills for data synced rather than for vehicles and drivers held, so
 * the expensive mistakes here are all the same mistake: reading more than once.
 * These cover the three guards against it — paging that ends where Terminal
 * says it ends, checkpoints that advance on Terminal's clock, and unique keys
 * that absorb a re-read of an overlapping window.
 */
class EldFleetSyncTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private string $base = 'https://api.sandbox.withterminal.com/tsp/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.terminal.enabled' => true,
            'services.terminal.secret_key' => 'sk_sandbox_test',
            'services.terminal.publishable_key' => 'pk_sandbox_test',
            'services.terminal.base_url' => $this->base,
            'services.terminal.backfill_days' => 0,
            'services.terminal.lookback_hours' => 48,
        ]);
    }

    private function connection(array $attributes = []): EldConnection
    {
        return EldConnection::create(array_merge([
            'uuid' => Str::uuid(),
            'carrier_dot_number' => '1234567',
            'terminal_connection_id' => 'conn_test',
            'connection_token' => 'con_tkn_test',
            'status' => EldConnection::STATUS_CONNECTED,
            'sync_status' => EldConnection::SYNC_PENDING,
        ], $attributes));
    }

    /**
     * A fleet spread over two pages, so a walk that stopped on a short page
     * would lose the tail of it.
     */
    private function fakeFleet(): void
    {
        Http::fake([
            // Registered first: the broad /vehicles* pattern below would
            // otherwise answer the per-vehicle locations call too.
            $this->base.'/vehicles/*/locations*' => Http::response([
                'results' => [[
                    'id' => 'vcl_loc_1',
                    'vehicle' => 'vcl_1',
                    'locatedAt' => '2026-09-06T09:30:00.000Z',
                    'location' => ['latitude' => 37.7749295, 'longitude' => -122.4194155],
                    'address' => ['formatted' => '1.5 miles from Austin, TX'],
                    'heading' => 25,
                    'speed' => 62.5,
                ]],
            ]),

            $this->base.'/vehicles?*cursor=page2*' => Http::response([
                'results' => [[
                    'id' => 'vcl_2',
                    'vin' => '2HGCM82633A004352',
                    'name' => 'Blue Two',
                    'licensePlate' => ['state' => 'TX', 'number' => 'XYZ-9999'],
                    'status' => 'active',
                    'metadata' => ['modifiedAt' => '2026-09-07T12:00:00.000Z'],
                ]],
            ]),

            $this->base.'/vehicles*' => Http::response([
                'results' => [[
                    'id' => 'vcl_1',
                    'vin' => '1HGCM82633A004352',
                    'name' => 'Big Red',
                    'make' => 'Peterbilt',
                    'model' => 'Model 579',
                    'year' => 2016,
                    'licensePlate' => ['state' => 'TN', 'number' => 'ABC-1234'],
                    'status' => 'active',
                    'metadata' => ['modifiedAt' => '2026-09-06T10:00:00.000Z'],
                ]],
                'next' => 'page2',
            ]),

            $this->base.'/drivers*' => Http::response([
                'results' => [[
                    'id' => 'drv_1',
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'username' => 'jdoe',
                    'status' => 'active',
                    'metadata' => ['modifiedAt' => '2026-09-06T11:00:00.000Z'],
                ]],
            ]),

            $this->base.'/hos/logs*' => Http::response([
                'results' => [[
                    'id' => 'hos_1',
                    'driver' => 'drv_1',
                    'vehicle' => 'vcl_1',
                    'type' => 'driving',
                    'startTime' => '2026-09-06T08:00:00.000Z',
                    'endTime' => '2026-09-06T14:00:00.000Z',
                    'metadata' => ['modifiedAt' => '2026-09-06T15:00:00.000Z'],
                ]],
            ]),
        ]);
    }

    public function test_it_walks_every_page_rather_than_stopping_on_a_short_one(): void
    {
        $this->fakeFleet();

        app(EldSyncService::class)->sync($this->connection(), true);

        // Two pages, one vehicle each. Providers routinely return fewer rows
        // than the limit asked for, so only `next` can end the walk.
        $this->assertSame(2, EldVehicle::count());
    }

    public function test_it_maps_the_fields_a_broker_actually_reads(): void
    {
        $this->fakeFleet();

        app(EldSyncService::class)->sync($this->connection(), true);

        $vehicle = EldVehicle::where('terminal_id', 'vcl_1')->firstOrFail();

        // licensePlate is an object of state and number, not a string.
        $this->assertSame('ABC-1234', $vehicle->license_plate);
        $this->assertSame('1HGCM82633A004352', $vehicle->vin);

        $this->assertSame('jdoe', EldDriver::firstOrFail()->username);

        $log = EldHosLog::firstOrFail();
        $this->assertSame('driving', $log->duty_status);
        $this->assertSame('drv_1', $log->driver_terminal_id);

        // Derived where the provider does not report it — and never negative,
        // whichever way round Carbon subtracts.
        $this->assertSame(21600, $log->duration_seconds);

        $location = EldLocation::firstOrFail();
        $this->assertSame('1.5 miles from Austin, TX', $location->description);
        $this->assertEquals(37.7749295, (float) $location->latitude);
    }

    public function test_the_checkpoint_follows_terminals_clock_not_ours(): void
    {
        $this->fakeFleet();

        app(EldSyncService::class)->sync($this->connection(), true);

        // The newest record the pass actually saw — page two's — rather than
        // the time the run happened, which would skip anything the provider
        // was still processing.
        $this->assertSame(
            '2026-09-07 12:00:00',
            EldSyncCheckpoint::where('resource', 'vehicles')->firstOrFail()->synced_through->toDateTimeString()
        );
    }

    public function test_re_reading_an_overlapping_window_updates_rather_than_duplicates(): void
    {
        $this->fakeFleet();

        $connection = $this->connection();
        $sync = app(EldSyncService::class);

        $sync->sync($connection, true);
        $sync->sync($connection->refresh(), false);

        $this->assertSame(2, EldVehicle::count());
        $this->assertSame(1, EldDriver::count());
        $this->assertSame(1, EldHosLog::count());
        $this->assertSame(1, EldLocation::count());
    }

    public function test_it_records_the_fleet_size_the_onboarding_tile_reports(): void
    {
        $this->fakeFleet();

        $connection = $this->connection();

        app(EldSyncService::class)->sync($connection, true);

        $connection->refresh();

        $this->assertSame(2, $connection->vehicle_count);
        $this->assertSame(1, $connection->driver_count);
        $this->assertSame(EldConnection::SYNC_COMPLETED, $connection->sync_status);
    }

    public function test_a_failed_pass_leaves_its_checkpoint_where_it_was(): void
    {
        Http::fake([$this->base.'/*' => Http::response([], 503)]);

        $connection = $this->connection();

        try {
            app(EldSyncService::class)->sync($connection, true);
            $this->fail('A failing Terminal should have thrown.');
        } catch (TerminalRequestException) {
            // Expected.
        }

        $connection->refresh();

        $this->assertSame(EldConnection::SYNC_FAILED, $connection->sync_status);
        $this->assertNotNull($connection->last_sync_error);

        // Advancing here would skip the window that was never read, and
        // nothing would ever come back for it.
        $this->assertNull(EldSyncCheckpoint::where('resource', 'vehicles')->first()?->synced_through);
    }

    public function test_an_archived_connection_is_left_alone(): void
    {
        Http::fake();

        $connection = $this->connection([
            'status' => EldConnection::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);

        app(EldSyncService::class)->sync($connection);

        // Archive stops the sync and the meter while keeping the history.
        Http::assertNothingSent();
        $this->assertSame(0, EldVehicle::count());
    }
}
