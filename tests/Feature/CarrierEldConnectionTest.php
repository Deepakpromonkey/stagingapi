<?php

namespace Tests\Feature;

use App\Jobs\SyncEldConnection;
use App\Models\CarrierConnectRequest;
use App\Models\Company;
use App\Models\Eld\EldConnection;
use App\Models\Eld\EldConnectionGrant;
use App\Models\Eld\EldWebhookEvent;
use App\Services\Eld\EldConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Step 4 of carrier onboarding: linking an ELD through Terminal.
 *
 * The case worth protecting hardest is the shared connection. A carrier hauling
 * for several brokers links their provider once, and each broker's consent is
 * recorded separately — get that wrong and the same fleet is imported, and
 * billed for, once per broker relationship.
 */
class CarrierEldConnectionTest extends TestCase
{
    // Both traits define migrateFreshUsing(); ours is the one that keeps the
    // MySQL-only migrations out of the sqlite run.
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    /** Terminal's FullConnection, as returned by the public token exchange. */
    private const CONNECTION = [
        'id' => 'conn_01GV12VR4DJP70GD1ZBK0SDWFH',
        'provider' => ['code' => 'samsara', 'name' => 'Samsara'],
        'externalId' => 'dot:1234567',
        'sourceId' => '123456789',
        'token' => 'con_tkn_22vUhkC6tgre4kwaYfUkCDA1rzn6eyb4',
        'status' => 'connected',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.terminal.enabled' => true,
            'services.terminal.secret_key' => 'sk_sandbox_test',
            'services.terminal.publishable_key' => 'pk_sandbox_test',
            'services.terminal.base_url' => 'https://api.sandbox.withterminal.com/tsp/v1',
            'services.terminal.link_url' => 'https://link.sandbox.withterminal.com',
            'services.terminal.webhook_secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
        ]);

        Queue::fake();
    }

    private function company(string $name = 'Northwind Logistics', ?string $template = 'tpl_northwind'): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => $name,
            'status' => true,
            'eld_consent_template' => $template,
        ]);
    }

    private function connectRequest(Company $company): CarrierConnectRequest
    {
        return CarrierConnectRequest::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'carrier_dot_number' => '1234567',
            'carrier_row_id' => '99001',
            'carrier_legal_name' => "Frank's Trucking",
            'carrier_email' => 'frank@franks.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,

            // The invitation has a lifetime, and a request that was never sent
            // reads as expired.
            'sent_on' => now(),
            'mobile_verified_at' => now(),
        ]);
    }

    /** Walks a carrier through the Link flow and returns the stored connection. */
    private function link(CarrierConnectRequest $request): EldConnection
    {
        $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token])
            ->assertOk();

        $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $request->token,
            'public_token' => 'pub_tkn_'.Str::random(20),
            'state' => $request->refresh()->eld_link_state,
        ])->assertOk();

        return EldConnection::firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Opening the Link page
    // ─────────────────────────────────────────────────────────────────────────

    public function test_it_hands_the_carrier_a_link_url_carrying_the_state_it_stored(): void
    {
        $request = $this->connectRequest($this->company());

        $response = $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token])
            ->assertOk();

        $url = $response->json('data.url');
        $state = $request->refresh()->eld_link_state;

        $this->assertStringStartsWith('https://link.sandbox.withterminal.com/?', $url);
        $this->assertNotNull($state);
        $this->assertStringContainsString('state='.$state, $url);
        $this->assertStringContainsString('key=pk_sandbox_test', $url);
    }

    public function test_the_link_url_is_keyed_to_the_carrier_so_terminal_can_dedupe(): void
    {
        $request = $this->connectRequest($this->company());

        $url = $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token])
            ->json('data.url');

        // Terminal matches on the provider account plus this value. Anything
        // broker-specific here and the same fleet connects twice.
        $this->assertStringContainsString('external_id=dot%3A1234567', $url);
    }

    public function test_the_carrier_is_shown_the_consent_page_belonging_to_their_broker(): void
    {
        $request = $this->connectRequest($this->company('Northwind Logistics', 'tpl_northwind'));

        $url = $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token])
            ->json('data.url');

        $this->assertStringContainsString('template=tpl_northwind', $url);
        $this->assertStringContainsString('name=Northwind+Logistics', $url);
    }

    public function test_it_reports_unavailable_rather_than_a_broken_page_when_terminal_is_off(): void
    {
        config(['services.terminal.enabled' => false]);

        $request = $this->connectRequest($this->company());

        $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token])
            ->assertStatus(503);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Coming back
    // ─────────────────────────────────────────────────────────────────────────

    public function test_it_exchanges_the_public_token_and_stores_the_connection(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $request = $this->connectRequest($this->company());
        $connection = $this->link($request);

        $this->assertSame('conn_01GV12VR4DJP70GD1ZBK0SDWFH', $connection->terminal_connection_id);
        $this->assertSame('Samsara', $connection->provider);
        $this->assertNotNull($request->refresh()->eld_connected_at);

        Queue::assertPushed(SyncEldConnection::class);
    }

    public function test_the_connection_token_is_encrypted_at_rest_and_never_serialised(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $connection = $this->link($this->connectRequest($this->company()));

        $this->assertNotSame(
            self::CONNECTION['token'],
            DB::table('eld_connections')->value('connection_token')
        );

        $this->assertSame(self::CONNECTION['token'], $connection->connection_token);
        $this->assertArrayNotHasKey('connection_token', $connection->toArray());
    }

    public function test_a_return_carrying_the_wrong_state_is_refused(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $request = $this->connectRequest($this->company());

        $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token]);

        $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $request->token,
            'public_token' => 'pub_tkn_forged',
            'state' => 'not-the-state-we-issued',
        ])->assertStatus(422);

        $this->assertSame(0, EldConnection::count());
    }

    public function test_the_state_is_single_use_so_a_return_url_cannot_be_replayed(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $request = $this->connectRequest($this->company());

        $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token]);

        $state = $request->refresh()->eld_link_state;

        $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $request->token,
            'public_token' => 'pub_tkn_first',
            'state' => $state,
        ])->assertOk();

        $this->postJson('/api/v1/carrier-connect/eld/verify', [
            'token' => $request->token,
            'public_token' => 'pub_tkn_replayed',
            'state' => $state,
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // One carrier, several brokers
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_second_broker_reuses_the_connection_and_gets_its_own_grant(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $first = $this->connectRequest($this->company('Northwind Logistics'));
        $second = $this->connectRequest($this->company('Bravo Freight', 'tpl_bravo'));

        $this->link($first);
        $this->link($second);

        // One fleet, imported and metered once.
        $this->assertSame(1, EldConnection::count());

        // Two brokers, each with their own record of consent.
        $this->assertSame(2, EldConnectionGrant::count());
        $this->assertEqualsCanonicalizing(
            [$first->company_id, $second->company_id],
            EldConnectionGrant::pluck('company_id')->all()
        );
    }

    public function test_one_broker_leaving_does_not_blind_the_others(): void
    {
        Http::fake([
            '*/public-token/exchange' => Http::response(self::CONNECTION),
            '*/connections/current' => Http::response(['status' => 'archived']),
        ]);

        $first = $this->connectRequest($this->company('Northwind Logistics'));
        $second = $this->connectRequest($this->company('Bravo Freight', 'tpl_bravo'));

        $connection = $this->link($first);
        $this->link($second);

        app(EldConnectionService::class)->revokeGrant($connection->refresh(), $first->company_id);

        $this->assertSame(EldConnection::STATUS_CONNECTED, $connection->refresh()->status);

        // Withdrawal is recorded, not erased — both are things a broker may
        // later have to evidence.
        $this->assertSame(2, EldConnectionGrant::count());
        $this->assertNotNull(
            EldConnectionGrant::where('company_id', $first->company_id)->first()->revoked_at
        );
    }

    public function test_the_connection_is_archived_once_no_broker_is_left_watching(): void
    {
        Http::fake([
            '*/public-token/exchange' => Http::response(self::CONNECTION),
            '*/connections/current' => Http::response(['status' => 'archived']),
        ]);

        $request = $this->connectRequest($this->company());
        $connection = $this->link($request);

        app(EldConnectionService::class)->revokeGrant($connection->refresh(), $request->company_id);

        $connection->refresh();

        $this->assertSame(EldConnection::STATUS_ARCHIVED, $connection->status);
        $this->assertFalse($connection->isSyncable());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Skipping, and the step's own state
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_carrier_whose_provider_is_unsupported_can_skip_the_step(): void
    {
        $request = $this->connectRequest($this->company());

        $response = $this->postJson('/api/v1/carrier-connect/skip', [
            'token' => $request->token,
            'step' => 'eld',
        ])->assertOk();

        $this->assertNotNull($request->refresh()->eld_skipped_at);

        // Settled moves the wizard on; skipped keeps it honest with the broker.
        $this->assertTrue($response->json('data.eld_settled'));
        $this->assertTrue($response->json('data.eld_skipped'));
        $this->assertFalse($response->json('data.eld_connected'));
    }

    public function test_skipping_cannot_throw_away_a_connection_already_made(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $request = $this->connectRequest($this->company());
        $this->link($request);

        $this->postJson('/api/v1/carrier-connect/skip', [
            'token' => $request->token,
            'step' => 'eld',
        ])->assertOk();

        $this->assertNull($request->refresh()->eld_skipped_at);
    }

    public function test_a_dropped_connection_stops_reading_as_connected(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $request = $this->connectRequest($this->company());
        $connection = $this->link($request);

        app(EldConnectionService::class)->markDisconnected($connection);

        $response = $this->postJson('/api/v1/carrier-connect/skip', [
            'token' => $request->token,
            'step' => 'eld',
        ]);

        // Not connected any more, so the wizard can offer re-authentication —
        // but still settled, so the carrier is not sent back to square one.
        $this->assertFalse($response->json('data.eld_connected'));
        $this->assertTrue($response->json('data.eld_settled'));
        $this->assertSame('disconnected', $response->json('data.eld.status'));
    }

    public function test_a_carrier_with_a_broken_connection_is_sent_to_re_authenticate(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $request = $this->connectRequest($this->company());
        $connection = $this->link($request);

        app(EldConnectionService::class)->markDisconnected($connection);

        $url = $this->postJson('/api/v1/carrier-connect/eld/connect', ['token' => $request->token])
            ->json('data.url');

        // Re-auth repairs the connection that exists rather than starting a
        // fresh one, so the id is in the path.
        $this->assertStringContainsString('/connection/conn_01GV12VR4DJP70GD1ZBK0SDWFH', $url);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Webhooks
    // ─────────────────────────────────────────────────────────────────────────

    /** Signs a body the way Svix does, so the endpoint sees a genuine delivery. */
    private function deliver(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $id = 'msg_'.Str::random(20);
        $timestamp = (string) time();

        $key = base64_decode(substr((string) config('services.terminal.webhook_secret'), 6), true);
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));

        return $this->call(
            'POST',
            '/api/v1/eld/terminal/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_SVIX_ID' => $id,
                'HTTP_SVIX_TIMESTAMP' => $timestamp,
                'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
            ],
            $body
        );
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $this->postJson('/api/v1/eld/terminal/webhook', [
            'id' => 'evt_1',
            'type' => 'connection.disconnected',
        ])->assertStatus(401);
    }

    public function test_a_disconnect_event_marks_the_connection_down(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $connection = $this->link($this->connectRequest($this->company()));

        $this->deliver([
            'id' => 'evt_01GV12VR4DJP70GD1ZBK0SDWFH',
            'type' => 'connection.disconnected',
            'detail' => ['connection' => ['id' => $connection->terminal_connection_id]],
        ])->assertOk();

        $this->assertSame(EldConnection::STATUS_DISCONNECTED, $connection->refresh()->status);
    }

    public function test_a_retried_delivery_is_acknowledged_without_acting_twice(): void
    {
        Http::fake(['*/public-token/exchange' => Http::response(self::CONNECTION)]);

        $connection = $this->link($this->connectRequest($this->company()));

        Queue::fake();

        $event = [
            'id' => 'evt_duplicate',
            'type' => 'sync.completed',
            'detail' => ['connection' => ['id' => $connection->terminal_connection_id]],
        ];

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk()->assertJson(['status' => 'duplicate']);

        // Terminal bills for what gets read, so a retry must not sync again.
        Queue::assertPushed(SyncEldConnection::class, 1);
        $this->assertSame(1, EldWebhookEvent::where('event_id', 'evt_duplicate')->count());
    }
}
