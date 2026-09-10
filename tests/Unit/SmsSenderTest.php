<?php

namespace Tests\Unit;

use App\Services\SmsSender;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outbound SMS: who the message actually goes to.
 *
 * Both behaviours under test exist because a one-time code sent to the wrong
 * handset is worse than one that fails outright — the sender is told it worked
 * and the person waiting never finds out why nothing arrived.
 */
class SmsSenderTest extends TestCase
{
    private const ENDPOINT = 'https://api.telnyx.com/v2/messages';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telnyx.key' => 'test-key',
            'services.telnyx.from' => '+15551234567',
            'services.telnyx.messaging_profile_id' => null,
            'services.telnyx.override_to' => null,
        ]);

        // Telnyx reports the per-recipient status separately from the HTTP
        // code, and the sender only counts the message as sent on this.
        Http::fake([
            self::ENDPOINT => Http::response(['data' => ['to' => [['status' => 'queued']]]]),
        ]);
    }

    /** The payload of the single request the sender made. */
    private function sent(): array
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'No SMS was sent.');

        return $recorded[0][0]->data();
    }

    // ── Country pinning ──────────────────────────────────────────────────────

    public function test_a_listed_number_is_pinned_to_its_own_country(): void
    {
        // Both onboarding and signup stamp +1 on a bare ten-digit number, which
        // turns this Indian handset into a real US number belonging to someone
        // else. Pinning happens at the one point every message passes through.
        $this->assertSame('+918076734039', SmsSender::forceCountry('8076734039'));
    }

    public function test_it_undoes_a_dial_code_already_applied_wrongly(): void
    {
        $this->assertSame('+918076734039', SmsSender::forceCountry('+18076734039'));
        $this->assertSame('+918076734039', SmsSender::forceCountry('00918076734039'));
        $this->assertSame('+918076734039', SmsSender::forceCountry('+918076734039'));
    }

    public function test_a_foreign_number_ending_the_same_way_is_left_alone(): void
    {
        // The trailing digits match, but this is somebody else's handset — only
        // the prefixes this application itself produces may be rewritten.
        $this->assertSame('+448076734039', SmsSender::forceCountry('+448076734039'));
    }

    public function test_an_unlisted_number_passes_straight_through(): void
    {
        $this->assertSame('+15551239876', SmsSender::forceCountry('+15551239876'));
    }

    // ── Test redirect ────────────────────────────────────────────────────────

    public function test_without_an_override_the_message_goes_to_the_real_recipient(): void
    {
        app(SmsSender::class)->send('+15551239876', 'Your code is 123456', 'onboarding OTP');

        $payload = $this->sent();

        $this->assertSame('+15551239876', $payload['to']);
        $this->assertSame('Your code is 123456', $payload['text']);
    }

    public function test_an_override_redirects_every_message_to_one_handset(): void
    {
        config(['services.telnyx.override_to' => '+918076734039']);

        app(SmsSender::class)->send('+15551239876', 'Your code is 123456', 'onboarding OTP');

        $this->assertSame('+918076734039', $this->sent()['to']);
    }

    public function test_a_redirected_message_says_who_it_was_for(): void
    {
        config(['services.telnyx.override_to' => '+918076734039']);

        app(SmsSender::class)->send('+15551239876', 'Your code is 123456', 'onboarding OTP');

        $text = $this->sent()['text'];

        // Enough to tell two onboardings apart, without putting a full phone
        // number into somebody else's message history.
        $this->assertStringContainsString('9876', $text);
        $this->assertStringNotContainsString('+15551239876', $text);

        // The code itself still has to survive the prefix.
        $this->assertStringContainsString('123456', $text);
    }

    public function test_redirecting_to_the_recipient_itself_changes_nothing(): void
    {
        config(['services.telnyx.override_to' => '+918076734039']);

        app(SmsSender::class)->send('+918076734039', 'Your code is 123456', 'onboarding OTP');

        $payload = $this->sent();

        $this->assertSame('+918076734039', $payload['to']);
        $this->assertSame('Your code is 123456', $payload['text']);
    }

    public function test_the_override_applies_after_the_country_is_pinned(): void
    {
        config(['services.telnyx.override_to' => '+15550001111']);

        // The intended recipient is corrected first, so the note in the message
        // names the number the carrier would really have been sent.
        app(SmsSender::class)->send('8076734039', 'Your code is 123456', 'onboarding OTP');

        $payload = $this->sent();

        $this->assertSame('+15550001111', $payload['to']);
        $this->assertStringContainsString('4039', $payload['text']);
    }
}
