<?php

namespace Tests\Feature\Drayage;

use App\Jobs\ScoreCarrierSearchPage;
use App\Models\CarrierConnectRequest;
use App\Services\Drayage\DrayageEnrichmentService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * include=fmcsa / trust_score / onboarding, and the in_dollartraq flag.
 *
 * The carrier database is never touched from a test (it is production's).
 * Existence and scores are seeded through the same cache keys the real code
 * reads first - carrier:rowid:{dot} and carrier_dt_score:{dot} - and the
 * FMCSA block, which has no cache in front of it, is stubbed at the
 * service.
 */
class DrayageEnrichmentTest extends DrayageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->import();
    }

    public function test_trust_score_comes_from_the_shared_cache(): void
    {
        Bus::fake([ScoreCarrierSearchPage::class]);
        Cache::put('carrier_dt_score:3408478', 91, 60);
        Sanctum::actingAs($this->brokerUser('agent'));

        $this->getJson('/api/v1/drayage/carriers/lm-9224?include=trust_score')->assertOk()
            ->assertJsonPath('data.trust_score', ['score' => 91, 'grade' => 'A', 'status' => 'ready']);

        Bus::assertNotDispatched(ScoreCarrierSearchPage::class);
    }

    public function test_an_unscored_carrier_is_queued_for_the_same_background_scoring(): void
    {
        Bus::fake([ScoreCarrierSearchPage::class]);
        Sanctum::actingAs($this->brokerUser('agent'));

        $this->getJson('/api/v1/drayage/carriers/lm-84667?include=trust_score')->assertOk()
            ->assertJsonPath('data.trust_score.status', 'pending')
            ->assertJsonPath('data.trust_score.score', null);

        Bus::assertDispatched(ScoreCarrierSearchPage::class, fn ($job) => $job->dots === ['540864']);
    }

    public function test_list_trust_scores_are_batched_for_the_page_only(): void
    {
        Bus::fake([ScoreCarrierSearchPage::class]);
        Cache::put('carrier_dt_score:3408478', 77, 60);
        Sanctum::actingAs($this->brokerUser('agent'));

        $carriers = $this->getJson('/api/v1/drayage/carriers?include=trust_score&sort=company_name&per_page=3&fields=company_name')
            ->assertOk()->json('data.carriers');

        $this->assertSame(['1 Alfa Transportation, LLC', 'Genesis Intermodal LLC', 'InCompass Logistics LLC'], array_column($carriers, 'company_name'));
        $this->assertNull($carriers[0]['trust_score'], 'a listing has no USDOT to score');
        $this->assertSame(77, $carriers[1]['trust_score']['score']);
        $this->assertSame('pending', $carriers[2]['trust_score']['status']);

        // One job for the page's unscored carriers, not one per carrier and
        // not for carriers off the page.
        Bus::assertDispatchedTimes(ScoreCarrierSearchPage::class, 1);
        Bus::assertDispatched(ScoreCarrierSearchPage::class, fn ($job) => $job->dots === ['2631140']);
    }

    public function test_onboarding_and_the_next_action(): void
    {
        $company = $this->company();
        $agent = $this->brokerUser('agent', $company);
        Sanctum::actingAs($agent);

        // Genesis is in the census and this broker has invited it; Medlog is
        // in the census and has not been invited.
        Cache::put('carrier:rowid:3408478', 3408478, 60);
        Cache::put('carrier:rowid:540864', 540864, 60);

        CarrierConnectRequest::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'carrier_row_id' => '3408478',
            'carrier_dot_number' => '3408478',
            'carrier_legal_name' => 'Genesis Intermodal LLC',
            'carrier_email' => 'carrier@example.test',
            'token' => Str::random(40),
            'status' => CarrierConnectRequest::STATUS_NEW,
            'sent_on' => now(),
        ]);

        $genesis = $this->getJson('/api/v1/drayage/carriers/lm-9224?include=onboarding')->assertOk()->json('data');
        $this->assertTrue($genesis['in_dollartraq']);
        $this->assertSame('new', $genesis['onboarding']['status']);
        $this->assertSame('view_onboarding', $genesis['actions']['next_action']);

        $medlog = $this->getJson('/api/v1/drayage/carriers/lm-84667?include=onboarding')->assertOk()->json('data');
        $this->assertTrue($medlog['in_dollartraq']);
        $this->assertNull($medlog['onboarding']);
        $this->assertSame('invite', $medlog['actions']['next_action']);
        $this->assertSame(['row_id' => '540864', 'email_option' => 'fmcsa'], $medlog['actions']['invite']['body']);
        $this->assertSame('/api/v1/carrier-connect', $medlog['actions']['invite']['endpoint']);

        // Another broker's invitation is not this broker's onboarding.
        Sanctum::actingAs($this->brokerUser('agent'));
        $this->getJson('/api/v1/drayage/carriers/lm-9224?include=onboarding')->assertOk()
            ->assertJsonPath('data.onboarding', null)
            ->assertJsonPath('data.actions.next_action', 'invite');
    }

    public function test_a_carrier_without_a_usdot_cannot_be_invited(): void
    {
        Sanctum::actingAs($this->brokerUser('agent'));

        $listing = collect($this->getJson('/api/v1/drayage/carriers?record_type=listing&include=onboarding')->assertOk()->json('data.carriers'))->first();

        $this->assertNull($listing['in_dollartraq']);
        $this->assertSame('unavailable', $listing['actions']['next_action']);
        $this->assertStringContainsString('No USDOT', $listing['actions']['reason']);
    }

    public function test_fmcsa_is_attached_on_request(): void
    {
        Cache::put('carrier:rowid:3408478', 3408478, 60);

        $this->partialMock(DrayageEnrichmentService::class, function ($mock) {
            $mock->shouldReceive('fmcsa')->once()->with('3408478')->andReturn([
                'dot_number' => '3408478',
                'operating_status' => 'active',
                'authority' => ['common' => true, 'contract' => false, 'broker' => false, 'any_active' => true],
                'out_of_service' => ['active' => false, 'orders' => []],
            ]);
        });

        Sanctum::actingAs($this->brokerUser('viewer'));

        $this->getJson('/api/v1/drayage/carriers/lm-9224?include=fmcsa')->assertOk()
            ->assertJsonPath('data.fmcsa.operating_status', 'active')
            ->assertJsonPath('data.fmcsa.authority.any_active', true)
            ->assertJsonPath('data.in_dollartraq', true);
    }

    public function test_without_include_nothing_is_looked_up(): void
    {
        $this->partialMock(DrayageEnrichmentService::class, function ($mock) {
            $mock->shouldNotReceive('fmcsa', 'inDollarTraq', 'trustScores', 'onboarding');
        });

        Sanctum::actingAs($this->brokerUser('viewer'));

        $data = $this->getJson('/api/v1/drayage/carriers/lm-9224')->assertOk()->json('data');
        $this->assertArrayNotHasKey('in_dollartraq', $data);

        $this->getJson('/api/v1/drayage/carriers')->assertOk();
    }
}
