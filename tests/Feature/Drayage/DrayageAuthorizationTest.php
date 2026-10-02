<?php

namespace Tests\Feature\Drayage;

use App\Http\Middleware\EnsureBrokerUser;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

/**
 * Who may call what. Reads follow the carrier directory permission, export
 * has its own, and imports/rollback are staff-only - held by no seat at all,
 * Owner/Admin included. Fleetra's service token reads and does nothing else.
 */
class DrayageAuthorizationTest extends DrayageTestCase
{
    private const READS = [
        '/api/v1/drayage/carriers',
        '/api/v1/drayage/carriers/lm-9224',
        '/api/v1/drayage/carriers/lookup?usdot=3408478',
        '/api/v1/drayage/facets',
        '/api/v1/drayage/fields',
        '/api/v1/drayage/stats',
    ];

    private array $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->import = $this->import();
    }

    private function adminCalls(): array
    {
        $dataset = $this->import['dataset_id'];

        return [
            ['get', '/api/v1/drayage/imports'],
            ['get', '/api/v1/drayage/imports/'.$this->import['import_id']],
            ['get', '/api/v1/drayage/datasets'],
            ['post', "/api/v1/drayage/datasets/{$dataset}/activate"],
            ['post', '/api/v1/drayage/imports'],
            ['delete', '/api/v1/drayage/datasets/ds-20200101T000000000Z-abcdef'],
        ];
    }

    public function test_guests_get_401_everywhere(): void
    {
        foreach (self::READS as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        $this->getJson('/api/v1/drayage/export')->assertUnauthorized();

        foreach ($this->adminCalls() as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
    }

    public function test_every_broker_role_can_read(): void
    {
        foreach (['viewer', 'agent', 'senior_agent', 'compliance_manager', 'owner_admin'] as $role) {
            Sanctum::actingAs($this->brokerUser($role));

            foreach (self::READS as $url) {
                $this->getJson($url)->assertOk();
            }
        }
    }

    public function test_only_compliance_managers_and_owners_can_export(): void
    {
        foreach (['viewer' => 403, 'agent' => 403, 'senior_agent' => 403, 'compliance_manager' => 200, 'owner_admin' => 200] as $role => $status) {
            Sanctum::actingAs($this->brokerUser($role));

            $this->get('/api/v1/drayage/export', ['Accept' => 'application/json'])->assertStatus($status);
        }
    }

    public function test_no_broker_seat_can_administer_the_directory(): void
    {
        foreach (['viewer', 'agent', 'senior_agent', 'compliance_manager', 'owner_admin'] as $role) {
            Sanctum::actingAs($this->brokerUser($role));

            foreach ($this->adminCalls() as [$method, $url]) {
                $this->json($method, $url)->assertForbidden();
            }
        }
    }

    public function test_staff_with_the_grant_can_administer(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/v1/drayage/imports')->assertOk();
        $this->getJson('/api/v1/drayage/imports/'.$this->import['import_id'])->assertOk();
        $this->getJson('/api/v1/drayage/datasets')->assertOk();
        $this->postJson("/api/v1/drayage/datasets/{$this->import['dataset_id']}/activate")->assertOk();
        $this->postJson('/api/v1/drayage/imports')->assertStatus(422);
        $this->deleteJson('/api/v1/drayage/datasets/ds-20200101T000000000Z-abcdef')->assertNotFound();
    }

    public function test_the_grant_command_gives_and_takes_the_permissions(): void
    {
        $user = $this->brokerUser('viewer');

        Artisan::call('drayage:grant', ['email' => $user->email]);
        $this->assertTrue($user->fresh()->hasPermissionTo('manage-drayage-directory'));

        Artisan::call('drayage:grant', ['email' => $user->email, '--revoke' => true]);
        $this->assertFalse($user->fresh()->hasPermissionTo('manage-drayage-directory'));
    }

    public function test_a_service_token_reads_the_directory_and_nothing_else(): void
    {
        Artisan::call('drayage:service-token', ['--name' => 'fleetra']);
        preg_match('/^(\d+\|\S+)$/m', Artisan::output(), $m);
        $token = $m[1] ?? $this->fail('No token printed.');

        $service = User::where('email', 'fleetra-drayage@service.dollartraq.invalid')->firstOrFail();

        $this->assertNull($service->company_id);
        $this->assertSame(['read-drayage-directory'], $service->getAllPermissions()->pluck('name')->all());
        $this->assertSame([EnsureBrokerUser::DRAYAGE_READ], $service->tokens()->first()->abilities);

        $this->withHeader('Authorization', 'Bearer '.$token);

        foreach (self::READS as $url) {
            $this->getJson($url)->assertOk();
        }

        $this->get('/api/v1/drayage/export', ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson('/api/v1/drayage/imports')->assertForbidden();

        // Broker routes outside the directory - including ones with no
        // permission gate of their own - refuse the scoped token.
        $this->getJson('/api/v1/me')->assertForbidden()
            ->assertJsonPath('message', 'This token can only read the drayage directory.');
        $this->getJson('/api/v1/shortlist')->assertForbidden();
        $this->getJson('/api/v1/carrier/search?q=x')->assertForbidden();

        Artisan::call('drayage:service-token', ['--name' => 'fleetra', '--revoke' => true]);
        $this->assertSame(0, $service->tokens()->count());
    }

    public function test_a_normal_sign_in_token_is_not_affected_by_the_scope_check(): void
    {
        $agent = $this->brokerUser('agent');
        Sanctum::actingAs($agent, ['*']);

        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/drayage/carriers')->assertOk();
    }

    public function test_carrier_portal_tokens_are_refused(): void
    {
        $carrierUser = new \App\Models\CarrierUser;
        $carrierUser->id = 1;
        Sanctum::actingAs($carrierUser, [\App\Models\CarrierUser::TOKEN_ABILITY]);

        $this->getJson('/api/v1/drayage/carriers')->assertForbidden();
    }
}
