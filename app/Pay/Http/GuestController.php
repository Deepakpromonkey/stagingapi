<?php

namespace App\Pay\Http;

use App\Http\Controllers\Controller;
use App\Pay\Fees;
use App\Pay\Models\Dispute;
use App\Pay\Models\Payment;
use App\Pay\PaymentRuleViolation;
use App\Pay\PaymentService;
use App\Pay\Stripe\Gateway;
use App\Pay\Stripe\PaymentsUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guest Pay: someone without a DollarTraq account pays a carrier. The
 * payment is reached only through its private tracking token, which the
 * guest keeps (the link is shown and emailed to them by Stripe's receipt).
 */
class GuestController extends Controller
{
    public function __construct(private PaymentService $payments, private Gateway $stripe)
    {
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'configured' => (bool) config('dtpay.stripe.secret') && (bool) $this->stripe->publishableKey(),
            'publishable_key' => $this->stripe->publishableKey(),
            'livemode' => $this->stripe->livemode(),
            'fees' => [
                'platform_pct' => (float) config('dtpay.fees.guest_platform_pct'),
                'card_pct' => (float) config('dtpay.fees.card_pct'),
            ],
            'limits' => config('dtpay.limits'),
        ]);
    }

    public function carrierSearch(Request $request): JsonResponse
    {
        return response()->json(['data' => BrokerController::searchCarriers((string) $request->query('q', ''))]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.config('dtpay.limits.min'), 'max:'.config('dtpay.limits.max')],
            'method_type' => ['required', Rule::in(['card', 'us_bank_account'])],
        ]);

        $q = Fees::quote(Fees::dollarsToCents($data['amount']), $data['method_type'], true);

        return response()->json([
            'amount' => Presenter::money($q['amount_cents']),
            'platform_fee' => Presenter::money($q['platform_fee_cents']) + ['pct' => $q['platform_fee_pct']],
            'card_fee' => Presenter::money($q['card_fee_cents']) + ['pct' => $q['card_fee_pct']],
            'total' => Presenter::money($q['total_cents']),
            'carrier_receives' => Presenter::money($q['amount_cents']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $limits = config('dtpay.limits');

        $data = $request->validate([
            'carrier_dot' => ['required', 'digits_between:1,10'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_mc' => ['nullable', 'string', 'max:32'],
            'amount' => ['required', 'numeric', "min:{$limits['min']}", "max:{$limits['max']}"],
            'load_number' => ['nullable', 'string', 'max:64'],
            'origin' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'pickup_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'payer_name' => ['required', 'string', 'max:255'],
            'payer_company' => ['nullable', 'string', 'max:255'],
            'payer_email' => ['required', 'email', 'max:255'],
            'payer_phone' => ['nullable', 'string', 'max:32'],
            'rate_confirmation' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        $payment = $this->payments->create($data + ['source' => Payment::SOURCE_GUEST], null, $request->file('rate_confirmation'));

        return response()->json([
            'token' => $payment->guest_token,
            'tracking_url' => config('dtpay.frontend_url').'/guest-pay/track/'.$payment->guest_token,
            'payment' => $this->present($payment),
        ], 201);
    }

    public function show(string $token): JsonResponse
    {
        return response()->json(['payment' => $this->present($this->payment($token))]);
    }

    public function intent(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['method_type' => ['required', Rule::in(['card', 'us_bank_account'])]]);
        $payment = $this->payment($token);

        return $this->guarded(function () use ($payment, $data) {
            $result = $this->payments->guestIntent($payment, $data['method_type']);

            return response()->json(['client_secret' => $result['client_secret'], 'payment' => $this->present($payment->fresh())]);
        });
    }

    /** After the Payment Element confirms in the browser: read the result back from Stripe. */
    public function sync(string $token): JsonResponse
    {
        $payment = $this->payment($token);

        return $this->guarded(fn () => response()->json(['payment' => $this->present($this->payments->sync($payment))]));
    }

    public function release(string $token): JsonResponse
    {
        $payment = $this->payment($token);

        return $this->guarded(fn () => response()->json(['payment' => $this->present($this->payments->release($payment, 'guest', null))]));
    }

    public function dispute(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(Dispute::REASONS))],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $payment = $this->payment($token);

        return $this->guarded(function () use ($request, $payment, $data) {
            $this->payments->openDispute($payment, $data['reason'], $data['details'], $request->file('evidence', []), 'guest', null);

            return response()->json(['payment' => $this->present($payment->fresh())], 201);
        });
    }

    private function payment(string $token): Payment
    {
        abort_unless(strlen($token) === 48, 404);

        return Payment::where('source', Payment::SOURCE_GUEST)->where('guest_token', $token)->firstOrFail();
    }

    private function present(Payment $payment): array
    {
        $payment->loadMissing(['events', 'disputes']);

        return Presenter::detail($payment, null, $this->payments->payoutAccount($payment->carrier_dot), true);
    }

    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PaymentRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PaymentsUnavailable $e) {
            return response()->json(['message' => 'Payments are not available right now.'], 503);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            report($e);

            return response()->json(['message' => 'Stripe could not complete this request. Please try again.'], 502);
        }
    }
}
