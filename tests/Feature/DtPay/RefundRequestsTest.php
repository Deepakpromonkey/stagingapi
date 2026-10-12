<?php

namespace Tests\Feature\DtPay;

use App\Pay\Models\RefundRequest;

/**
 * Brokers ask; DollarTraq decides. A refund only reaches Stripe when an
 * admin approves it through the admin endpoints.
 */
class RefundRequestsTest extends DtPayTestCase
{
    private const TOKEN = 'test-admin-token-123456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['dtpay.admin_token' => self::TOKEN]);
    }

    private function admin(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN, 'X-Admin-Email' => 'ops@dollartraq.com', 'X-Admin-Name' => 'Ops Desk'];
    }

    private function heldPaymentWithRequest(): array
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment(1000.00);
        $this->pay($payment['id'], 'card');

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund", ['reason' => 'Shipper cancelled the load'])
            ->assertCreated()
            ->assertJsonPath('payment.status', 'funded')
            ->assertJsonPath('payment.refund_requested', true)
            ->assertJsonPath('payment.refund_request.status', 'pending')
            ->assertJsonPath('payment.can.refund', false)
            ->assertJsonPath('payment.can.withdraw_refund', true);

        return $payment;
    }

    public function test_a_request_holds_the_money_and_blocks_release(): void
    {
        $payment = $this->heldPaymentWithRequest();
        $this->carrierReady();

        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")
            ->assertJsonPath('payment.can.release', false)
            ->assertJsonPath('payment.can.release_blocked_reason', 'A refund was requested. DollarTraq is reviewing it.');
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertStatus(422);

        $this->assertCount(0, $this->stripe->refunds);
        $this->assertCount(0, $this->stripe->transfers);
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAsUser($this->user());
        $payment = $this->createPayment();
        $this->pay($payment['id'], 'card');

        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/refund")->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_the_broker_can_withdraw_and_then_release(): void
    {
        $payment = $this->heldPaymentWithRequest();
        $this->carrierReady();

        $this->deleteJson("/api/v1/dtpay/payments/{$payment['id']}/refund-request")->assertOk()->assertJsonPath('payment.refund_requested', false);
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertOk()->assertJsonPath('payment.status', 'released');
    }

    public function test_an_admin_approval_refunds_through_stripe_once(): void
    {
        $payment = $this->heldPaymentWithRequest();

        $list = $this->getJson('/api/v1/dtpay/admin/refund-requests', $this->admin())->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('Shipper cancelled the load', $list[0]['reason']);
        $this->assertSame($payment['id'], $list[0]['payment']['id']);
        $this->assertSame('Northwind Logistics', $list[0]['company']);

        $this->getJson('/api/v1/dtpay/admin/overview', $this->admin())->assertOk()->assertJsonPath('pending_refunds', 1);

        $this->postJson("/api/v1/dtpay/admin/refund-requests/{$list[0]['id']}/approve", ['note' => 'Confirmed with shipper'], $this->admin())
            ->assertOk()
            ->assertJsonPath('refund_request.status', 'approved')
            ->assertJsonPath('refund_request.payment.status', 'refunded')
            ->assertJsonPath('refund_request.payment.company', 'Northwind Logistics');

        $this->assertCount(1, $this->stripe->refunds);

        // A second click (or a second admin) cannot refund again.
        $this->postJson("/api/v1/dtpay/admin/refund-requests/{$list[0]['id']}/approve", [], $this->admin())->assertStatus(422);
        $this->assertCount(1, $this->stripe->refunds);

        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")
            ->assertJsonPath('payment.status', 'refunded')
            ->assertJsonPath('payment.refund_request.status', 'approved');

        $request = RefundRequest::first();
        $this->assertSame('ops@dollartraq.com', $request->decided_by_email);
    }

    public function test_an_admin_rejection_keeps_the_money_held_and_releasable(): void
    {
        $payment = $this->heldPaymentWithRequest();
        $this->carrierReady();
        $id = RefundRequest::first()->uuid;

        $this->postJson("/api/v1/dtpay/admin/refund-requests/{$id}/reject", [], $this->admin())->assertStatus(422);
        $this->postJson("/api/v1/dtpay/admin/refund-requests/{$id}/reject", ['note' => 'Load was delivered, POD attached'], $this->admin())
            ->assertOk()
            ->assertJsonPath('refund_request.status', 'rejected')
            ->assertJsonPath('refund_request.payment.status', 'funded');

        $this->assertCount(0, $this->stripe->refunds);

        $this->getJson("/api/v1/dtpay/payments/{$payment['id']}")
            ->assertJsonPath('payment.refund_request.status', 'rejected')
            ->assertJsonPath('payment.refund_request.decision_note', 'Load was delivered, POD attached')
            ->assertJsonPath('payment.can.refund', true);
        $this->postJson("/api/v1/dtpay/payments/{$payment['id']}/release")->assertOk();
    }

    public function test_admin_endpoints_need_the_token_and_an_admin_email(): void
    {
        $this->getJson('/api/v1/dtpay/admin/refund-requests')->assertUnauthorized();
        $this->getJson('/api/v1/dtpay/admin/refund-requests', ['Authorization' => 'Bearer wrong', 'X-Admin-Email' => 'ops@dollartraq.com'])->assertUnauthorized();
        $this->getJson('/api/v1/dtpay/admin/refund-requests', ['Authorization' => 'Bearer '.self::TOKEN])->assertStatus(422);

        // A broker's own sign-in is not enough.
        $this->actingAsUser($this->user());
        $this->getJson('/api/v1/dtpay/admin/refund-requests')->assertUnauthorized();

        // No token configured: switched off entirely.
        config(['dtpay.admin_token' => null]);
        $this->getJson('/api/v1/dtpay/admin/refund-requests', $this->admin())->assertUnauthorized();
    }

    public function test_the_old_direct_refund_route_is_gone(): void
    {
        $this->actingAsUser($this->user());
        $this->postJson('/api/v1/dt-pay/transactions/refund', ['id' => 1])->assertStatus(410);
        $this->postJson('/api/v1/dt-pay/transactions/release', ['id' => 1])->assertStatus(410);
    }
}
