<?php

namespace Tests\Feature;

use App\Jobs\SyncEldConnection;
use App\Models\Company;
use App\Models\CarrierConnectRequest;
use App\Models\EldConnection;
use App\Models\User;
use App\Services\Eld\EldSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The Terminal (ELD) onboarding step and the sync behind it.
 *
 * Terminal itself is faked throughout — these assert our half of the contract:
 * that the link carries a state we later insist on, that an exchange becomes a
 * stored connection, that a webhook without a valid signature is refused, and
 * that a sync lands the fleet in our own tables.
 */
class EldTerminalIntegrationTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const WEBHOOK_SECRET = 'whsec_c2VjcmV0LWtleS1mb3ItdGVzdGluZy1vbmx5';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'terminal.enabled' => true,
            'terminal.publishable_key' => 'pk_sandbox_test',
            'terminal.secret_key' => 'sk_sandbox_test',
            'terminal.environment' => 'sandbox',
            'terminal.webhook_secret' => self::WEBHOOK_SECRET,
            'app.frontend_url' => 'https://app.dollartraq.test',
        ]);
    }

    protected function connectRequest(): CarrierConnectRequest
    {
        $company = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);

        $user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam@northwind.test',
            'password' => Hash::make('secret-password'),
            'status' => true,
            'is_owner' => true,
        ]);

        return CarrierConnectRequest::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'carrier_row_id' => (string) Str::uuid(),
            'carrier_dot_number' => '1234567',
            'carrier_legal_name' => 'Acme Trucking LLC',
            'carrier_email' => 'dispatch@acmetrucking.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_NEW,
            'sent_on' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Opening Terminal Link
    // ─────────────────────────────────────────────────────────────────────

    public function test_it_hands_back_a_link_url_and_remembers_the_state(): void
    {
        $connectRequest = $this->connectRequest();

        $response = $this->postJson('/api/v1/carrier-connect/eld/connect', [
            'token' => $connectRequest->token,
        ])->assertOk();

        $url = $response->json('data.url');

        $this->assertStringStartsWith('https://link.sandbox.withterminal.com/?', $url);
        $this->assertStringContainsString('key=pk_sandbox_test', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // The state in the URL is the one we will demand back, and the carrier
        // must land on their own onboarding rather than anywhere else.
        $this->assertSame($connectRequest->fresh()->eld_link_state, $query['state']);
        $this->assertSame((string) $connectRequest->uuid, $query['external_id']);
        $this->assertStringContainsString('/carrier/connect/'.$connectRequest->token, $query['redirect_url']);
    }

    public function test_it_refuses_to_start_when_terminal_is_not_configured(): void
    {
        config(['terminal.enabled' => false]);

        $connectRequest = $this->connectRequest();

        $this->postJson('/api/v1/carrier-connect/eld/connect', [
            'token' => $connectRequest->token,
        ])->assertStatus(503);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Coming back from Terminal Link
    // ─────────────────────────────────────────────────────────────────────

    public function test_it_exchanges_the_public_token_and_stores_the_connection(): void
    {
        Bus::fake();

        $connectRequest = $this->connectRequest();
        $connectRequest->forceFill(['eld_link_state' => 'state-abc'])->save();

        Http::fake([
            '*/public-token/exchange' => Http::response([
                'connectionId' => 'conn_01TESTCONNECTION',
                'connectionToken' => 'con_tkn_secret',
            ]),
            '*/connections/current' => Http::response([
                'id' => 'conn_01TESTCONNECTION',
                'status' => 'connected',
                'provider' => ['code' => 'motive', 'name' => 'Motive'],
                'account' => ['name' => 'Acme Trucking LLC', 'dotNumbers' => ['1234567']],
            ]),
        ]);

        $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $connectRequest->token,
            'public_token' => 'pub_tkn_from_redirect',
            'state' => 'state-abc',
        ])->assertOk();

        $connection = EldConnection::firstOrFail();

        $this->assertSame('conn_01TESTCONNECTION', $connection->terminal_connection_id);
        $this->assertSame('con_tkn_secret', $connection->connection_token);
        $this->assertSame('Motive', $connection->provider_name);

        $connectRequest->refresh();

        $this->assertSame($connection->id, $connectRequest->eld_connection_id);
        $this->assertNotNull($connectRequest->eld_connected_at);

        // Spent, so the same redirect cannot be replayed.
        $this->assertNull($connectRequest->eld_link_state);

        Bus::assertDispatched(SyncEldConnection::class);
    }

    public function test_it_rejects_a_redirect_whose_state_does_not_match(): void
    {
        Bus::fake();
        Http::fake();

        $connectRequest = $this->connectRequest();
        $connectRequest->forceFill(['eld_link_state' => 'the-real-state'])->save();

        $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $connectRequest->token,
            'public_token' => 'pub_tkn_stolen',
            'state' => 'a-different-state',
        ])->assertStatus(422);

        $this->assertSame(0, EldConnection::count());

        // Nothing was exchanged, so no telematics account was ever reachable.
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_the_connection_token_never_leaves_the_api(): void
    {
        Bus::fake();

        $connectRequest = $this->connectRequest();
        $connectRequest->forceFill(['eld_link_state' => 'state-abc'])->save();

        Http::fake([
            '*/public-token/exchange' => Http::response([
                'connectionId' => 'conn_01TESTCONNECTION',
                'connectionToken' => 'con_tkn_secret',
            ]),
            '*/connections/current' => Http::response([
                'id' => 'conn_01TESTCONNECTION',
                'status' => 'connected',
                'provider' => ['code' => 'motive', 'name' => 'Motive'],
            ]),
        ]);

        $response = $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $connectRequest->token,
            'public_token' => 'pub_tkn_from_redirect',
            'state' => 'state-abc',
        ])->assertOk();

        $this->assertStringNotContainsString('con_tkn_secret', $response->getContent());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Skipping
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_carrier_can_skip_the_eld_step(): void
    {
        $connectRequest = $this->connectRequest();

        $this->postJson('/api/v1/carrier-connect/skip', [
            'token' => $connectRequest->token,
            'step' => 'eld',
        ])->assertOk();

        $connectRequest->refresh();

        $this->assertNotNull($connectRequest->eld_skipped_at);

        // A skip is not a connection, and must never read as one.
        $this->assertNull($connectRequest->eld_connection_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Webhooks
    // ─────────────────────────────────────────────────────────────────────

    public function test_it_refuses_a_webhook_without_a_valid_signature(): void
    {
        Bus::fake();

        $this->postJson('/api/v1/webhooks/terminal', [
            'type' => 'sync.completed',
            'detail' => ['connection' => ['id' => 'conn_01TESTCONNECTION']],
        ], [
            'svix-id' => 'msg_1',
            'svix-timestamp' => (string) time(),
            'svix-signature' => 'v1,not-the-right-signature',
        ])->assertStatus(401);

        Bus::assertNothingDispatched();
    }

    public function test_a_signed_sync_completed_webhook_queues_a_sync(): void
    {
        Bus::fake();

        $connection = EldConnection::create([
            'uuid' => Str::uuid(),
            'terminal_connection_id' => 'conn_01TESTCONNECTION',
            'connection_token' => 'con_tkn_secret',
            'status' => EldConnection::STATUS_CONNECTED,
        ]);

        $payload = [
            'id' => 'evt_1',
            'type' => 'sync.completed',
            'detail' => [
                'connection' => ['id' => 'conn_01TESTCONNECTION'],
                'sync' => ['status' => 'completed', 'progress' => 100],
            ],
        ];

        $this->postJson('/api/v1/webhooks/terminal', $payload, $this->svixHeaders($payload))
            ->assertOk();

        $this->assertSame('completed', $connection->fresh()->sync_status);

        Bus::assertDispatched(SyncEldConnection::class);
    }

    public function test_a_disconnect_webhook_marks_the_connection_without_dropping_the_fleet(): void
    {
        Bus::fake();

        $connection = EldConnection::create([
            'uuid' => Str::uuid(),
            'terminal_connection_id' => 'conn_01TESTCONNECTION',
            'connection_token' => 'con_tkn_secret',
            'status' => EldConnection::STATUS_CONNECTED,
        ]);

        $connection->vehicles()->create([
            'terminal_id' => 'vcl_1',
            'vin' => '1HGCM82633A004352',
        ]);

        $payload = [
            'id' => 'evt_2',
            'type' => 'connection.disconnected',
            'detail' => ['connection' => ['id' => 'conn_01TESTCONNECTION']],
        ];

        $this->postJson('/api/v1/webhooks/terminal', $payload, $this->svixHeaders($payload))
            ->assertOk();

        $connection->refresh();

        $this->assertSame(EldConnection::STATUS_DISCONNECTED, $connection->status);
        $this->assertNotNull($connection->disconnected_at);

        // What the broker assessed the carrier on stays readable.
        $this->assertSame(1, $connection->vehicles()->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Syncing
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_sync_lands_the_fleet_in_our_own_tables(): void
    {
        $connection = EldConnection::create([
            'uuid' => Str::uuid(),
            'terminal_connection_id' => 'conn_01TESTCONNECTION',
            'connection_token' => 'con_tkn_secret',
            'status' => EldConnection::STATUS_CONNECTED,
        ]);

        Http::fake([
            '*/connections/current' => Http::response([
                'id' => 'conn_01TESTCONNECTION',
                'status' => 'connected',
                'provider' => ['code' => 'geotab', 'name' => 'Geotab'],
            ]),
            '*/vehicles/locations*' => Http::response([
                'results' => [[
                    'id' => 'loc_1',
                    'vehicleId' => 'vcl_1',
                    'latitude' => 36.1627,
                    'longitude' => -86.7816,
                    'speed' => 62.5,
                    'timestamp' => '2026-08-30T12:00:00.000Z',
                ]],
            ]),
            '*/vehicles*' => Http::response([
                'results' => [[
                    'id' => 'vcl_1',
                    'sourceId' => '9001',
                    'vin' => '1HGCM82633A004352',
                    'name' => 'Big Red',
                    'make' => 'Peterbilt',
                    'model' => 'Model 579',
                    'year' => 2016,
                    'licensePlate' => ['state' => 'TN', 'number' => 'ABC-1234'],
                ]],
            ]),
            '*/drivers*' => Http::response([
                'results' => [[
                    'id' => 'drv_1',
                    'name' => 'Dana Whitfield',
                    'email' => 'dana@acmetrucking.test',
                    'licenseNumber' => 'TN-99887',
                    'licenseState' => 'TN',
                ]],
            ]),
            '*/hos/logs*' => Http::response([
                'results' => [[
                    'id' => 'hos_1',
                    'driverId' => 'drv_1',
                    'vehicleId' => 'vcl_1',
                    'dutyStatus' => 'driving',
                    'startedAt' => '2026-08-30T08:00:00.000Z',
                    'endedAt' => '2026-08-30T12:00:00.000Z',
                    'location' => ['latitude' => 36.1627, 'longitude' => -86.7816],
                ]],
            ]),
        ]);

        $counts = app(EldSyncService::class)->sync($connection);

        $this->assertSame(
            ['vehicles' => 1, 'drivers' => 1, 'hos_logs' => 1, 'locations' => 1],
            $counts
        );

        $connection->refresh();

        $this->assertSame('completed', $connection->sync_status);
        $this->assertNotNull($connection->last_synced_at);
        $this->assertSame('Geotab', $connection->provider_name);

        $vehicle = $connection->vehicles()->firstOrFail();
        $this->assertSame('1HGCM82633A004352', $vehicle->vin);
        $this->assertSame('TN', $vehicle->license_plate_state);

        // The whole object is kept, not just the columns we named.
        $this->assertSame('Model 579', $vehicle->payload['model']);

        // A single `name` is split, because some providers send only that.
        $driver = $connection->drivers()->firstOrFail();
        $this->assertSame('Dana', $driver->first_name);
        $this->assertSame('Whitfield', $driver->last_name);

        // `dutyStatus` and `status` both appear in Terminal's own docs.
        $this->assertSame('driving', $connection->hosLogs()->firstOrFail()->status);

        $this->assertSame(62.5, $connection->vehicleLocations()->firstOrFail()->speed);
    }

    public function test_a_second_sync_updates_rather_than_duplicates(): void
    {
        $connection = EldConnection::create([
            'uuid' => Str::uuid(),
            'terminal_connection_id' => 'conn_01TESTCONNECTION',
            'connection_token' => 'con_tkn_secret',
            'status' => EldConnection::STATUS_CONNECTED,
        ]);

        Http::fake([
            '*/connections/current' => Http::response(['id' => 'conn_01TESTCONNECTION', 'status' => 'connected']),
            '*/vehicles/locations*' => Http::response(['results' => []]),
            '*/vehicles*' => Http::response(['results' => [
                ['id' => 'vcl_1', 'name' => 'Big Red'],
            ]]),
            '*/drivers*' => Http::response(['results' => []]),
            '*/hos/logs*' => Http::response(['results' => []]),
        ]);

        $sync = app(EldSyncService::class);

        $sync->sync($connection);
        $sync->sync($connection->fresh());

        $this->assertSame(1, $connection->vehicles()->count());
    }

    public function test_a_failed_sync_is_recorded_on_the_connection(): void
    {
        $connection = EldConnection::create([
            'uuid' => Str::uuid(),
            'terminal_connection_id' => 'conn_01TESTCONNECTION',
            'connection_token' => 'con_tkn_secret',
            'status' => EldConnection::STATUS_CONNECTED,
        ]);

        Http::fake([
            '*/connections/current' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        try {
            app(EldSyncService::class)->sync($connection);
            $this->fail('Expected the sync to surface the failure.');
        } catch (\Throwable) {
            // expected
        }

        $connection->refresh();

        $this->assertSame('failed', $connection->sync_status);
        $this->assertNotNull($connection->last_sync_error);
    }

    /**
     * Sign a payload the way Svix does, so the controller's verification is
     * exercised rather than bypassed.
     */
    private function svixHeaders(array $payload): array
    {
        $id = 'msg_'.Str::random(10);
        $timestamp = (string) time();
        $body = json_encode($payload);

        $key = base64_decode(substr(self::WEBHOOK_SECRET, 6), true);

        $signature = base64_encode(
            hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true)
        );

        return [
            'svix-id' => $id,
            'svix-timestamp' => $timestamp,
            'svix-signature' => 'v1,'.$signature,
        ];
    }
}
