<?php

namespace App\Jobs;

use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Services\Coi\CarrierInsuranceRequestService;
use App\Services\Coi\CoiFilingVerifier;
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

    public function handle(InsuranceExpiryExtractor $extractor, CarrierInsuranceRequestService $service, CoiFilingVerifier $verifier): void
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
            'extracted' => $result['details'] ?? null,
        ])->save();

        /*
         | A reply that carries no date is a real answer, but not the end of
         | the conversation: the agency needs the insured's authorization
         | first, the renewal has not been bound yet, or it asked who the
         | holder is. The certificate arrives in the next mail, and that mail
         | is only read if the request is still open — so it goes to awaiting,
         | not failed, and keeps no resolved_at.
         */
        if ($result['expiry_date']) {
            /*
             | A date is not a clearance. The same certificate has to agree
             | with what FMCSA shows before a broker can rely on it, and the
             | two disagree often enough — filing lag, a pending cancellation
             | already rescinded — that the comparison is recorded alongside
             | the date rather than left to whoever opens the card.
             */
            $request->forceFill([
                'status' => CoiInsuranceRequest::STATUS_SUCCESS,
                'insurance_expiry_date' => $result['expiry_date'],
                'verification' => $verifier->verify($request, $result['details'] ?? []),
                'coverage' => $this->coverageFrom($result['details'] ?? []),
                'verified_at' => now(),
                'resolved_at' => now(),
                'last_error' => null,
            ])->save();

            return;
        }

        /*
         | Before settling into awaiting: some dateless replies exist only to
         | name a better address. Re-routing sends the request on and puts it
         | back to pending, so waiting on this agency would be waiting on the
         | wrong one.
         */
        if ($service->rerouteIfAsked($request, $result['details'] ?? [])) {
            return;
        }

        $request->forceFill([
            'status' => CoiInsuranceRequest::STATUS_AWAITING,
            'last_error' => 'The reply did not state an insurance expiry date.',
        ])->save();
    }

    /**
     * The terms a dispatcher asks about, lifted out of the reading.
     *
     * Only the parts a tender turns on. The rest of the reading stays on the
     * reply, where it belongs.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function coverageFrom(array $details): array
    {
        return [
            'coverages' => $details['coverages'] ?? [],
            'exclusions' => $details['exclusions'] ?? [],
            'sub_limits' => $details['sub_limits'] ?? [],
            'scheduled_vins' => $details['scheduled_vins'] ?? [],
            'read_at' => now()->toIso8601String(),
        ];
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
