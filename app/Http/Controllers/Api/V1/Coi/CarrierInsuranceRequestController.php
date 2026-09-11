<?php

namespace App\Http\Controllers\Api\V1\Coi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coi\RaiseInsuranceRequest;
use App\Http\Resources\CoiInsuranceRequestResource;
use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Services\Coi\CarrierInsuranceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The broker side of "chase this carrier's agent for a current COI".
 *
 * Everything here is scoped to the caller's company, the same way the shortlist
 * and the connect requests are: a colleague who opens the same carrier profile
 * sees the request someone else raised, and nobody sees another broker's.
 */
class CarrierInsuranceRequestController extends Controller
{
    public function __construct(
        private readonly \App\Services\Coi\CoiCoverageCheck $coverage,
        private readonly CarrierInsuranceRequestService $requests,
    ) {}

    /**
     * The open or most recent request for one DOT — what the insurance card
     * asks for when it renders.
     */
    public function show(Request $request, int $dot): JsonResponse
    {
        $insuranceRequest = CoiInsuranceRequest::where('company_id', $request->user()->company_id)
            ->where('dot_number', $dot)
            ->with('latestResponse')
            ->latest('id')
            ->first();

        return response()->json([
            'status' => 'success',
            'message' => $insuranceRequest
                ? 'Insurance request retrieved.'
                : 'No insurance request has been raised for this carrier.',
            'data' => $insuranceRequest
                ? new CoiInsuranceRequestResource($insuranceRequest)
                : null,
        ]);
    }

