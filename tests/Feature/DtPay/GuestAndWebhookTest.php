<?php

namespace Tests\Feature\DtPay;

use App\Pay\Models\Payment;
use App\Pay\Models\PayoutAccount;
use App\Pay\Stripe\PaymentsUnavailable;
use App\Pay\Stripe\StripeGateway;
use Illuminate\Support\Str;

class GuestAndWebhookTest extends DtPayTestCase
{
    private function guestPayment(): array
    {
        return $this->postJson('/api/v1/dtpay/guest/payments', [
            'carrier_dot' => '3577340',
            'carrier_name' => 'Powell Distributing',
            'amount' => 1000,
            'load_number' => 'BOL-88',
            'payer_name' => 'Sam Okafor',
            'payer_company' => 'Okafor Produce',
            'payer_email' => 'sam@okafor.test',
        ])->assertCreated()->json();
    }

    public function test_guest_pays_at_the_guest_rate_and_releases_through_the_token(): void
    {
        $created = $this->guestPayment();
        $token = $created['token'];

        $this->assertSame(48, strlen($token));
        $this->assertStringEndsWith('/guest-pay/track/'.$token, $created['tracking_url']);
        $this->assertSame('draft', $created['payment']['status']);

        $intent = $this->postJson("/api/v1/dtpay/guest/payments/{$token}/intent", ['method_type' => 'card'])->assertOk()->json();
        $this->assertNotEmpty($intent['client_secret']);
        $this->assertSame(104900, $intent['payment']['breakdown']['total']['cents'], '2.0% guest fee + 2.9% card');

        // The browser confirms with Stripe; then the page reads the result back.
        $this->stripe->settle('pi_fake_1', 'succeeded');
        $this->postJson("/api/v1/dtpay/guest/payments/{$token}/sync")->assertOk()->assertJsonPath('payment.status', 'funded');

        PayoutAccount::create(['carrier_dot' => '3577340', 'livemode' => false, 'stripe_account_id' => 'acct_fake_3577340', 'transfers_active' => true]);

        $this->postJson("/api/v1/dtpay/guest/payments/{$token}/release")->assertOk()->assertJsonPath('payment.status', 'released');
        $this->assertSame(100000, $this->stripe->transfers[0]['amountCents']);
    }

    public function test_guest_can_dispute_while_held(): void
    {
        $token = $this->guestPayment()['token'];
        $this->postJson("/api/v1/dtpay/guest/payments/{$token}/intent", ['method_type' => 'us_bank_account'])->assertOk();
        $this->stripe->settle('pi_fake_1', 'processing');
        $this->postJson("/api/v1/dtpay/guest/payments/{$token}/sync")->assertJsonPath('payment.status', 'processing');

        $this->postJson("/api/v1/dtpay/guest/payments/{$token}/dispute", ['reason' => 'not_delivered', 'details' => 'Nothing arrived at the dock.'])
            ->assertCreated()->assertJsonPath('payment.open_dispute.reason', 'not_delivered');
    }

    public function test_a_wrong_token_finds_nothing_and_broker_payments_are_unreachable(): void
    {
        $this->getJson('/api/v1/dtpay/guest/payments/'.Str::random(48))->assertNotFound();
        $this->getJson('/api/v1/dtpay/guest/payments/short')->assertNotFound();

        $this->actingAsUser($this->user());
        $broker = $this->createPayment();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/dtpay/guest/payments/'.$broker['id'])->assertNotFound();
    }

    public function test_guest_validation(): void
    {
        $this->postJson('/api/v1/dtpay/guest/payments', ['carrier_dot' => 'abc', 'amount' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['carrier_dot', 'amount', 'payer_name', 'payer_email']);
    }

    public function test_webhooks_need_a_valid_signature_and_are_handled_once(): void
    {
        $this->call('POST', '/api/v1/dtpay/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);

        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'us_bank_account');

        $event = json_encode(['id' => 'evt_once', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_fake_1', 'status' => 'succeeded', 'latest_charge' => 'ch_x', 'metadata' => []]]]);

        foreach ([1, 2] as $_) {
            $this->call('POST', '/api/v1/dtpay/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid', 'CONTENT_TYPE' => 'application/json'], $event)->assertOk();
        }

        $funded = Payment::where('uuid', $payment['id'])->first();
        $this->assertSame('funded', $funded->status);
        $this->assertSame(1, $funded->events()->where('type', 'funded')->count());
    }

    public function test_account_updates_and_chargebacks(): void
    {
        PayoutAccount::create(['carrier_dot' => '3577340', 'livemode' => false, 'stripe_account_id' => 'acct_x']);

        $this->sendEvent('account.updated', ['id' => 'acct_x', 'details_submitted' => true, 'payouts_enabled' => true, 'capabilities' => ['transfers' => 'active']]);
        $this->assertTrue(PayoutAccount::first()->ready());

        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');

        $this->sendEvent('charge.dispute.created', ['payment_intent' => 'pi_fake_1', 'reason' => 'fraudulent']);

        $held = Payment::where('uuid', $payment['id'])->first();
        $this->assertTrue($held->on_hold);
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);
    }

    public function test_a_live_key_is_refused_unless_allowed(): void
    {
        config(['dtpay.stripe.secret' => 'sk_live_'.Str::random(20), 'dtpay.allow_live' => false]);

        $gateway = new StripeGateway;
        $this->assertTrue($gateway->livemode());

        $this->expectException(PaymentsUnavailable::class);
        $gateway->createCustomer('X', null, []);
    }

    public function test_no_key_means_unavailable_not_a_crash(): void
    {
        config(['dtpay.stripe.secret' => null]);
        $this->app->instance(\App\Pay\Stripe\Gateway::class, new StripeGateway);

        $this->actingAsUser($this->user());
        $this->getJson('/api/v1/dtpay/methods')->assertStatus(503);
    }

    private function sendEvent(string $type, array $object): void
    {
        $this->call('POST', '/api/v1/dtpay/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 'evt_'.Str::random(10), 'type' => $type, 'data' => ['object' => $object]]))->assertOk();
    }
}
