<?php

namespace App\Http\Controllers\Api\V1\Coi;

use App\Http\Controllers\Controller;
use App\Services\Coi\CarrierInsuranceRequestService;
use App\Services\Coi\InboundEmailPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Where the mail provider posts a reply to the insurance inbox.
 *
 * Public by necessity — the provider carries no bearer token — so the shared
 * secret is the whole of the authorisation, and an unset secret closes the
 * endpoint rather than opening it.
 *
 * Almost everything here answers 200. A provider that does not get a prompt 200
 * redelivers, and redelivering a mail this could not match is not going to help
 * on the second attempt; the only thing it achieves is another Claude call if
 * the mail matched but something later threw. Failures are logged and swallowed
 * deliberately.
 */
class InboundEmailWebhookController extends Controller
{
    public function __construct(
        private readonly CarrierInsuranceRequestService $requests,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorised.'], 401);
        }

        $payload = $this->payloadFrom($request);

        // SNS opens with a handshake before any mail arrives.
        if (($payload['Type'] ?? null) === 'SubscriptionConfirmation') {
            return $this->confirmSnsSubscription($payload);
        }

        try {
            $response = $this->requests->recordReply(
                InboundEmailPayload::fromProviderPayload($payload)
            );
        } catch (\Throwable $e) {
            Log::error('Inbound COI reply could not be stored', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Logged.'], 200);
        }

        return response()->json([
            'status' => 'success',
            'message' => $response ? 'Reply recorded.' : 'No matching request.',
        ]);
    }

    /**
     * Header first, query string second — not every provider lets a custom
     * header be set on a delivery URL, and SNS lets neither, which is why the
     * secret can ride on the URL at all.
     */
    private function authorised(Request $request): bool
    {
        $expected = (string) config('coi_insurance.webhook.secret');

        if ($expected === '') {
            Log::warning('Inbound COI webhook called with no COI_INBOUND_SECRET configured.');

            return false;
        }

        $provided = (string) ($request->header('X-Inbound-Secret') ?? $request->query('secret', ''));

        return hash_equals($expected, $provided);
    }

    /**
     * SNS posts `text/plain` with a JSON body, so Laravel does not decode it.
     *
     * @return array<string, mixed>
     */
    private function payloadFrom(Request $request): array
    {
        $decoded = $request->all();

        if ($decoded !== []) {
            return $decoded;
        }

        $raw = json_decode($request->getContent(), true);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Fetching the SubscribeURL is what completes an SNS subscription.
     *
     * Restricted to the configured topic: fetching an arbitrary URL because a
     * stranger posted one is a request-forgery primitive, and confirming their
     * topic would let them feed this endpoint whatever they like afterwards.
     *
     * @param  array<string, mixed>  $payload
     */
    private function confirmSnsSubscription(array $payload): JsonResponse
    {
        $expectedArn = (string) config('coi_insurance.webhook.sns_topic_arn');
        $arn = (string) ($payload['TopicArn'] ?? '');
        $url = (string) ($payload['SubscribeURL'] ?? '');

        if ($expectedArn === '' || ! hash_equals($expectedArn, $arn)) {
            Log::warning('Ignoring SNS subscription confirmation for an unexpected topic', [
                'topic_arn' => $arn,
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        if (! str_starts_with($url, 'https://sns.') || ! str_contains($url, '.amazonaws.com/')) {
            Log::warning('Ignoring SNS subscription confirmation with an off-host SubscribeURL', [
                'url' => $url,
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        Http::timeout(10)->get($url);

        Log::info('Confirmed SNS subscription for the COI insurance inbox', ['topic_arn' => $arn]);

        return response()->json(['status' => 'confirmed'], 200);
    }
}
