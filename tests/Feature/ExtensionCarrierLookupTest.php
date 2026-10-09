<?php

namespace Tests\Feature;

use App\Jobs\ScoreCarrierSearchPage;
use App\Models\CarrierBlocked;
use App\Models\CarrierShortlist;
use App\Models\Company;
use App\Models\User;
use App\Services\DtScore\DtScore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The Chrome extension's hover card.
 *
 * Every test here seeds the carrier facts into the cache the endpoint reads
 * first (ext:card:v2:{dot}), so none of them reach the external carrier
 * database - what's under test is the endpoint around those facts: auth,
 * permission, the score, the background scoring hand-off and the company's
 * own shortlist / blocklist flags.
 */
class ExtensionCarrierLookupTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const DOT = 3577340;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Added by a MySQL-only migration PortableMigrations skips on sqlite,
        // and the endpoint scopes the shortlist flag by it.
        if (! Schema::hasColumn('carrier_shortlists', 'company_id')) {
            Schema::table('carrier_shortlists', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable();
            });
        }

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

        Permission::create(['name' => 'view-carrier-directory', 'guard_name' => 'web']);
    }

    private function seedCard(): void
    {
        Cache::put('ext:card:v2:'.self::DOT, [
            'carrier_id' => self::DOT,
            'dot_number' => (string) self::DOT,
            'legal_name' => 'Frostline Refrigerated LLC',
            'dba_name' => null,
            'mc_number' => 'MC123456',
            'city' => 'Dallas',
            'state' => 'TX',
        ], now()->addMinutes(30));
    }

    private function actAsPermitted(): void
    {
        $this->user->givePermissionTo('view-carrier-directory');

        Sanctum::actingAs($this->user);
    }

    public function test_it_requires_a_logged_in_broker(): void
    {
        $this->getJson('/api/v1/extension/carrier/'.self::DOT)->assertUnauthorized();
    }

    public function test_it_requires_the_carrier_directory_permission(): void
    {
        $this->seedCard();

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/extension/carrier/'.self::DOT)->assertForbidden();
    }

    public function test_only_a_numeric_dot_reaches_the_lookup(): void
    {
        $this->actAsPermitted();

        $this->getJson('/api/v1/extension/carrier/abc123')->assertNotFound();
        $this->getJson('/api/v1/extension/carrier/1234567890')->assertNotFound();
    }

    public function test_a_cached_score_comes_back_with_the_companys_own_flags(): void
    {
        $this->seedCard();
        DtScore::store((string) self::DOT, 81);

        CarrierShortlist::create([
            'company_id' => $this->user->company_id,
            'user_id' => $this->user->id,
            'carrier_id' => self::DOT,
        ]);

        Bus::fake();
        $this->actAsPermitted();

        $this->getJson('/api/v1/extension/carrier/'.self::DOT)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.legal_name', 'Frostline Refrigerated LLC')
            ->assertJsonPath('data.dt_score', 81)
            ->assertJsonPath('data.dt_score_pending', false)
            ->assertJsonPath('data.shortlisted', true)
            ->assertJsonPath('data.blocked', false)
            ->assertJsonPath('data.profile_url', rtrim(config('app.frontend_url'), '/').'/carriers/'.self::DOT);

        Bus::assertNotDispatchedAfterResponse(ScoreCarrierSearchPage::class);
    }

    public function test_a_blocked_carrier_is_flagged_for_its_own_company_only(): void
    {
        $this->seedCard();

        $otherCompany = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Someone Else Freight',
            'status' => true,
        ]);

        CarrierBlocked::create([
            'company_id' => $otherCompany->id,
            'user_id' => $this->user->id,
            'carrier_id' => self::DOT,
        ]);

        Bus::fake();
        $this->actAsPermitted();

        $this->getJson('/api/v1/extension/carrier/'.self::DOT)
            ->assertOk()
            ->assertJsonPath('data.blocked', false);

        CarrierBlocked::create([
            'company_id' => $this->user->company_id,
            'user_id' => $this->user->id,
            'carrier_id' => self::DOT,
        ]);

        $this->getJson('/api/v1/extension/carrier/'.self::DOT)
            ->assertOk()
            ->assertJsonPath('data.blocked', true);
    }

    public function test_a_missing_score_is_scored_in_the_background_once(): void
    {
        $this->seedCard();

        Bus::fake();
        $this->actAsPermitted();

        // Hovering the same DOT twice in a row runs one scoring pass, not two.
        foreach (range(1, 2) as $hover) {
            $this->getJson('/api/v1/extension/carrier/'.self::DOT)
                ->assertOk()
                ->assertJsonPath('data.dt_score', null)
                ->assertJsonPath('data.dt_score_pending', true);
        }

        Bus::assertDispatchedAfterResponseTimes(ScoreCarrierSearchPage::class, 1);
        Bus::assertDispatchedAfterResponse(
            ScoreCarrierSearchPage::class,
            fn (ScoreCarrierSearchPage $job) => $job->dots === [(string) self::DOT],
        );
    }

    public function test_an_unknown_dot_is_a_clean_404(): void
    {
        // What quickCard() remembers for a DOT with no carrier.
        Cache::put('ext:card:v2:'.self::DOT, false, now()->addMinutes(10));

        Bus::fake();
        $this->actAsPermitted();

        $this->getJson('/api/v1/extension/carrier/'.self::DOT)
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No carrier found with DOT '.self::DOT.'.',
            ]);

        Bus::assertNothingDispatched();
    }
}
