<?php

namespace App\Jobs;

use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Services\Coi\InboundEmailPayload;
use App\Services\Coi\InsuranceExpiryExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read the expiry date out of one stored reply and resolve its request.
 *
 * Queued rather than run inside the webhook: the provider retries a delivery it
 * did not get a prompt 200 for, and a retried delivery would mean a second
 * Claude call for the same mail.
 */
class ExtractInsuranceExpiry implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * The failures worth retrying are a rate limit or an overload, and both
     * clear on their own given a little room.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $responseId)
    {
        // Pinned rather than inherited from QUEUE_CONNECTION — see the note in
        // config/coi_insurance.php.
        $this->onConnection(config('coi_insurance.connection', 'database'));
        $this->onQueue(config('coi_insurance.queue', 'default'));
    }

    public function handle(InsuranceExpiryExtractor $extractor): void
    {
        $response = CoiInsuranceResponse::with('request')->find($this->responseId);

        if ($response === null || $response->request === null) {
            return;
        }

        $request = $response->request;

        // Another reply on the same request may have resolved it while this
        // was queued. Nothing to do, and no reason to spend the call.
        if (! $request->isOpen()) {
            return;
        }

        $payload = InboundEmailPayload::fromProviderPayload($response->raw_payload ?? []);

        $body = trim($payload->bodyForExtraction());

        if ($body === '') {
            // Falls back to whatever was stored directly, which is what the
            // non-webhook paths (a replayed row, a manually inserted reply)
            // will have.
            $body = trim((string) ($response->body_text ?: strip_tags((string) $response->body_html)));
        }

        $result = $extractor->extract($body);

        $response->forceFill([
            'llm_response' => $result['raw'],
            'extracted_expiry_date' => $result['expiry_date'],
        ])->save();

        /*
         | A reply that carries no date is a real answer, not a failure to
         | process — the agency said the policy is gone, or wrote back asking
         | who we are. It resolves the request as failed so the card stops
         | saying "pending", and the mail itself is one click away.
         */
        $request->forceFill([
            'status' => $result['expiry_date']
                ? CoiInsuranceRequest::STATUS_SUCCESS
                : CoiInsuranceRequest::STATUS_FAILED,
            'insurance_expiry_date' => $result['expiry_date'],
            'resolved_at' => now(),
            'last_error' => $result['expiry_date'] ? null : 'The reply did not state an insurance expiry date.',
        ])->save();
    }

    /**
     * One extraction per stored reply. A provider that redelivers, or a retry
     * that overlaps its own predecessor, must not call Claude twice.
     */
    public function uniqueId(): string
    {
        return 'coi-extract-'.$this->responseId;
    }

    /**
     * Out of attempts. The reply is still on the row and still readable by a
     * human, so the request is failed rather than left pending forever.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('COI insurance expiry extraction failed', [
            'response_id' => $this->responseId,
            'error' => $e->getMessage(),
        ]);

        $response = CoiInsuranceResponse::with('request')->find($this->responseId);

        $response?->request?->forceFill([
            'status' => CoiInsuranceRequest::STATUS_FAILED,
            'resolved_at' => now(),
            'last_error' => 'Could not read an expiry date from the reply: '.$e->getMessage(),
        ])->save();
    }
}
