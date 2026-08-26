<?php

namespace App\Services\Coi;

use App\Jobs\ExtractInsuranceExpiry;
use App\Mail\CarrierInsuranceRequestMail;
use App\Models\Carriers\Carrier;
use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Raising a request, and matching the reply back to it.
 *
 * The two halves are here together because they share one non-obvious rule:
 * what identifies a request is the token in the Reply-To sub-address, not the
 * DOT and not the subject line. Both of those are things an agency's mail
 * client will happily rewrite, and two brokers chasing the same carrier would
 * otherwise be indistinguishable.
 */
class CarrierInsuranceRequestService
{
    public function __construct(
        private readonly CoiContactResolver $contacts,
    ) {}

    /**
     * Raise a request for a carrier, or hand back the one already in flight.
     *
     * Deliberately idempotent per company and DOT: the button sits on a profile
     * page any number of the broker's team may have open, and an agency that
     * receives the same request four times answers none of them.
     */
    public function raise(User $user, int $dotNumber, ?string $carrierName = null, ?string $carrierMc = null): CoiInsuranceRequest
    {
        $existing = CoiInsuranceRequest::where('company_id', $user->company_id)
            ->where('dot_number', $dotNumber)
            ->whereIn('status', [CoiInsuranceRequest::STATUS_PENDING, CoiInsuranceRequest::STATUS_RESPONDED])
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        // A request that already succeeded is not re-raised on every visit to
        // the profile — the answer is still on screen. The cooldown is what
        // makes "ask again" a deliberate act rather than a double click.
        $cooldownHours = (int) config('coi_insurance.resend_cooldown_hours', 24);

        $recent = CoiInsuranceRequest::where('company_id', $user->company_id)
            ->where('dot_number', $dotNumber)
            ->where('created_at', '>=', now()->subHours($cooldownHours))

            /*
             | A request that never left the building does not hold the
             | cooldown. The usual cause is a rejected recipient address, and
             | making the broker wait a day to retry a mail no agency ever
             | received would be a cooldown protecting nobody.
             */
            ->whereNotNull('sent_at')

            ->latest('id')
            ->first();

        if ($recent) {
            return $recent;
        }

        $identity = $this->carrierIdentity($dotNumber, $carrierName, $carrierMc);

        $contact = $this->contacts->resolve($dotNumber);

        $forced = config('coi_insurance.force_recipient');

        if ($contact === null && ! $forced) {
            throw new RuntimeException(
                'No insurance contact could be found for DOT '.$dotNumber
                .'. The certificate on file does not carry an agency email address.'
            );
        }

        /*
         | With a test recipient set the mail goes there, but the row still
         | records what the resolver found — otherwise a staging run would
         | report that every carrier resolves perfectly, which is the one
         | thing it is being run to check.
         */
        $recipient = $forced ?: $contact['email'];
        $source = $forced
            ? 'test:'.($contact['source'] ?? 'none')
            : $contact['source'];

        $request = CoiInsuranceRequest::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'dot_number' => $dotNumber,
            'carrier_name' => $identity['name'],
            'carrier_mc' => $identity['mc'],
            'recipient_email' => $recipient,
            'recipient_source' => $source,
            'status' => CoiInsuranceRequest::STATUS_PENDING,
            'subject' => CoiInsuranceRequest::buildSubject($identity['name'], $dotNumber),
        ]);

        $this->send($request);

        return $request;
    }

    /**
     * Hand the mail to the queue.
     *
     * A send failure marks the row failed rather than bubbling: the broker
     * clicked a button, and the outcome they need to see is on the card, not in
     * a 500. The message itself is on the row for whoever looks into it.
     */
    public function send(CoiInsuranceRequest $request): void
    {
        try {
            Mail::to($request->recipient_email)
                ->queue(
                    (new CarrierInsuranceRequestMail($request))
                        ->onConnection(config('coi_insurance.connection'))
                        ->onQueue(config('coi_insurance.queue'))
                );

            $request->forceFill([
                'sent_at' => now(),
                'message_id' => $request->uuid.'@'.config('coi_insurance.inbox.domain'),
                'last_error' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('COI insurance request failed to send', [
                'request_uuid' => $request->uuid,
                'dot_number' => $request->dot_number,
                'error' => $e->getMessage(),
            ]);

            $request->forceFill([
                'status' => CoiInsuranceRequest::STATUS_FAILED,
                'last_error' => $e->getMessage(),
                'resolved_at' => now(),
            ])->save();
        }
    }

    /**
     * Store an inbound reply against the request it answers, and queue the
     * extraction.
     *
     * Returns null when nothing matched — a stranger mailing the inbox, or a
     * bounce. That is not an error: the webhook still has to answer 200, or the
     * provider retries the same unmatched mail for a day.
     */
    public function recordReply(InboundEmailPayload $payload): ?CoiInsuranceResponse
    {
        $request = $this->matchRequest($payload);

        if ($request === null) {
            Log::info('Inbound COI reply matched no request', [
                'from' => $payload->fromEmail,
                'to' => $payload->recipients,
                'subject' => $payload->subject,
            ]);

            return null;
        }

        return DB::transaction(function () use ($request, $payload) {
            $response = $request->responses()->create([
                'from_email' => $payload->fromEmail,
                'from_name' => $payload->fromName,
                'subject' => $payload->subject,
                'body_text' => $payload->text,
                'body_html' => $payload->html,
                'raw_payload' => $payload->raw,
                'received_at' => $payload->receivedAt ?? now(),
            ]);

            /*
             | A reply on a request that already succeeded is stored but does
             | not reopen it — an agency's "thanks, let us know if you need
             | anything else" would otherwise knock a good answer back to
             | pending and spend another extraction call on nothing.
             */
            if ($request->isOpen()) {
                $request->forceFill([
                    'status' => CoiInsuranceRequest::STATUS_RESPONDED,
                    'responded_at' => $request->responded_at ?? now(),
                ])->save();

                ExtractInsuranceExpiry::dispatch($response->id);
            }

            return $response;
        });
    }

    /**
     * Three ways in, tried strongest first.
     *
     * The token is the only one that is genuinely unambiguous. The DOT in the
     * sub-address covers a client that rewrote the token but kept the plus
     * part, and the subject line covers an agency that composed a fresh mail
     * to the inbox rather than replying. The last two can only ever resolve to
     * an open request, so a stale row cannot be reopened by a coincidence.
     */
    private function matchRequest(InboundEmailPayload $payload): ?CoiInsuranceRequest
    {
        foreach ($payload->recipients as $recipient) {
            $parts = $this->parseSubAddress($recipient);

            if ($parts === null) {
                continue;
            }

            if ($parts['token'] !== null) {
                $match = CoiInsuranceRequest::where('reply_token', $parts['token'])->first();

                if ($match) {
                    return $match;
                }
            }

            if ($parts['dot'] !== null) {
                $match = $this->openRequestForDot($parts['dot']);

                if ($match) {
                    return $match;
                }
            }
        }

        return $this->matchBySubject($payload->subject);
    }

    /**
     * @return array{dot: ?int, token: ?string}|null
     */
    private function parseSubAddress(string $recipient): ?array
    {
        [$local] = array_pad(explode('@', $recipient, 2), 2, '');

        if (! str_contains($local, '+')) {
            return null;
        }

        [, $tag] = explode('+', $local, 2);

        // `{dot}-{token}`, but tolerate a client that kept only one half.
        if (preg_match('/^(\d+)-([a-z0-9]+)$/i', $tag, $match)) {
            return ['dot' => (int) $match[1], 'token' => strtolower($match[2])];
        }

        if (preg_match('/^\d+$/', $tag)) {
            return ['dot' => (int) $tag, 'token' => null];
        }

        return ['dot' => null, 'token' => strtolower($tag)];
    }

    private function matchBySubject(?string $subject): ?CoiInsuranceRequest
    {
        if ($subject === null || ! preg_match('/\b(\d{5,9})\b/', $subject, $match)) {
            return null;
        }

        // Only when the subject is recognisably one of ours. A mail that
        // happens to contain a seven-digit number is not a reply.
        if (! str_contains(strtolower($subject), 'insurance details of the carrier')) {
            return null;
        }

        return $this->openRequestForDot((int) $match[1]);
    }

    private function openRequestForDot(int $dotNumber): ?CoiInsuranceRequest
    {
        return CoiInsuranceRequest::where('dot_number', $dotNumber)
            ->whereIn('status', [CoiInsuranceRequest::STATUS_PENDING, CoiInsuranceRequest::STATUS_RESPONDED])
            ->latest('id')
            ->first();
    }

    /**
     * The name and MC that go into the mail.
     *
     * Read from the carrier database rather than trusted from the client: the
     * mail is addressed to a third party and quotes the carrier's identity
     * back at them, so it should say what FMCSA says. What the front end sent
     * is the fallback for a DOT with no census row.
     *
     * @return array{name: ?string, mc: ?string}
     */
    private function carrierIdentity(int $dotNumber, ?string $carrierName, ?string $carrierMc): array
    {
        $carrier = Carrier::where('dot_number', $dotNumber)
            ->with('authority')
            ->first();

        $name = $carrier?->legal_name ?: $carrier?->dba_name ?: $carrierName;
        $mc = $carrier?->authority?->docket_number ?: $carrierMc;

        return [
            'name' => $name ? trim($name) : null,
            'mc' => $mc ? trim($mc) : null,
        ];
    }
}
