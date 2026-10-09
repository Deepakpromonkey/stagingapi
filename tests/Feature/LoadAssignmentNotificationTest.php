<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Shipment\LoadAssignmentNotifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Booking a load tells every driver on it: a push if they have the driver
 * app, an SMS with the store links if they don't - never both.
 *
 * Telnyx and OneSignal are faked, and any other outbound request fails the
 * test, so nothing here can message a real phone.
 */
class LoadAssignmentNotificationTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const TELNYX = 'https://api.telnyx.com/v2/messages';

    private const ONESIGNAL = 'https://api.onesignal.com/notifications*';

    private bool $pushDelivers = true;

    private bool $smsGatewayUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'subscriptions.require_subscription_for_loads' => false,
            'driver_app.notify_on_new_load' => true,
            'services.telnyx.key' => 'test-telnyx-key',
            'services.telnyx.from' => '+18668850345',
            'services.telnyx.override_to' => null,
            'services.onesignal.app_id' => 'test-onesignal-app',
            'services.onesignal.rest_api_key' => 'os_v2_app_test',
        ]);

        // The driver API's table - its migrations live in that project, not
        // this one. Only the columns the notifier reads.
        if (! Schema::hasTable('app_drivers')) {
            Schema::create('app_drivers', function (Blueprint $table) {
                $table->id();
                $table->string('phone')->nullable();
                $table->string('device_token')->nullable();
                $table->timestamps();
            });
        }

        Http::preventStrayRequests();

        Http::fake([
            self::TELNYX => fn () => $this->smsGatewayUp
                ? Http::response(['data' => ['to' => [['status' => 'queued']]]])
                : Http::response(['errors' => [['title' => 'Service unavailable']]], 503),
            self::ONESIGNAL => fn () => $this->pushDelivers
                ? Http::response(['id' => 'notif-1'])
                // What OneSignal answers for an id that no longer reaches a phone.
                : Http::response(['id' => '', 'errors' => ['invalid_player_ids' => ['sub-gone']]]),
        ]);
    }

    private function bookLoad(array $payload): string
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
            'email' => 'sam-'.Str::random(8).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => false,
        ]);

        $user->assignRole(Role::where('slug', 'agent')->firstOrFail());

        Sanctum::actingAs($user->fresh());

        return $this->postJson('/api/v1/shipments', ['tracking_method' => 'driver_phone'] + $payload)
            ->assertCreated()
            ->json('data.shipment_no');
    }

    private function appDriver(string $phone, ?string $token): void
    {
        DB::table('app_drivers')->insert([
            'phone' => $phone,
            'device_token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, array> Each SMS sent: ['to' => ..., 'text' => ...]. */
    private function texts(): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::TELNYX)
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    /** @return array<int, array> Each push sent. */
    private function pushes(): array
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://api.onesignal.com/'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_a_driver_without_the_app_gets_an_sms_with_both_store_links(): void
    {
        $load = $this->bookLoad(['driver_phone_1' => '(555) 010-2020', 'country_code_1' => '+1']);

        $texts = $this->texts();

        $this->assertCount(1, $texts);
        $this->assertSame('+15550102020', $texts[0]['to']);
        $this->assertStringContainsString("Northwind Logistics assigned you load {$load}", $texts[0]['text']);
        $this->assertStringContainsString('https://play.google.com/store/apps/details?id=com.DollarTraq', $texts[0]['text']);
        $this->assertStringContainsString('https://apps.apple.com/app/id6759337431', $texts[0]['text']);
        $this->assertSame([], $this->pushes());
    }

    public function test_the_sms_stays_in_the_plain_sms_alphabet(): void
    {
        $this->bookLoad(['driver_phone_1' => '5550102020', 'country_code_1' => '+1']);

        // Anything outside ASCII would switch the text to UCS-2 and double
        // what each one costs.
        $this->assertMatchesRegularExpression('/^[\x0A\x20-\x7E]+$/', $this->texts()[0]['text']);
    }

    public function test_a_driver_on_the_app_gets_a_push_and_no_sms(): void
    {
        $this->appDriver('+15550102020', 'sub-123');

        $load = $this->bookLoad(['driver_phone_1' => '555-010-2020', 'country_code_1' => '+1']);

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes);
        $this->assertSame(['sub-123'], $pushes[0]['include_subscription_ids']);
        $this->assertSame('test-onesignal-app', $pushes[0]['app_id']);
        $this->assertSame('New load assigned', $pushes[0]['headings']['en']);
        $this->assertStringContainsString($load, $pushes[0]['contents']['en']);
        $this->assertSame('load_assigned', $pushes[0]['data']['type']);
        $this->assertSame([], $this->texts());

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.onesignal.com/')
            && $request->header('Authorization') === ['Key os_v2_app_test']);
    }

    public function test_a_push_that_does_not_reach_the_phone_falls_back_to_sms(): void
    {
        $this->pushDelivers = false;
        $this->appDriver('+15550102020', 'sub-gone');

        $this->bookLoad(['driver_phone_1' => '5550102020', 'country_code_1' => '+1']);

        $this->assertCount(1, $this->pushes());
        $this->assertCount(1, $this->texts(), 'the driver must still hear about the load');
    }

    public function test_an_app_driver_without_push_enabled_gets_the_sms(): void
    {
        $this->appDriver('+15550102020', null);

        $this->bookLoad(['driver_phone_1' => '5550102020', 'country_code_1' => '+1']);

        $this->assertSame([], $this->pushes());
        $this->assertCount(1, $this->texts());
    }

    public function test_each_phone_uses_its_own_country_code_and_is_messaged_once(): void
    {
        $this->bookLoad([
            'driver_phone_1' => '98765 43210',
            'country_code_1' => '+91',
            'driver_phone_2' => '5550102020',
            'country_code' => '+1',
            'driver_phone_3' => '+1 555 010 2020', // the same driver as phone 2
            'team_load' => true,
        ]);

        $this->assertEqualsCanonicalizing(
            ['+919876543210', '+15550102020'],
            array_column($this->texts(), 'to'),
        );
    }

    public function test_a_load_without_a_driver_phone_sends_nothing(): void
    {
        $this->bookLoad([]);

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_switched_off(): void
    {
        config(['driver_app.notify_on_new_load' => false]);

        $this->bookLoad(['driver_phone_1' => '5550102020', 'country_code_1' => '+1']);

        Http::assertNothingSent();
    }

    public function test_a_failing_sms_gateway_does_not_fail_the_booking(): void
    {
        $this->smsGatewayUp = false;

        Log::spy();

        // bookLoad() asserts the 201 itself.
        $this->bookLoad(['driver_phone_1' => '5550102020', 'country_code_1' => '+1']);

        $this->assertCount(1, $this->texts());

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => $message === '[LoadAssignment] SMS FAILED')
            ->once();
    }

    public function test_phone_numbers_are_made_international(): void
    {
        $cases = [
            ['+1 (555) 010-2020', null, '+15550102020'],
            ['5550102020', '+1', '+15550102020'],
            ['15550102020', '+1', '+15550102020'],
            ['9876543210', '+91', '+919876543210'],
            ['09876543210', '+91', '+919876543210'],
            ['919876543210', '+91', '+919876543210'],
            ['0091 98765 43210', null, '+919876543210'],
            ['', '+1', null],
            [null, '+1', null],
        ];

        foreach ($cases as [$phone, $dial, $expected]) {
            $this->assertSame($expected, LoadAssignmentNotifier::international($phone, $dial), "{$phone} / {$dial}");
        }
    }
}
