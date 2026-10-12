<?php

namespace Tests\Feature\DtPay;

use App\Pay\Models\PaymentRequest;

/**
 * Payers ask; DollarTraq decides. Money only reaches Stripe (transfer or
 * refund) when an admin approves a request or acts directly.
 */
class PaymentRequestsTest extends DtPayTestCase
{
    private function held(float $amount = 1000.00): array
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment($amount);
        $this->pay($payment['id'], 'card');

        return $payment;
    }

    private function admin(string $method, string $uri, array $data = [])
    {
        return $this->json($method, "/api/v1/dtpay/admin/{$uri}", $data, $this->adminHeaders());
    }

    /*
    | Releases
    */

    public function test_a_broker_release_is_only_a_request_until_an_admin_approves(): void
    {
        $payment = $this->held();
        $this->carrierReady();

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release", ['note' => 'Delivered, POD attached'])
            ->assertCreated()
            ->assertJsonPath('payment.status', 'funded')
            ->assertJsonPath('payment.release_requested', true)
            ->assertJsonPath('payment.release_request.status', 'pending')
            ->assertJsonPath('payment.can.release', false)
            ->assertJsonPath('payment.can.withdraw_release', true)
            ->assertJsonPath('payment.can.refund', false);
        $this->assertCount(0, $this->stripe->transfers, 'asking moves no money');

        $queue = $this->admin('GET', 'requests?type=release')->assertOk()->json('data');
        $this->assertCount(1, $queue);
        $this->assertSame('Delivered, POD attached', $queue[0]['reason']);
        $this->assertTrue($queue[0]['payment']['payout_ready']);
        $this->getJson('/api/v1/dtpay/admin/overview', $this->adminHeaders())->assertJsonPath('pending_releases', 1);

        $this->admin('POST', "requests/{$queue[0]['id']}/approve")->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.payment.status', 'released');
        $this->admin('POST', "requests/{$queue[0]['id']}/approve")->assertStatus(422);

        $this->assertCount(1, $this->stripe->transfers);
        $this->assertSame(100000, $this->stripe->transfers[0]['amountCents']);
        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->assertJsonPath('payment.release_request.status', 'approved');
    }

    public function test_approving_a_release_for_a_carrier_without_payouts_keeps_it_waiting(): void
    {
        $payment = $this->held();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertCreated();
        $id = PaymentRequest::first()->uuid;

        $this->admin('POST', "requests/{$id}/approve")->assertStatus(422);
        $this->assertSame('pending', PaymentRequest::first()->status, 'still waiting, so the admin can approve once the carrier is set up');

        $this->carrierReady();
        $this->admin('POST', "requests/{$id}/approve")->assertOk();
    }

    public function test_a_declined_release_stays_held_and_the_broker_can_ask_again(): void
    {
        $payment = $this->held();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertCreated();
        $id = PaymentRequest::first()->uuid;

        $this->admin('POST', "requests/{$id}/reject", ['note' => 'No POD yet'])->assertOk()->assertJsonPath('request.payment.status', 'funded');
        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")
            ->assertJsonPath('payment.release_request.status', 'rejected')
            ->assertJsonPath('payment.release_request.decision_note', 'No POD yet')
            ->assertJsonPath('payment.can.release', true);

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertCreated();
    }

    public function test_hold_and_disputes_stop_release_requests(): void
    {
        $payment = $this->held();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/hold", ['hold' => true])->assertOk();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);
    }

    public function test_one_open_request_at_a_time_and_withdrawing(): void
    {
        $payment = $this->held();
        $this->carrierReady();

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertCreated();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund", ['reason' => 'Changed our mind'])->assertStatus(422);

        $this->deleteJson("/api/v1/dtpay/payments/{$payment['id']}/release-request")->assertOk()->assertJsonPath('payment.release_requested', false);
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund", ['reason' => 'Shipper cancelled'])->assertCreated();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);

        $this->deleteJson("/api/v1/dtpay/payments/{$payment['id']}/refund-request")->assertOk();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertCreated();
    }

    /*
    | Refunds
    */

    public function test_a_refund_request_needs_a_reason_and_blocks_release(): void
    {
        $payment = $this->held();
        $this->carrierReady();

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund", ['reason' => 'Shipper cancelled the load'])
            ->assertCreated()
            ->assertJsonPath('payment.refund_requested', true)
            ->assertJsonPath('payment.can.release_blocked_reason', 'A refund was requested. DollarTraq is reviewing it.');

        $this->adminRelease($payment['id'])->assertStatus(422);
        $this->assertCount(0, $this->stripe->refunds);
        $this->assertCount(0, $this->stripe->transfers);
    }

    public function test_an_admin_approves_a_refund_once(): void
    {
        $payment = $this->held();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund", ['reason' => 'Shipper cancelled the load'])->assertCreated();

        $queue = $this->admin('GET', 'refund-requests')->assertOk()->json('data');
        $this->assertSame('Northwind Logistics', $queue[0]['company']);

        $this->admin('POST', "requests/{$queue[0]['id']}/approve", ['note' => 'Confirmed with shipper'])->assertOk()
            ->assertJsonPath('request.payment.status', 'refunded')
            ->assertJsonPath('refund_request.status', 'approved');
        $this->admin('POST', "refund-requests/{$queue[0]['id']}/approve")->assertStatus(422);

        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame('ops@dollartraq.com', PaymentRequest::first()->decided_by_email);
    }

    public function test_a_declined_refund_stays_held(): void
    {
        $payment = $this->held();
        $this->carrierReady();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund", ['reason' => 'Shipper cancelled the load'])->assertCreated();
        $id = PaymentRequest::first()->uuid;

        $this->admin('POST', "requests/{$id}/reject")->assertStatus(422);
        $this->admin('POST', "requests/{$id}/reject", ['note' => 'Load was delivered'])->assertOk();

        $this->assertCount(0, $this->stripe->refunds);
        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")->assertJsonPath('payment.refund_request.decision_note', 'Load was delivered');
        $this->adminRelease($payment['id'])->assertOk();
    }

    /*
    | Direct admin actions
    */

    public function test_admin_detail_shows_what_can_be_done(): void
    {
        $payment = $this->held();

        $this->admin('GET', "payments/{$payment['id']}")->assertOk()
            ->assertJsonPath('payment.company', 'Northwind Logistics')
            ->assertJsonPath('payment.admin_can.refund', true)
            ->assertJsonPath('payment.admin_can.release', false)
            ->assertJsonPath('payment.admin_can.payout_link', true);
    }

    public function test_admin_hold_refund_and_a_direct_refund_closes_waiting_requests(): void
    {
        $payment = $this->held();
        $this->carrierReady();
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertCreated();

        $this->admin('POST', "payments/{$payment['id']}/hold", ['hold' => true, 'note' => 'Checking POD'])->assertOk()->assertJsonPath('payment.on_hold', true);
        $this->adminRelease($payment['id'])->assertStatus(422);
        $this->admin('POST', "payments/{$payment['id']}/hold", ['hold' => false])->assertOk();

        $this->admin('POST', "payments/{$payment['id']}/refund")->assertStatus(422);
        $this->admin('POST', "payments/{$payment['id']}/refund", ['reason' => 'Carrier never picked up'])->assertOk()->assertJsonPath('payment.status', 'refunded');

        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame('rejected', PaymentRequest::first()->status, 'the waiting release request is closed');
    }

    public function test_settling_a_dispute_for_the_carrier_releases_and_for_the_payer_refunds(): void
    {
        $this->carrierReady();

        foreach (['carrier' => 'released', 'payer' => 'refunded'] as $outcome => $status) {
            $payment = $this->held();
            $dispute = $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/disputes", ['reason' => 'damaged', 'details' => 'Two pallets arrived crushed.'])
                ->assertCreated()->json('payment.open_dispute.id');

            $this->admin('POST', "payments/{$payment['id']}/disputes/{$dispute}/settle", ['outcome' => $outcome, 'note' => 'Reviewed photos'])
                ->assertOk()
                ->assertJsonPath('payment.status', $status)
                ->assertJsonPath('payment.open_dispute', null);
        }

        $this->assertCount(1, $this->stripe->transfers);
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_admin_can_send_the_carrier_a_payout_link(): void
    {
        $payment = $this->held();

        $url = $this->admin('POST', "payments/{$payment['id']}/payout-link", ['email' => 'pay@powell.test'])->assertOk()->json('url');
        $this->assertStringContainsString('acct_fake_3577340', $url);
    }

    /*
    | Access
    */

    public function test_admin_endpoints_need_the_token_and_an_admin_email(): void
    {
        $this->getJson('/api/v1/dtpay/admin/requests')->assertUnauthorized();
        $this->getJson('/api/v1/dtpay/admin/requests', ['Authorization' => 'Bearer wrong', 'X-Admin-Email' => 'ops@dollartraq.com'])->assertUnauthorized();
        $this->getJson('/api/v1/dtpay/admin/requests', ['Authorization' => 'Bearer '.self::ADMIN_TOKEN])->assertStatus(422);

        $this->actingAsUser($this->user());
        $this->getJson('/api/v1/dtpay/admin/requests')->assertUnauthorized();

        config(['dtpay.admin_token' => null]);
        $this->getJson('/api/v1/dtpay/admin/requests', $this->adminHeaders())->assertUnauthorized();
    }

    public function test_the_old_direct_routes_are_gone(): void
    {
        $this->actingAsUser($this->user());
        $this->postJson('/api/v1/dt-pay/transactions/refund', ['id' => 1])->assertStatus(410);
        $this->postJson('/api/v1/dt-pay/transactions/release', ['id' => 1])->assertStatus(410);
    }
}
