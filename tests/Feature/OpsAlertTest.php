<?php

namespace Tests\Feature;

use App\Mail\OpsAlertMail;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Operational alerts: what is sent, to whom, and how often. See config/ops.php.
 */
class OpsAlertTest extends TestCase
{
    private const WEBHOOK = 'https://teams.test/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ops.role' => 'production',
            'ops.server_label' => 'test-box',
            'ops.email.to' => ['deepak@promonkey.tech'],
            'ops.email.cc' => ['gaurav@promonkey.tech'],
            'services.teams.webhook_url' => self::WEBHOOK,
        ]);

        Cache::store('file')->flush();
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('', 202)]);

        Route::get('/api/__ops/boom', fn () => throw new \RuntimeException('database exploded'));
        Route::get('/api/__ops/validation', fn () => request()->validate(['x' => 'required']));
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();

        parent::tearDown();
    }

    private function teamsPosts(): int
    {
        return count(Http::recorded(fn (HttpRequest $request) => $request->url() === self::WEBHOOK));
    }

    public function test_a_server_error_goes_to_teams_and_to_both_mailboxes(): void
    {
        $this->getJson('/api/__ops/boom')->assertStatus(500);

        $this->assertSame(1, $this->teamsPosts());

        Mail::assertSent(OpsAlertMail::class, function (OpsAlertMail $mail) {
            return $mail->hasTo('deepak@promonkey.tech')
                && $mail->hasCc('gaurav@promonkey.tech')
                && str_contains($mail->headline, 'RuntimeException')
                && $mail->facts['Message'] === 'database exploded'
                && $mail->facts['Request'] === 'GET /api/__ops/boom'
                && $mail->facts['Server'] === 'test-box';
        });
    }

    public function test_the_same_error_is_sent_once_and_repeats_are_counted(): void
    {
        $this->getJson('/api/__ops/boom');
        $this->getJson('/api/__ops/boom');
        $this->getJson('/api/__ops/boom');

        $this->assertSame(1, $this->teamsPosts());
        Mail::assertSentCount(1);

        // Once the window has passed, the next one goes out with the count.
        $this->travel(16)->minutes();
        $this->getJson('/api/__ops/boom');

        Mail::assertSentCount(2);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => ($mail->facts['Repeats'] ?? null) === '2 more time(s) since the last alert');
    }

    public function test_client_errors_do_not_alert(): void
    {
        $this->getJson('/api/__ops/validation')->assertStatus(422);
        $this->getJson('/no-such-route')->assertStatus(404);

        $this->assertSame(0, $this->teamsPosts());
        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_box_has_no_role(): void
    {
        config(['ops.role' => null]);

        $this->getJson('/api/__ops/boom')->assertStatus(500);

        $this->assertSame(0, $this->teamsPosts());
        Mail::assertNothingSent();
    }

    public function test_a_site_is_reported_down_after_two_misses_and_again_when_it_is_back(): void
    {
        config([
            'ops.role' => 'watcher',
            'ops.uptime.targets' => ['https://brokerapi.test/up'],
            'ops.uptime.failures_before_alert' => 2,
        ]);

        Http::fake([
            self::WEBHOOK => Http::response('', 202),
            'https://brokerapi.test/up' => Http::sequence()
                ->push('', 502)
                ->push('', 502)
                ->push('', 502)
                ->push('ok', 200),
        ]);

        $this->artisan('ops:check-uptime')->assertSuccessful();
        Mail::assertNothingSent(); // one miss is not an outage

        $this->artisan('ops:check-uptime')->assertSuccessful();
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->level === 'critical'
            && str_contains($mail->headline, 'DOWN: brokerapi.test')
            && $mail->facts['Problem'] === 'HTTP 502');

        // Still down a minute later: no second mail inside the reminder window.
        $this->artisan('ops:check-uptime')->assertSuccessful();
        Mail::assertSentCount(1);

        $this->artisan('ops:check-uptime')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->level === 'resolved'
            && str_contains($mail->headline, 'Back up: brokerapi.test'));
    }

    public function test_a_4xx_counts_as_the_site_answering(): void
    {
        config(['ops.role' => 'watcher', 'ops.uptime.targets' => ['https://brokerapi.test/up'], 'ops.uptime.failures_before_alert' => 1]);

        Http::fake([self::WEBHOOK => Http::response('', 202), 'https://brokerapi.test/up' => Http::response('', 404)]);

        $this->artisan('ops:check-uptime')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_stopped_service_is_reported_and_so_is_its_recovery(): void
    {
        config(['ops.services' => ['mysql', 'nginx']]);

        Process::fake([
            '*mysql*' => Process::sequence()
                ->push(Process::result(output: "inactive\n", exitCode: 3))
                ->push(Process::result(output: "active\n")),
            '*nginx*' => Process::result(output: "active\n"),
        ]);

        $this->artisan('ops:check-services')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => str_contains($mail->headline, 'Service stopped: mysql')
            && $mail->facts['State'] === 'inactive');

        $this->artisan('ops:check-services')->assertSuccessful();

        Mail::assertSentCount(2);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => str_contains($mail->headline, 'Running again: mysql'));
    }

    public function test_the_alert_email_renders(): void
    {
        $html = (new OpsAlertMail('🔴 Server error: RuntimeException', 'critical', [
            'Server' => 'test-box',
            'Message' => 'database exploded',
        ]))->render();

        $this->assertStringContainsString('database exploded', $html);
        $this->assertStringContainsString('test-box', $html);
    }
}
