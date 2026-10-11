<?php

namespace Tests\Feature\DtPay;

use App\Models\Shipment;
use App\Pay\Fees;
use App\Pay\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class BrokerPaymentsTest extends DtPayTestCase
{
    public function test_fees_are_added_on_top_and_the_carrier_gets_the_full_amount(): void
    {
        $ach = Fees::quote(100000, 'us_bank_account');
        $this->assertSame([100000, 1200, 0, 101200], [$ach['amount_cents'], $ach['platform_fee_cents'], $ach['card_fee_cents'], $ach['total_cents']]);

        $card = Fees::quote(100000, 'card');
        $this->assertSame([1200, 2900, 104100], [$card['platform_fee_cents'], $card['card_fee_cents'], $card['total_cents']]);

        $guest = Fees::quote(100000, 'us_bank_account', true);
        $this->assertSame(2000, $guest['platform_fee_cents']);

        // Rounds half up to the cent: 1.2% of $12.34 = 14.808c -> 15c.
        $this->assertSame(15, Fees::quote(1234, 'us_bank_account')['platform_fee_cents']);
    }

    public function test_a_card_payment_is_funded_at_once_and_quotes_card_fees(): void
    {
        $this->actingAsUser($this->user());

        $payment = $this->createPayment(1000.00);
        $this->assertSame('draft', $payment['status']);
        $this->assertMatchesRegularExpression('/^DTP-\d{6}$/', $payment['reference']);

        $paid = $this->pay($payment['id'], 'card');

        $this->assertSame('funded', $paid['status']);
        $this->assertSame(104100, $paid['breakdown']['total']['cents']);
        $this->assertSame(100000, $paid['breakdown']['carrier_receives']['cents']);
        $this->assertSame(104100, $this->stripe->intents['pi_fake_1']['amount']);
    }

    public function test_an_ach_payment_waits_for_the_webhook_before_it_is_funded(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment(500.00);

        $paid = $this->pay($payment['id'], 'us_bank_account');
        $this->assertSame('processing', $paid['status']);
        $this->assertSame(50600, $paid['breakdown']['total']['cents'], 'no card fee on ACH');
        $this->assertArrayHasKey('mandate_data', $this->stripe->intents['pi_fake_1']);

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);

        $this->webhook('payment_intent.succeeded', ['id' => 'pi_fake_1', 'status' => 'succeeded', 'latest_charge' => 'ch_1', 'metadata' => []]);

        $this->assertSame('funded', Payment::where('uuid', $payment['id'])->value('status'));
    }

    public function test_a_failed_ach_debit_can_be_paid_again(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment(500.00);
        $this->pay($payment['id'], 'us_bank_account');

        $this->webhook('payment_intent.payment_failed', ['id' => 'pi_fake_1', 'status' => 'requires_payment_method', 'last_payment_error' => ['message' => 'Insufficient funds'], 'metadata' => []]);

        $failed = $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->json('payment');
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('Insufficient funds', $failed['failure_reason']);
        $this->assertTrue($failed['can']['pay']);

        $this->assertSame('funded', $this->pay($payment['id'], 'card')['status']);
    }

    public function test_release_sends_exactly_the_load_amount_and_only_once(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment(1000.00);
        $this->pay($payment['id'], 'card');
        $this->carrierReady();

        $released = $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertOk()->json('payment');
        $this->assertSame('released', $released['status']);

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);

        $this->assertCount(1, $this->stripe->transfers, 'never pays twice');
        $this->assertSame(100000, $this->stripe->transfers[0]['amountCents'], 'the load amount, not the total charged');
        $this->assertSame('acct_fake_3577340', $this->stripe->transfers[0]['destination']);
        $this->assertSame('ch_pi_fake_1', $this->stripe->transfers[0]['sourceCharge']);
    }

    public function test_release_needs_the_carrier_payout_account(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")
            ->assertStatus(422)->assertJsonFragment(['message' => 'The carrier has not finished setting up payouts yet. Send them the payout setup link.']);

        $link = $this->postJson('/api/v1/dtpay/carriers/3577340/payout-link', ['email' => 'pay@powell.test'])->assertOk()->json('url');
        $this->assertStringContainsString('acct_fake_3577340', $link);

        $this->stripe->accounts['acct_fake_3577340'] = ['details_submitted' => true, 'payouts_enabled' => true, 'transfers_active' => true];
        $this->getJson('/api/v1/dtpay/carriers/3577340/payout')->assertJson(['ready' => true]);

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertOk();
    }

    public function test_release_rechecks_a_carrier_that_finished_setup_since(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');

        $this->postJson('/api/v1/dtpay/carriers/3577340/payout-link')->assertOk();
        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->assertJsonPath('payment.can.release', false)
            ->assertJsonPath('payment.can.release_blocked_reason', 'The carrier has not finished payout setup.');

        // The carrier completes Stripe's onboarding; no webhook reaches us.
        $this->stripe->accounts['acct_fake_3577340'] = ['details_submitted' => true, 'payouts_enabled' => true, 'transfers_active' => true];

        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->assertJsonPath('payment.can.release', true);
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertOk()->assertJsonPath('payment.status', 'released');
    }

    public function test_a_failed_transfer_leaves_the_money_held(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');
        $this->carrierReady();
        $this->stripe->failTransfers = true;

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);

        $after = $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->json('payment');
        $this->assertSame('funded', $after['status']);
        $this->assertContains('release_failed', array_column($after['timeline'], 'type'));
    }

    public function test_hold_and_disputes_block_release(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');
        $this->carrierReady();

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/hold", ['hold' => true])->assertOk();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/hold", ['hold' => false])->assertOk();

        $disputed = $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/disputes", [
            'reason' => 'damaged',
            'details' => 'Two pallets arrived crushed.',
            'evidence' => [UploadedFile::fake()->image('pallets.jpg')],
        ])->assertCreated()->json('payment');
        $this->assertSame('damaged', $disputed['open_dispute']['reason']);
        $this->assertSame(1, $disputed['open_dispute']['evidence_count']);

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/disputes/{$disputed['open_dispute']['id']}/resolve", ['resolution' => 'Carrier credited the damage.'])->assertOk();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertOk();
    }

    public function test_refund_only_while_held_never_after_release(): void
    {
        $this->actingAsUser($this->user());

        $held = $this->createPayment();
        $this->pay($held['id'], 'card');
        $this->postJson("/api/v1/dtpay/payments/{$held['id']}/refund")->assertOk()->assertJsonPath('payment.status', 'refunded');
        $this->postJson("/api/v1/dtpay/payments/{$held['id']}/refund")->assertStatus(422);
        $this->assertCount(1, $this->stripe->refunds);

        $released = $this->createPayment(200.00);
        $this->pay($released['id'], 'card');
        $this->carrierReady();
        $this->postJson("/api/v1/dtpay/payments/{$released['id']}/release")->assertOk();
        $this->postJson("/api/v1/dtpay/payments/{$released['id']}/refund")->assertStatus(422);
        $this->assertCount(1, $this->stripe->refunds, 'no refund once the carrier has the money');
    }

    public function test_another_company_cannot_see_or_move_the_payment(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');
        $this->carrierReady();

        $this->actingAsUser($this->user('owner_admin', $this->company('Rival Freight')));

        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->assertNotFound();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertNotFound();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund")->assertNotFound();
        $this->getJson('/api/v1/dtpay/payments')->assertJsonCount(0, 'data');

        $this->assertCount(0, $this->stripe->transfers);
    }

    public function test_a_payment_method_from_another_company_is_refused(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $foreign = $this->stripe->addMethod('cus_someone_else', 'card');

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/pay", ['payment_method_id' => $foreign])->assertStatus(422);
        $this->assertCount(0, $this->stripe->intents);
    }

    public function test_release_limits_follow_the_role(): void
    {
        $company = $this->company();
        $this->actingAsUser($this->user('owner_admin', $company));
        $small = $this->createPayment(4000.00);
        $this->pay($small['id'], 'card');
        $big = $this->createPayment(6000.00);
        $this->pay($big['id'], 'card');
        $this->carrierReady();

        // Senior agents release up to $5,000.
        $this->actingAsUser($this->user('senior_agent', $company));
        $this->postJson("/api/v1/dtpay/payments/{$big['id']}/release")->assertForbidden();
        $this->postJson("/api/v1/dtpay/payments/{$small['id']}/release")->assertOk();

        // Agents create and fund, but cannot release or refund.
        $this->actingAsUser($this->user('agent', $company));
        $this->getJson("/api/v1/dtpay/payments/{$big['id']}")->assertOk()->assertJsonPath('payment.can.release', false);
        $this->postJson("/api/v1/dtpay/payments/{$big['id']}/release")->assertForbidden();
        $this->postJson("/api/v1/dtpay/payments/{$big['id']}/refund")->assertForbidden();

        // Viewers have no DT Pay at all.
        $this->actingAsUser($this->user('viewer', $company));
        $this->getJson('/api/v1/dtpay/payments')->assertForbidden();
    }

    public function test_a_shipment_payment_takes_the_load_details_and_the_load_drops_off_the_list(): void
    {
        $user = $this->actingAsUser($this->user());

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'shipment_no' => 'SHP-1001',
            'pro_number' => 'PRO-77',
            'carrier_name' => 'Powell Distributing',
            'carrier_dot' => '3577340',
            'tracking_method' => 'driver_phone',
            'status' => 'active',
            'origin' => 'Dallas, TX',
            'destination' => 'Memphis, TN',
        ]);

        $this->getJson('/api/v1/dtpay/shipments')->assertJsonPath('data.0.id', $shipment->id);

        $payment = $this->postJson('/api/v1/dtpay/payments', ['source' => 'shipment', 'shipment_id' => $shipment->id, 'amount' => 2500])
            ->assertCreated()->json('payment');

        $this->assertSame('PRO-77', $payment['load_number']);
        $this->assertSame('3577340', $payment['carrier']['dot']);
        $this->assertSame('Dallas, TX', $payment['origin']);

        $this->getJson('/api/v1/dtpay/shipments')->assertJsonCount(0, 'data');
    }

    public function test_documents_are_stored_and_downloaded_by_the_owner_only(): void
    {
        $this->actingAsUser($this->user());

        $payment = $this->postJson('/api/v1/dtpay/payments', [
            'source' => 'manual', 'carrier_dot' => '3577340', 'amount' => 900,
            'rate_confirmation' => UploadedFile::fake()->create('rate-con.pdf', 40, 'application/pdf'),
        ])->assertCreated()->json('payment');
        $this->assertTrue($payment['documents']['rate_confirmation']);

        $this->pay($payment['id'], 'card');
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/pod", ['file' => UploadedFile::fake()->create('pod.pdf', 40, 'application/pdf')])
            ->assertOk()->assertJsonPath('payment.documents.pod', true);

        $this->get("/api/v1/dtpay/payments/{$payment['id']}/files/pod")->assertOk();

        $this->actingAsUser($this->user('owner_admin', $this->company('Rival Freight')));
        $this->get("/api/v1/dtpay/payments/{$payment['id']}/files/pod")->assertNotFound();
    }

    public function test_an_unpaid_payment_can_be_cancelled_but_not_a_funded_one(): void
    {
        $this->actingAsUser($this->user());

        $draft = $this->createPayment();
        $this->postJson("/api/v1/dtpay/payments/{$draft['id']}/cancel")->assertOk()->assertJsonPath('payment.status', 'cancelled');

        $funded = $this->createPayment();
        $this->pay($funded['id'], 'card');
        $this->postJson("/api/v1/dtpay/payments/{$funded['id']}/cancel")->assertStatus(422);
    }

    public function test_summary_and_list(): void
    {
        $this->actingAsUser($this->user());

        $a = $this->createPayment(1000);
        $this->pay($a['id'], 'card');
        $this->createPayment(300);

        $summary = $this->getJson('/api/v1/dtpay/summary')->assertOk()->json();
        $this->assertSame(100000, $summary['held']['cents']);
        $this->assertSame(1, $summary['held']['count']);
        $this->assertSame(1, $summary['awaiting_payment']['count']);

        $this->getJson('/api/v1/dtpay/payments?status=held')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/dtpay/payments?q=LD-5521')->assertJsonCount(2, 'data');
    }

    public function test_funding_methods_list_and_remove_only_own(): void
    {
        $this->actingAsUser($this->user());

        $customer = app(\App\Pay\PaymentService::class)->customerFor(auth()->user()->company);
        $mine = $this->stripe->addMethod($customer, 'us_bank_account');
        $theirs = $this->stripe->addMethod('cus_other', 'card');

        $this->getJson('/api/v1/dtpay/methods')->assertOk()->assertJsonCount(1, 'methods');
        $this->postJson('/api/v1/dtpay/methods/setup-intent', ['type' => 'us_bank_account'])->assertJson(['client_secret' => 'seti_fake_secret']);

        $this->deleteJson("/api/v1/dtpay/methods/{$theirs}")->assertNotFound();
        $this->deleteJson("/api/v1/dtpay/methods/{$mine}")->assertOk();
        $this->getJson('/api/v1/dtpay/methods')->assertJsonCount(0, 'methods');
    }

    private function webhook(string $type, array $object): void
    {
        $this->call('POST', '/api/v1/dtpay/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 'evt_'.Str::random(10), 'type' => $type, 'data' => ['object' => $object]]))->assertOk();
    }
}
