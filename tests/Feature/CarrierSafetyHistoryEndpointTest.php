<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Carrier\CarrierSafetyHistory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The history itself reads the external carrier database, which the suite
 * does not have; it was checked against the old detail() output on real
 * carriers. This covers how the endpoint hands it out.
 */
class CarrierSafetyHistoryEndpointTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private string $body = '{"success":true,"data":{"inspections":[],"violation_details":[],"crash_details":[]}}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $body = $this->body;
        $this->app->instance(CarrierSafetyHistory::class, new class($body) extends CarrierSafetyHistory
        {
            public function __construct(private string $json) {}

            public function gzippedJson(string $dot): string
            {
                return gzencode($this->json);
            }
        });
    }

    private function broker(): User
    {
        $company = Company::create(['uuid' => Str::uuid(), 'company_name' => 'Northwind', 'status' => true]);

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

    public function test_a_browser_gets_the_cached_gzip_as_is(): void
    {
        Sanctum::actingAs($this->broker());

        $response = $this->get('/api/v1/carrier/264184/safety-history', ['Accept-Encoding' => 'gzip, deflate, br']);

        $response->assertOk()
            ->assertHeader('Content-Encoding', 'gzip')
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame($this->body, gzdecode($response->getContent()));
    }

    public function test_a_client_without_gzip_gets_plain_json(): void
    {
        Sanctum::actingAs($this->broker());

        $this->getJson('/api/v1/carrier/264184/safety-history', ['Accept-Encoding' => 'identity'])
            ->assertOk()
            ->assertHeaderMissing('Content-Encoding')
            ->assertJsonPath('data.inspections', []);
    }

    public function test_it_needs_a_signed_in_broker(): void
    {
        $this->getJson('/api/v1/carrier/264184/safety-history')->assertUnauthorized();
    }
}
