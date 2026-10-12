<?php

namespace App\Pay\Http;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Pay\Models\Dispute;
use App\Pay\Models\Payment;
use App\Pay\Models\PaymentRequest;
use App\Pay\Models\PayoutAccount;
use App\Pay\PaymentRuleViolation;
use App\Pay\PaymentService;
use App\Pay\Stripe\Gateway;
use App\Pay\Stripe\PaymentsUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * DT Pay for DollarTraq admins (through the admin backend): escrow across
 * every broker, the release and refund requests waiting for a decision,
 * and direct control of any held payment - release, refund, hold, settle
 * a dispute, send the carrier their payout setup link.
 */
class AdminController extends Controller
{
    public function __construct(private PaymentService $payments, private Gateway $stripe) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function overview(): JsonResponse
    {
        $sum = fn ($q) => (int) $q->sum('amount_cents');
        $pending = PaymentRequest::where('status', PaymentRequest::PENDING)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        return response()->json([
            'livemode' => $this->safeLivemode(),
            'held' => Presenter::money($sum(Payment::where('status', Payment::FUNDED)))
                + ['count' => Payment::where('status', Payment::FUNDED)->count()],
            'clearing' => Presenter::money($sum(Payment::where('status', Payment::PROCESSING)))
                + ['count' => Payment::where('status', Payment::PROCESSING)->count()],
            'released_today' => Presenter::money($sum(Payment::where('status', Payment::RELEASED)->where('released_at', '>=', now()->startOfDay()))),
            'released_total' => Presenter::money($sum(Payment::where('status', Payment::RELEASED))),
            'fees_earned' => Presenter::money((int) Payment::whereIn('status', [Payment::FUNDED, Payment::RELEASED])->sum(DB::raw('platform_fee_cents + card_fee_cents'))),
            'pending_releases' => (int) ($pending[PaymentRequest::RELEASE] ?? 0),
            'pending_refunds' => (int) ($pending[PaymentRequest::REFUND] ?? 0),
            'open_disputes' => Dispute::where('status', Dispute::OPEN)->count(),
            'platform_fee_pct' => (float) config('dtpay.fees.platform_pct'),
        ]);
    }

