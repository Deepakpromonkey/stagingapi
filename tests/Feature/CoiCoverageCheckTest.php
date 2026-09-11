<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CoiInsuranceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The two questions asked at tender time.
 *
 * Is the truck on the policy (02, 08), and is the load's commodity covered for
 * what it is worth (18). Both are about one load; neither changes what the
 * carrier's profile says.
 */
class CoiCoverageCheckTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private User $user;

    private function seedCoverage(array $coverage): void
    {
        $company = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);

        $this->user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam-'.Str::random(6).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => true,
        ]);

        CoiInsuranceRequest::create([
            'company_id' => $company->id,
            'user_id' => $this->user->id,
            'dot_number' => 3577340,
            'carrier_name' => 'Frostline Refrigerated LLC',
            'recipient_email' => 'certs@agency.example',
            'recipient_source' => 'ocr',
            'status' => CoiInsuranceRequest::STATUS_SUCCESS,
            'subject' => 'Insurance details of the carrier Frostline 3577340',
            'sent_at' => now()->subDays(3),
            'resolved_at' => now()->subDay(),
            'insurance_expiry_date' => '2027-05-30',
            'coverage' => $coverage,
        ]);

        Sanctum::actingAs($this->user);
    }

    private function check(array $payload): array
    {
        return $this->postJson('/api/v1/carriers/3577340/coverage-check', $payload)
            ->assertOk()
            ->json('data');
    }

    public function test_a_seafood_load_over_its_sub_limit_is_held(): void
    {
        // Sequence 18: $250,000 cargo, but seafood is capped at $100,000.
        $this->seedCoverage([
            'coverages' => [['type' => 'cargo', 'limit' => 250000]],
            'sub_limits' => [['commodity' => 'seafood and shellfish', 'limit' => 100000]],
            'scheduled_vins' => [],
        ]);

        $data = $this->check(['commodity' => 'fresh seafood', 'value' => 148000]);

        $this->assertTrue($data['hold']);
        $this->assertSame('under_insured', $data['checks']['commodity']['verdict']);
        $this->assertEquals(100000, $data['checks']['commodity']['limit_applied']);
    }

    public function test_the_same_carrier_still_takes_a_load_under_the_general_limit(): void
    {
        // The point of keeping this off the profile: only that load was bad.
        $this->seedCoverage([
            'coverages' => [['type' => 'cargo', 'limit' => 250000]],
            'sub_limits' => [['commodity' => 'seafood and shellfish', 'limit' => 100000]],
            'scheduled_vins' => [],
        ]);

        $data = $this->check(['commodity' => 'palletised dry goods', 'value' => 148000]);

        $this->assertFalse($data['hold']);
        $this->assertSame('covered', $data['checks']['commodity']['verdict']);
        $this->assertEquals(250000, $data['checks']['commodity']['limit_applied']);
    }

    public function test_an_unscheduled_unit_is_held(): void
    {
        // Sequence 08: the tractor is not on the scheduled-auto policy.
        $this->seedCoverage([
            'coverages' => [],
            'sub_limits' => [],
            'scheduled_vins' => [
                ['vin' => '1FUJGLDR9CLBP8834', 'description' => '2012 Freightliner Cascadia'],
            ],
        ]);

        $data = $this->check(['vin' => '1XKYDP9X7GJ483011']);

        $this->assertTrue($data['hold']);
        $this->assertSame('not_scheduled', $data['checks']['unit']['verdict']);
    }

    public function test_a_scheduled_unit_passes_however_the_vin_was_typed(): void
    {
        $this->seedCoverage([
            'coverages' => [],
            'sub_limits' => [],
            'scheduled_vins' => [
                ['vin' => '1FUJGLDR9CLBP8834', 'description' => '2012 Freightliner Cascadia'],
            ],
        ]);

        // Quoted back with spacing and lowercase, as a dispatcher would.
        $data = $this->check(['vin' => '1fujgldr9-clbp 8834']);

        $this->assertFalse($data['hold']);
        $this->assertSame('scheduled', $data['checks']['unit']['verdict']);
    }

    public function test_a_policy_with_no_schedule_does_not_hold_every_load(): void
    {
        // Any Auto: every unit is covered and there is no list to be on.
        $this->seedCoverage([
            'coverages' => [['type' => 'auto liability', 'limit' => 1000000]],
            'sub_limits' => [],
            'scheduled_vins' => [],
        ]);

        $data = $this->check(['vin' => '1XKYDP9X7GJ483011']);

        $this->assertFalse($data['hold']);
        $this->assertSame('no_schedule', $data['checks']['unit']['verdict']);
    }

    public function test_it_refuses_a_check_that_asks_nothing(): void
    {
        $this->seedCoverage(['coverages' => [], 'sub_limits' => [], 'scheduled_vins' => []]);

        $this->postJson('/api/v1/carriers/3577340/coverage-check', [])
            ->assertStatus(422);
    }
}
