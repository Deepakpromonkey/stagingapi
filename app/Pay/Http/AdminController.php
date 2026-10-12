<?php

namespace App\Pay\Http;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Pay\Models\Payment;
use App\Pay\Models\RefundRequest;
use App\Pay\PaymentRuleViolation;
use App\Pay\PaymentService;
use App\Pay\Stripe\Gateway;
use App\Pay\Stripe\PaymentsUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DT Pay for DollarTraq admins (through the admin backend): the escrow
 * across every broker, and the refund requests waiting for a decision.
 */
class AdminController extends Controller
{
    public function __construct(private PaymentService $payments, private Gateway $stripe) {}

    public function overview(): JsonResponse
    {
        $sum = fn ($q) => (int) $q->sum('amount_cents');

        return response()->json([
            'livemode' => $this->safeLivemode(),
            'held' => Presenter::money($sum(Payment::where('status', Payment::FUNDED)))
                + ['count' => Payment::where('status', Payment::FUNDED)->count()],
            'clearing' => Presenter::money($sum(Payment::where('status', Payment::PROCESSING)))
                + ['count' => Payment::where('status', Payment::PROCESSING)->count()],
            'released_today' => Presenter::money($sum(Payment::where('status', Payment::RELEASED)->where('released_at', '>=', now()->startOfDay()))),
            'released_total' => Presenter::money($sum(Payment::where('status', Payment::RELEASED))),
            'fees_earned' => Presenter::money((int) Payment::whereIn('status', [Payment::FUNDED, Payment::RELEASED])->sum(DB::raw('platform_fee_cents + card_fee_cents'))),
            'pending_refunds' => RefundRequest::where('status', RefundRequest::PENDING)->count(),
            'platform_fee_pct' => (float) config('dtpay.fees.platform_pct'),
        ]);
    }

    public function refundRequests(Request $request): JsonResponse
    {
        $status = $request->query('status', RefundRequest::PENDING);

        $page = RefundRequest::with('payment')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->paginate(min(50, (int) $request->query('per_page', 25)));

        $companies = Company::whereIn('id', collect($page->items())->pluck('company_id'))->pluck('company_name', 'id');
        $users = User::whereIn('id', collect($page->items())->pluck('requested_by')->filter())->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn (RefundRequest $r) => Presenter::refundRequest($r) + [
                'decided_by_name' => $r->decided_by_name,
                'decided_by_email' => $r->decided_by_email,
                'company' => $companies[$r->company_id] ?? null,
                'requested_by' => ($u = $users[$r->requested_by] ?? null) ? ['name' => trim("{$u->first_name} {$u->last_name}"), 'email' => $u->email] : null,
                'payment' => $this->paymentRow($r->payment),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $page = Payment::query()
            ->when($status === 'held', fn ($w) => $w->where('status', Payment::FUNDED))
            ->when($status && $status !== 'held', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('reference', 'like', "%{$q}%")
                ->orWhere('load_number', 'like', "%{$q}%")
                ->orWhere('carrier_name', 'like', "%{$q}%")
                ->orWhere('carrier_dot', $q)))
            ->latest('id')
            ->paginate(min(50, (int) $request->query('per_page', 25)));

        $companies = Company::whereIn('id', collect($page->items())->pluck('company_id')->filter())->pluck('company_name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Payment $p) => $this->paymentRow($p, $companies[$p->company_id] ?? null))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $admin = $request->attributes->get('dtpay_admin');

        return $this->decide(fn () => $this->payments->approveRefundRequest($this->find($id), $admin['name'], $admin['email'], $data['note'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $admin = $request->attributes->get('dtpay_admin');

        return $this->decide(fn () => $this->payments->rejectRefundRequest($this->find($id), $admin['name'], $admin['email'], $data['note']));
    }

    private function decide(callable $action): JsonResponse
    {
        try {
            $r = $action();
        } catch (PaymentRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => 'DT Pay is not available right now.', 'detail' => $e->getMessage()], 503);
        }

        return response()->json(['refund_request' => Presenter::refundRequest($r) + [
            'company' => $company = Company::whereKey($r->company_id)->value('company_name'),
            'payment' => $this->paymentRow($r->payment->refresh(), $company),
        ]]);
    }

    private function find(string $id): RefundRequest
    {
        return RefundRequest::where('uuid', $id)->first() ?? abort(404, 'This refund request was not found.');
    }

    private function paymentRow(Payment $p, ?string $company = null): array
    {
        return Presenter::summary($p) + [
            'company' => $company,
            'fees' => Presenter::money($p->platform_fee_cents + $p->card_fee_cents),
            'method_type' => $p->method_type,
            'livemode' => $p->livemode,
        ];
    }

    private function safeLivemode(): ?bool
    {
        try {
            return $this->stripe->livemode();
        } catch (\Throwable) {
            return null;
        }
    }
}