    /** Release and refund requests. ?type=release|refund, ?status=pending|all|... */
    public function requests(Request $request, ?string $type = null): JsonResponse
    {
        $type ??= $request->query('type');
        $status = $request->query('status', PaymentRequest::PENDING);

        $page = PaymentRequest::with('payment')
            ->when(in_array($type, [PaymentRequest::RELEASE, PaymentRequest::REFUND], true), fn ($q) => $q->where('type', $type))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->oldest('id')
            ->paginate(min(50, (int) $request->query('per_page', 25)));

        $items = collect($page->items());
        $companies = Company::whereIn('id', $items->pluck('company_id'))->pluck('company_name', 'id');
        $users = User::whereIn('id', $items->where('requested_by_type', 'broker')->pluck('requested_by')->filter())
            ->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        return response()->json([
            'data' => $items->map(function (PaymentRequest $r) use ($companies, $users) {
                $u = $r->requested_by_type === 'broker' ? ($users[$r->requested_by] ?? null) : null;
                $payment = $r->payment;

                return Presenter::request($r) + [
                    'decided_by_name' => $r->decided_by_name,
                    'decided_by_email' => $r->decided_by_email,
                    'company' => $companies[$r->company_id] ?? ($payment->payer_company ?: null),
                    'requested_by' => $u
                        ? ['name' => trim("{$u->first_name} {$u->last_name}"), 'email' => $u->email]
                        : ($r->requested_by_type === 'guest' ? ['name' => $payment->payer_name, 'email' => $payment->payer_email, 'guest' => true] : null),
                    'payment' => $this->row($payment, $companies[$r->company_id] ?? null) + [
                        'pod' => (bool) $payment->pod_path,
                        'payout_ready' => (bool) $this->payments->payoutAccount($payment->carrier_dot)?->ready(),
                    ],
                ];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $page = Payment::query()
            ->when($status === 'held', fn ($w) => $w->where('status', Payment::FUNDED))
            ->when($status === 'disputed', fn ($w) => $w->whereIn('id', Dispute::where('status', Dispute::OPEN)->select('payment_id')))
            ->when($status && ! in_array($status, ['held', 'disputed'], true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('reference', 'like', "%{$q}%")
                ->orWhere('load_number', 'like', "%{$q}%")
                ->orWhere('carrier_name', 'like', "%{$q}%")
                ->orWhere('carrier_dot', $q)))
            ->latest('id')
            ->paginate(min(50, (int) $request->query('per_page', 25)));

        $companies = Company::whereIn('id', collect($page->items())->pluck('company_id')->filter())->pluck('company_name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Payment $p) => $this->row($p, $companies[$p->company_id] ?? null))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['payment' => $this->detail($this->find($id))]);
    }

    public function file(Request $request, string $id, string $kind)
    {
        $payment = $this->find($id);

        $path = match ($kind) {
            'rate-confirmation' => $payment->rate_confirmation_path,
            'pod' => $payment->pod_path,
            default => null,
        };

        if ($kind === 'evidence') {
            $dispute = Dispute::where('payment_id', $payment->id)->find((int) $request->query('dispute'))
                ?? abort(404, 'That dispute was not found.');
            $path = ($dispute->evidence_paths ?? [])[(int) $request->query('index', 0)] ?? null;
        }

        abort_unless($path && Storage::disk(config('dtpay.disk'))->exists($path), 404, 'That file was not found.');

        return Storage::disk(config('dtpay.disk'))->download($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Deciding requests
    |--------------------------------------------------------------------------
    */

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $admin = $this->admin($request);

        return $this->decide(fn () => $this->payments->approveRequest($this->findRequest($id), $admin['name'], $admin['email'], $data['note'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $admin = $this->admin($request);

        return $this->decide(fn () => $this->payments->rejectRequest($this->findRequest($id), $admin['name'], $admin['email'], $data['note']));
    }

    /*
    |--------------------------------------------------------------------------
    | Acting on a payment directly
    |--------------------------------------------------------------------------
    */

    public function release(Request $request, string $id): JsonResponse
    {
        $payment = $this->find($id);

        return $this->act($payment, fn () => $this->payments->release($payment, 'admin', null));
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $payment = $this->find($id);
        $admin = $this->admin($request);

        return $this->act($payment, fn () => $this->payments->refund($payment, 'admin', null, "By DollarTraq ({$admin['name']}): {$data['reason']}"));
    }

    public function hold(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['hold' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $payment = $this->find($id);
        $admin = $this->admin($request);
        $note = trim('By DollarTraq ('.$admin['name'].')'.(! empty($data['note']) ? ': '.$data['note'] : ''));

        return $this->act($payment, fn () => $this->payments->setHold($payment, (bool) $data['hold'], 'admin', null, $note));
    }

    public function settleDispute(Request $request, string $id, string $disputeId): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['carrier', 'payer', 'close'])],
            'note' => ['required', 'string', 'min:3', 'max:400'],
        ]);
        $payment = $this->find($id);
        $dispute = Dispute::where('payment_id', $payment->id)->find((int) $disputeId) ?? abort(404, 'That dispute was not found.');
        $admin = $this->admin($request);

        return $this->act($payment, fn () => $this->payments->settleDispute($payment, $dispute, $data['outcome'], $data['note'], $admin['name']));
    }

    public function payoutLink(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255']]);
        $payment = $this->find($id);

        try {
            $url = $this->payments->payoutLink($payment->carrier_dot, $data['email'] ?? null, $payment->carrier_name);
        } catch (PaymentRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => 'DT Pay is not available right now.', 'detail' => $e->getMessage()], 503);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            report($e);

            return response()->json(['message' => 'Stripe could not create the link: '.$e->getMessage()], 502);
        }

        return response()->json(['url' => $url]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function act(Payment $payment, callable $action): JsonResponse
    {
        try {
            $action();
        } catch (PaymentRuleViolation $e) {
            return response()->json(['message' => $e->getMessage(), 'payment' => $this->detail($payment->refresh())], 422);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => 'DT Pay is not available right now.', 'detail' => $e->getMessage()], 503);
        }

        return response()->json(['payment' => $this->detail($payment->refresh())]);
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

        $company = Company::whereKey($r->company_id)->value('company_name');

        return response()->json([
            'request' => Presenter::request($r) + ['company' => $company, 'payment' => $this->row($r->payment->refresh(), $company)],
            // Kept for the admin panel build that only knew refund requests.
            'refund_request' => Presenter::request($r) + ['company' => $company, 'payment' => $this->row($r->payment, $company)],
        ]);
    }

    /** Everything about one payment, with what an admin may do next. */
    private function detail(Payment $p): array
    {
        $p->loadMissing(['events', 'disputes']);
        $payout = $this->freshPayout($p);
        $company = $p->company_id ? Company::whereKey($p->company_id)->value('company_name') : null;

        $detail = Presenter::detail($p, null, $payout);
        $detail['refund_request'] = ($r = $p->latestRequest(PaymentRequest::REFUND)) ? Presenter::request($r) : null;
        unset($detail['can']);

        return array_merge($detail, [
            'company' => $company,
            'payout' => ['ready' => (bool) $payout?->ready(), 'started' => (bool) $payout, 'details_submitted' => (bool) $payout?->details_submitted],
            'requests' => $p->requests()->get()->map(fn ($r) => Presenter::request($r) + ['decided_by_name' => $r->decided_by_name])->values(),
            'admin_can' => Presenter::adminCan($p, $payout),
        ]);
    }

    private function row(Payment $p, ?string $company = null): array
    {
        return Presenter::summary($p) + [
            'company' => $company,
            'fees' => Presenter::money($p->platform_fee_cents + $p->card_fee_cents),
            'method_type' => $p->method_type,
            'livemode' => $p->livemode,
        ];
    }

    private function freshPayout(Payment $payment): ?PayoutAccount
    {
        $account = $this->payments->payoutAccount($payment->carrier_dot);

        if ($account && ! $account->ready() && $payment->status === Payment::FUNDED) {
            try {
                $account = $this->payments->refreshPayoutAccount($account);
            } catch (Throwable) {
                // Stripe unreachable: show what we last knew.
            }
        }

        return $account;
    }

    private function find(string $id): Payment
    {
        return Payment::where('uuid', $id)->first() ?? abort(404, 'This payment was not found.');
    }

    private function findRequest(string $id): PaymentRequest
    {
        return PaymentRequest::where('uuid', $id)->first() ?? abort(404, 'This request was not found.');
    }

    /** @return array{name: string, email: string} */
    private function admin(Request $request): array
    {
        return $request->attributes->get('dtpay_admin');
    }

    private function safeLivemode(): ?bool
    {
        try {
            return $this->stripe->livemode();
        } catch (Throwable) {
            return null;
        }
    }
}