    /**
     * Every request the company has raised. Ordered newest first, because the
     * only reason to open this list is to see what is still outstanding.
     */
    public function index(Request $request): JsonResponse
    {
        $requests = CoiInsuranceRequest::where('company_id', $request->user()->company_id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->with('latestResponse')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'status' => 'success',
            'message' => 'Insurance requests retrieved.',
            'data' => CoiInsuranceRequestResource::collection($requests->items()),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Raise one. Idempotent per company and DOT — see the service.
     */
    public function store(RaiseInsuranceRequest $request): JsonResponse
    {
        try {
            $insuranceRequest = $this->requests->raise(
                $request->user(),
                $request->integer('dot_number'),
                $request->input('carrier_name'),
                $request->input('carrier_mc'),
                [
                    'asks' => $request->input('asks'),
                    'holder_name' => $request->input('holder_name'),
                    'ask_note' => $request->input('ask_note'),
                ],
            );
        } catch (RuntimeException $e) {
            /*
             | 422 rather than 500: the usual cause is that the certificate on
             | file carries no agency address, which is a fact about the data
             | and something the broker can act on by uploading a better COI.
             */
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        $insuranceRequest->load('latestResponse');

        return response()->json([
            'status' => 'success',
            'message' => 'Insurance details have been requested from the carrier\'s agency.',
            'data' => new CoiInsuranceRequestResource($insuranceRequest),
        ], 201);
    }

    /**
     * The reply itself — what the "view response" link opens.
     *
     * Scoped through the request's company, so the uuid alone is not enough to
     * read another broker's correspondence.
     */
    public function response(Request $request, string $uuid): JsonResponse
    {
        $response = CoiInsuranceResponse::where('uuid', $uuid)
            ->whereHas('request', fn ($query) => $query->where('company_id', $request->user()->company_id))
            ->with('request')
            ->first();

        if ($response === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Response not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Response retrieved.',
            'data' => [
                'uuid' => $response->uuid,
                'from_email' => $response->from_email,
                'from_name' => $response->from_name,
                'subject' => $response->subject,
                'received_at' => $response->received_at?->toIso8601String(),

                // The body as it arrived. The card renders the text; the HTML
                // is there for a reader that wants the original formatting.
                'body_text' => $response->body_text,
                'body_html' => $response->body_html,

                'extracted_expiry_date' => $response->extracted_expiry_date?->toDateString(),

                // Kept visible on purpose: a broker acting on an extracted date
                // should be able to see exactly what was extracted, and from
                // what, without asking anyone.
                'llm_response' => $response->llm_response,

                'request' => new CoiInsuranceRequestResource($response->request),
            ],
        ]);
    }

    /**
     * The whole correspondence for one request — what the Track button opens.
     *
     * The card polls `show` on a timer, so the reply bodies deliberately do
     * not travel with it; they are fetched here, once, when a broker actually
     * opens the thread.
     *
     * Scoped through the company like `response`, so the uuid alone is not
     * enough to read another broker's correspondence.
     */
    public function thread(Request $request, string $uuid): JsonResponse
    {
        $insuranceRequest = CoiInsuranceRequest::where('uuid', $uuid)
            ->where('company_id', $request->user()->company_id)
            ->with(['user', 'responses' => fn ($query) => $query->oldest('received_at')])
            ->first();

        if ($insuranceRequest === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insurance request not found.',
            ], 404);
        }

        /*
        | The outbound mail opens the thread. Its body is not stored — it is
        | built from a template at send time — so the subject and the address
        | it went to are what there is to show, and they are the two things a
        | broker checks when an agency says it never received anything.
        */
        $messages = [[
            'direction' => 'outbound',
            'uuid' => null,
            'from_name' => trim(
                ($insuranceRequest->user?->first_name ?? '')
                .' '.($insuranceRequest->user?->last_name ?? '')
            ) ?: null,
            'from_email' => $insuranceRequest->replyToAddress(),
            'to_email' => $insuranceRequest->recipient_email,
            'subject' => $insuranceRequest->subject,
            'at' => $insuranceRequest->sent_at?->toIso8601String(),
            'body_text' => null,
            'extracted_expiry_date' => null,
            'llm_response' => null,

            /*
            | What this request actually asked for. The mail body is built from
            | a template at send time and never stored, and since the broker
            | chooses the questions there is no longer a single "standard
            | request" to describe — so the questions themselves travel instead.
            */
            'asks' => array_values(array_map(
                fn ($ask) => CoiInsuranceRequest::ASKS[$ask] ?? $ask,
                $insuranceRequest->asks ?? [],
            )),
            'ask_note' => $insuranceRequest->ask_note,
            'holder_name' => $insuranceRequest->holder_name,
        ]];

        foreach ($insuranceRequest->responses as $reply) {
            $messages[] = [
                'direction' => 'inbound',
                'uuid' => $reply->uuid,
                'from_name' => $reply->from_name,
                'from_email' => $reply->from_email,
                'to_email' => $insuranceRequest->replyToAddress(),
                'subject' => $reply->subject,
                'at' => $reply->received_at?->toIso8601String(),

                // The text as it arrived; the card falls back to stripping the
                // HTML when an agency sends no plain-text part.
                'body_text' => $reply->body_text
                    ?: ($reply->body_html ? strip_tags($reply->body_html) : null),

                'extracted_expiry_date' => $reply->extracted_expiry_date?->toDateString(),

                // The rest of what the model read out of this reply: limits,
                // exclusions, commodity sub-limits, who it was made out to.
                'extracted' => $reply->extracted,

                // Shown on purpose: a broker acting on an extracted date should
                // be able to see what was extracted, and from what.
                'llm_response' => $reply->llm_response,
            ];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Insurance request thread retrieved.',
            'data' => [
                'request' => new CoiInsuranceRequestResource($insuranceRequest),
                'messages' => $messages,
                'state_path' => $insuranceRequest->statePath(),
            ],
        ]);
    }

    /**
     * Can this carrier take this load — asked once, about one load.
     *
     * Deliberately separate from the carrier's verification status. A seafood
     * load over a commodity sub-limit is a bad load for this carrier today,
     * not a bad carrier, and answering it by touching the profile would hold
     * every other load they are perfectly insured for.
     */
    public function coverageCheck(Request $request, int $dot): JsonResponse
    {
        $validated = $request->validate([
            'vin' => ['nullable', 'string', 'max:32'],
            'commodity' => ['nullable', 'string', 'max:120'],
            'value' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (empty($validated['vin']) && empty($validated['commodity'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Give a vin, a commodity, or both.',
            ], 422);
        }

        $companyId = $request->user()->company_id;
        $checks = [];

        if (! empty($validated['vin'])) {
            $checks['unit'] = $this->coverage->unitScheduled($companyId, $dot, $validated['vin']);
        }

        if (! empty($validated['commodity'])) {
            $checks['commodity'] = $this->coverage->commodityCovered(
                $companyId,
                $dot,
                $validated['commodity'],
                (float) ($validated['value'] ?? 0),
            );
        }

        /*
        | One answer for the dispatcher on top of the detail. A load is held if
        | any single check says so — the unit not being on the policy and the
        | commodity being over its sub-limit are both reasons on their own.
        */
        $blocking = ['not_scheduled', 'under_insured'];
        $held = (bool) array_intersect(
            array_column($checks, 'verdict'),
            $blocking,
        );

        return response()->json([
            'status' => 'success',
            'message' => $held ? 'This load should be held.' : 'Nothing found against this load.',
            'data' => [
                'hold' => $held,
                'checks' => $checks,
            ],
        ]);
    }
}
