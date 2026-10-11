<?php

namespace App\Pay\Http;

use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Models\Shipment;
use App\Pay\Fees;
use App\Pay\Models\Dispute;
use App\Pay\Models\Payment;
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
 * DT Pay for signed-in brokers. Every payment is read through payment(),
 * which scopes it to the caller's company - nobody can see or act on
 * another company's money.
 */
class BrokerController extends Controller
{
    public function __construct(private PaymentService $payments, private Gateway $stripe)
    {
    }

    /** Publishable key, mode and fee schedule for the frontend. */
    public function config(): JsonResponse
    {
        return response()->json([
            'configured' => (bool) config('dtpay.stripe.secret') && (bool) $this->stripe->publishableKey(),
            'publishable_key' => $this->stripe->publishableKey(),
            'livemode' => $this->stripe->livemode(),
            'fees' => [
                'platform_pct' => (float) config('dtpay.fees.platform_pct'),
                'card_pct' => (float) config('dtpay.fees.card_pct'),
            ],
            'limits' => config('dtpay.limits'),
        ]);
    }

    /** Dashboard figures for the caller's company. */
    public function summary(Request $request): JsonResponse
    {
        $company = $request->user()->company_id;
        $base = fn () => Payment::where('company_id', $company);

        $sum = fn (array $statuses, string $col = 'amount_cents') => (int) $base()->whereIn('status', $statuses)->sum($col);
        $count = fn (array $statuses) => $base()->whereIn('status', $statuses)->count();

        $monthStart = now()->startOfMonth();

        return response()->json([
            'held' => Presenter::money($sum([Payment::FUNDED])) + ['count' => $count([Payment::FUNDED])],
            'processing' => Presenter::money($sum([Payment::PROCESSING])) + ['count' => $count([Payment::PROCESSING])],
            'awaiting_payment' => ['count' => $count([Payment::DRAFT, Payment::FAILED])],
            'released_this_month' => Presenter::money((int) $base()->where('status', Payment::RELEASED)->where('released_at', '>=', $monthStart)->sum('amount_cents'))
                + ['count' => $base()->where('status', Payment::RELEASED)->where('released_at', '>=', $monthStart)->count()],
            'released_total' => Presenter::money($sum([Payment::RELEASED])),
            'refunded' => Presenter::money($sum([Payment::REFUNDED])) + ['count' => $count([Payment::REFUNDED])],
            'open_disputes' => Dispute::where('status', Dispute::OPEN)
                ->whereIn('payment_id', $base()->select('id'))->count(),
            'fees_paid' => Presenter::money((int) $base()->whereIn('status', [Payment::FUNDED, Payment::RELEASED])->sum(DB::raw('platform_fee_cents + card_fee_cents'))),
            'recent' => $base()->latest('id')->limit(6)->get()->map(fn ($p) => Presenter::summary($p))->values(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Funding methods
    |--------------------------------------------------------------------------
    */

    public function methods(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $customer = $this->payments->customerFor($request->user()->company, $request->user());

            return response()->json(['methods' => $this->stripe->listPaymentMethods($customer)]);
        });
    }

    public function setupIntent(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['card', 'us_bank_account'])]]);

        return $this->guarded(function () use ($request, $data) {
            $customer = $this->payments->customerFor($request->user()->company, $request->user());

            return response()->json(['client_secret' => $this->stripe->createSetupIntent($customer, [$data['type']])]);
        });
    }

    public function removeMethod(Request $request, string $methodId): JsonResponse
    {
        return $this->guarded(function () use ($request, $methodId) {
            $customer = $this->payments->customerFor($request->user()->company, $request->user());
            $method = $this->stripe->retrievePaymentMethod($methodId);

            abort_unless(($method['customer'] ?? null) === $customer, 404);

            $this->stripe->detachPaymentMethod($methodId);

            return response()->json(['removed' => true]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing what to pay for
    |--------------------------------------------------------------------------
    */

    /** The company's loads that have a carrier and are not already paid. */
    public function shipments(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $taken = Payment::where('company_id', $request->user()->company_id)
            ->whereNotNull('shipment_id')
            ->whereNotIn('status', [Payment::CANCELLED, Payment::FAILED, Payment::REFUNDED])
            ->pluck('shipment_id');

        $shipments = Shipment::where('company_id', $request->user()->company_id)
            ->whereNotNull('carrier_dot')->where('carrier_dot', '!=', '')
            ->whereNotIn('id', $taken)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('shipment_no', 'like', "%{$q}%")
                ->orWhere('pro_number', 'like', "%{$q}%")
                ->orWhere('carrier_name', 'like', "%{$q}%")
                ->orWhere('carrier_dot', $q)))
            ->latest('id')
            ->paginate(min(50, (int) $request->query('per_page', 20)));

        return response()->json([
            'data' => collect($shipments->items())->map(fn (Shipment $s) => [
                'id' => $s->id,
                'shipment_no' => $s->shipment_no,
                'pro_number' => $s->pro_number,
                'status' => $s->status,
                'carrier' => ['dot' => $s->carrier_dot, 'name' => $s->carrier_name, 'mc' => $s->carrier_mc],
                'origin' => $s->origin,
                'destination' => $s->destination,
                'pickup_date' => $s->pickup_date,
                'delivery_date' => $s->delivery_date,
            ])->values(),
            'meta' => ['current_page' => $shipments->currentPage(), 'last_page' => $shipments->lastPage(), 'total' => $shipments->total()],
        ]);
    }

    /** Carriers by DOT, or by the start of the legal name. */
    public function carrierSearch(Request $request): JsonResponse
    {
        return response()->json(['data' => self::searchCarriers((string) $request->query('q', ''))]);
    }

    public static function searchCarriers(string $q): array
    {
        $q = trim($q);

        if (mb_strlen($q) < 2) {
            return [];
        }

        try {
            $query = Carrier::query()->with('authority');

            ctype_digit($q)
                ? $query->where('dot_number', (int) $q)
                : $query->where('legal_name', 'like', $q.'%');

            return $query->limit(10)->get(['id', 'dot_number', 'legal_name', 'dba_name', 'phy_city', 'phy_state'])
                ->map(fn ($c) => [
                    'dot' => (string) $c->dot_number,
                    'name' => $c->legal_name,
                    'dba' => $c->dba_name,
                    'mc' => $c->authority?->docket_number,
                    'location' => trim(($c->phy_city ? $c->phy_city.', ' : '').($c->phy_state ?? '')),
                ])->values()->all();
        } catch (Throwable) {
            return [];   // carrier database unreachable: manual entry still works
        }
    }

    /** Can this carrier be paid out yet? */
    public function carrierPayout(Request $request, string $dot): JsonResponse
    {
        return $this->guarded(function () use ($dot) {
            $account = $this->payments->payoutAccount($dot);

            if ($account && ! $account->ready()) {
                $account = $this->payments->refreshPayoutAccount($account);
            }

            return response()->json([
                'started' => (bool) $account,
                'ready' => (bool) $account?->ready(),
                'details_submitted' => (bool) $account?->details_submitted,
            ]);
        });
    }

    /** A Stripe-hosted link to send the carrier so they can receive payouts. */
    public function carrierPayoutLink(Request $request, string $dot): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->guarded(fn () => response()->json([
            'url' => $this->payments->payoutLink($dot, $data['email'] ?? null, $data['name'] ?? null),
        ]));
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.config('dtpay.limits.min'), 'max:'.config('dtpay.limits.max')],
            'method_type' => ['required', Rule::in(['card', 'us_bank_account'])],
        ]);

        $q = Fees::quote(Fees::dollarsToCents($data['amount']), $data['method_type']);

        return response()->json([
            'amount' => Presenter::money($q['amount_cents']),
            'platform_fee' => Presenter::money($q['platform_fee_cents']) + ['pct' => $q['platform_fee_pct']],
            'card_fee' => Presenter::money($q['card_fee_cents']) + ['pct' => $q['card_fee_pct']],
            'total' => Presenter::money($q['total_cents']),
            'carrier_receives' => Presenter::money($q['amount_cents']),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $page = Payment::where('company_id', $request->user()->company_id)
            ->when($status === 'held', fn ($w) => $w->where('status', Payment::FUNDED))
            ->when($status === 'disputed', fn ($w) => $w->whereIn('id', Dispute::where('status', Dispute::OPEN)->select('payment_id')))
            ->when($status && ! in_array($status, ['held', 'disputed'], true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('reference', 'like', "%{$q}%")
                ->orWhere('load_number', 'like', "%{$q}%")
                ->orWhere('carrier_name', 'like', "%{$q}%")
                ->orWhere('carrier_dot', $q)))
            ->when($request->query('from'), fn ($w, $from) => $w->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($w, $to) => $w->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate(min(50, (int) $request->query('per_page', 20)));

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => Presenter::summary($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $limits = config('dtpay.limits');

        $data = $request->validate([
            'source' => ['required', Rule::in([Payment::SOURCE_SHIPMENT, Payment::SOURCE_MANUAL])],
            'shipment_id' => ['required_if:source,shipment', 'nullable', 'integer'],
            'amount' => ['required', 'numeric', "min:{$limits['min']}", "max:{$limits['max']}"],
            'carrier_dot' => ['required_if:source,manual', 'nullable', 'digits_between:1,10'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_mc' => ['nullable', 'string', 'max:32'],
            'load_number' => ['nullable', 'string', 'max:64'],
            'origin' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'pickup_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'rate_confirmation' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        if ($data['source'] === Payment::SOURCE_SHIPMENT) {
            $shipment = Shipment::where('company_id', $user->company_id)->findOrFail($data['shipment_id']);

            abort_unless($shipment->carrier_dot, 422, 'That load has no carrier assigned.');

            $data = array_merge($data, [
                'carrier_dot' => $shipment->carrier_dot,
                'carrier_name' => $data['carrier_name'] ?? $shipment->carrier_name,
                'carrier_mc' => $data['carrier_mc'] ?? $shipment->carrier_mc,
                'load_number' => $data['load_number'] ?? ($shipment->pro_number ?: $shipment->shipment_no),
                'origin' => $data['origin'] ?? $shipment->origin,
                'destination' => $data['destination'] ?? $shipment->destination,
                'pickup_date' => $data['pickup_date'] ?? $shipment->pickup_date,
                'delivery_date' => $data['delivery_date'] ?? $shipment->delivery_date,
            ]);
        }

        $payment = $this->payments->create($data, $user, $request->file('rate_confirmation'));

        return response()->json(['payment' => $this->present($payment, $request)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['payment' => $this->present($this->payment($request, $id), $request)]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['payment_method_id' => ['required', 'string', 'max:64']]);
        $payment = $this->payment($request, $id);

        return $this->guarded(function () use ($request, $payment, $data) {
            $result = $this->payments->pay($payment, $request->user(), $data['payment_method_id'], $request->ip(), $request->userAgent());

            return response()->json([
                'payment' => $this->present($result['payment'], $request),
                'client_secret' => $result['client_secret'],
                'error' => $result['error'],
            ]);
        }, 'create-fund-payment', $request);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $payment = $this->payment($request, $id);

        return $this->guarded(fn () => response()->json(['payment' => $this->present($this->payments->sync($payment), $request)]));
    }

    public function release(Request $request, string $id): JsonResponse
    {
        $payment = $this->payment($request, $id);

        abort_unless(Permissions::canRelease($request->user(), $payment), 403, 'You are not allowed to release a payment of this amount.');

        return $this->guarded(fn () => response()->json([
            'payment' => $this->present($this->payments->release($payment, 'broker', $request->user()->id), $request),
        ]));
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $payment = $this->payment($request, $id);

        return $this->guarded(fn () => response()->json([
            'payment' => $this->present($this->payments->refund($payment, 'broker', $request->user()->id, $data['reason'] ?? null), $request),
        ]), 'refund-cancel-payment', $request);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $payment = $this->payment($request, $id);

        return $this->guarded(fn () => response()->json([
            'payment' => $this->present($this->payments->cancel($payment, 'broker', $request->user()->id), $request),
        ]), 'refund-cancel-payment', $request);
    }

    public function hold(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['hold' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $payment = $this->payment($request, $id);

        return $this->guarded(fn () => response()->json([
            'payment' => $this->present($this->payments->setHold($payment, (bool) $data['hold'], 'broker', $request->user()->id, $data['note'] ?? null), $request),
        ]), 'create-fund-payment', $request);
    }

    public function pod(Request $request, string $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $payment = $this->payment($request, $id);

        return $this->guarded(fn () => response()->json([
            'payment' => $this->present($this->payments->attachPod($payment, $request->file('file'), 'broker', $request->user()->id), $request),
        ]), 'create-fund-payment', $request);
    }

    public function dispute(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(Dispute::REASONS))],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $payment = $this->payment($request, $id);

        return $this->guarded(function () use ($request, $payment, $data) {
            $this->payments->openDispute($payment, $data['reason'], $data['details'], $request->file('evidence', []), 'broker', $request->user()->id);

            return response()->json(['payment' => $this->present($payment->fresh(), $request)], 201);
        }, 'create-fund-payment', $request);
    }

    public function resolveDispute(Request $request, string $id, int $disputeId): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'min:3', 'max:2000']]);
        $payment = $this->payment($request, $id);
        $dispute = Dispute::where('payment_id', $payment->id)->findOrFail($disputeId);

        return $this->guarded(function () use ($request, $payment, $dispute, $data) {
            $this->payments->resolveDispute($payment, $dispute, $data['resolution'], 'broker', $request->user()->id);

            return response()->json(['payment' => $this->present($payment->fresh(), $request)]);
        }, 'create-fund-payment', $request);
    }

    /** Download the rate confirmation, the POD, or a dispute's evidence file. */
    public function file(Request $request, string $id, string $kind)
    {
        $payment = $this->payment($request, $id);

        $path = match ($kind) {
            'rate-confirmation' => $payment->rate_confirmation_path,
            'pod' => $payment->pod_path,
            default => null,
        };

        if ($kind === 'evidence') {
            $dispute = Dispute::where('payment_id', $payment->id)->findOrFail((int) $request->query('dispute'));
            $path = ($dispute->evidence_paths ?? [])[(int) $request->query('index', 0)] ?? null;
        }

        abort_unless($path && Storage::disk(config('dtpay.disk'))->exists($path), 404);

        return Storage::disk(config('dtpay.disk'))->download($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** A payment of the caller's company, or 404. */
    private function payment(Request $request, string $id): Payment
    {
        return Payment::where('company_id', $request->user()->company_id)->where('uuid', $id)->firstOrFail();
    }

    private function present(Payment $payment, Request $request): array
    {
        $payment->loadMissing(['events', 'disputes']);

        return Presenter::detail($payment, $request->user(), $this->payments->payoutAccount($payment->carrier_dot));
    }

    /** Runs an action, turning rule breaks and Stripe trouble into clean JSON. */
    private function guarded(callable $action, ?string $permission = null, ?Request $request = null): JsonResponse
    {
        if ($permission && ! Permissions::can($request?->user(), $permission)) {
            return response()->json(['message' => 'You do not have permission to do this.'], 403);
        }

        try {
            return $action();
        } catch (PaymentRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => 'DT Pay is not available right now.', 'detail' => $e->getMessage()], 503);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            report($e);

            return response()->json(['message' => 'Stripe could not complete this request. Please try again.'], 502);
        }
    }
}
