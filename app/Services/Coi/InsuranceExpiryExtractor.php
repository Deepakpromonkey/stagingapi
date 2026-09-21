<?php

namespace App\Services\Coi;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Reads the policy expiry date out of an agency's reply.
 *
 * A regular expression was the obvious first answer and is the wrong one: the
 * replies are prose, the date arrives as "exp 4/30/26", "valid through April
 * 30th", or inside a quoted certificate, and roughly as often the mail says the
 * policy was cancelled and there is no date at all. Distinguishing those is the
 * whole job, so it goes to the model.
 *
 * The date is only the part the card sorts on. The same reply also carries the
 * exclusions, commodity sub-limits, limits, holder and insurer that the rest of
 * the sequences turn on, and they arrive in the same prose — so one call reads
 * all of it and returns a fixed JSON shape. Anything outside that shape is kept
 * on the response row for a human, never interpreted.
 */
class InsuranceExpiryExtractor
{
    private const NOT_FOUND = 'NOT FOUND';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are reading a reply from a commercial insurance agency to a freight
        broker who asked for a carrier's current certificate of insurance.

        The reply may carry the certificate itself as an attached PDF or scan.
        Where one is attached, read it: it is the document the broker will be
        held to, and the message around it is usually only a covering note.

        Answer with one JSON object and nothing else. No prose, no code fence.

        {
          "expiry_date": "YYYY-MM-DD" or null,
          "policy_number": string or null,
          "insurer": string or null,
          "agency": string or null,
          "holder_name": string or null,
          "coverages": [
            {"type": string, "limit": number or null, "expiry_date": "YYYY-MM-DD" or null}
          ],
          "exclusions": [string],
          "sub_limits": [{"commodity": string, "limit": number}],
          "scheduled_vins": [{"vin": string, "description": string or null}],
          "alternate_email": string or null,
          "signals": [string],
          "summary": string,
          "read_from": "certificate" | "email" | "both"
        }

        Rules when a document is attached:

        - The certificate outranks the message. Where the two disagree on a
          date, a limit or a name, take the certificate and say so in summary:
          a covering note written from memory is the thing that is wrong, and
          the disagreement is itself worth the broker knowing.
        - Read every coverage row on the certificate, not only the ones the
          message mentions, along with its limits and its own expiry date.
        - The certificate's own exclusions and endorsements count as
          exclusions, including anything conditioning cover on a schedule.
        - If the attachment is not a certificate at all — a signature image, an
          unrelated form — ignore it and read the message instead.

        Rules for expiry_date:

        - If the mail states more than one expiry date, give the earliest one
          that is still in the future relative to the other dates in the mail —
          a carrier is only covered until its first policy lapses.
        - If the mail does not state an expiry date, or says the policy is
          cancelled, expired or not in force, use null.
        - Never infer a date that is not in the mail.

        Rules for the rest:

        - coverages: one entry per line of coverage named (auto liability,
          general liability, cargo, trailer interchange, physical damage).
          `limit` is the dollar figure as a plain number, no symbols.
        - exclusions: anything the mail says is NOT covered, verbatim enough to
          be actionable. Include deductibles that a broker would need to know.
        - sub_limits: a lower limit that applies to a named commodity or
          situation only, beneath the general cargo limit.
        - scheduled_vins: the units listed on a scheduled-auto policy. Copy each
          VIN exactly as written, including its length — a VIN that has been
          transcribed short is worth knowing about. `description` is the year
          and model if the mail gives one.
        - holder_name: the certificate holder the mail says the certificate was
          made out to, if it names one.
        - alternate_email: a different address the mail asks you to write to
          instead, such as a service inbox in an out-of-office.
        - summary: one sentence, plain English, what this reply actually says.
        - read_from: "certificate" when the answer came off an attached
          document, "email" when it came out of the message text, "both" when
          each supplied part of it. It is shown to the broker, so it has to say
          where the numbers actually came from.

        signals — include every one that applies, and nothing else:

          policy_not_in_force      the policy is cancelled, expired or lapsed
          cancellation_rescinded   a previous cancellation has been withdrawn
          awaiting_authorization   the insured must approve before release
          renewal_pending          the renewal is not yet bound
          wrong_agency             this agency no longer writes the account
          direct_writer            the policy is direct and they cannot issue
          out_of_office            an automatic absence reply
          asks_requirements        they are asking what the broker needs
          certificate_disowned     they did not issue a certificate shown to them
          limit_discrepancy        a limit differs from what was shown to them
          insurer_changed          the account moved to a different insurer
          filing_lag               they say an FMCSA filing has not caught up
        PROMPT;

    public function __construct(private readonly Client $client) {}

    /**
     * @return array{expiry_date: ?CarbonImmutable, raw: string, details: array<string, mixed>}
     *
     * @throws RuntimeException when the model could not be reached or answered
     *                          in a shape this cannot read
     */
    public function extract(string $emailBody, array $documents = []): array
    {
        $body = $this->trimForModel($emailBody);

        if (trim($body) === '' && $documents === []) {
            throw new RuntimeException('The reply had no readable body to extract from.');
        }

        /*
         | "Certificate attached." is a complete reply, and until the document
         | went with it there was nothing here to read. The note below keeps the
         | user turn non-empty and tells the model where the answer actually is.
         */
        if (trim($body) === '') {
            $body = 'The reply carried no text of its own. Read the attached document.';
        }

        $hasDocuments = $documents !== [];

        $message = $this->client->messages->create(
            maxTokens: $hasDocuments
                ? (int) config('coi_insurance.llm.max_tokens_with_document', 4096)
                : (int) config('coi_insurance.llm.max_tokens', 1024),
            messages: [['role' => 'user', 'content' => $this->contentFor($body, $documents)]],
            model: (string) config('coi_insurance.llm.model', 'claude-opus-5'),
            /*
             | A sentence of prose is a one-line extraction and depth buys
             | nothing. A certificate is a dense page of limits, dates,
             | endorsements and exclusions that have to be read off a table and
             | told apart, so a reply carrying one gets more room to think.
             */
            outputConfig: ['effort' => $hasDocuments ? 'medium' : 'low'],
            system: self::SYSTEM_PROMPT,
        );

        $raw = $this->firstText($message);

        if ($raw === null) {
            throw new RuntimeException('The model returned no text block.');
        }

        $details = $this->parseDetails($raw);

        return [
            'expiry_date' => $this->parseDate($details['expiry_date'] ?? null, $raw),
            'raw' => $raw,
            'details' => $details,
        ];
    }

    /**
     * The user turn: the documents first, then the prose.
     *
     * The order is the documented one and it is not arbitrary — a document
     * placed after the text it relates to is read less reliably.
     *
     * @param  array<int, array{data: string, media_type: string, filename: string}>  $documents
     * @return array<int, DocumentBlockParam|ImageBlockParam|TextBlockParam>
     */
    private function contentFor(string $body, array $documents): array
    {
        $blocks = [];

        foreach ($documents as $document) {
            $mediaType = strtolower((string) ($document['media_type'] ?? ''));
            $data = (string) ($document['data'] ?? '');

            if ($data === '') {
                continue;
            }

            if ($mediaType === 'application/pdf') {
                $blocks[] = DocumentBlockParam::with(
                    source: Base64PDFSource::with($data),

                    // The agency's own filename. "ACME COI 2026.pdf" tells the
                    // model which carrier the page belongs to when a reply
                    // carries a certificate and an endorsement together.
                    title: (string) ($document['filename'] ?? 'certificate.pdf'),
                );

                continue;
            }

            // A scanned certificate photographed or faxed in. The same page,
            // arriving as pixels rather than as text.
            $blocks[] = ImageBlockParam::with(
                source: Base64ImageSource::with($data, $mediaType),
            );
        }

        $blocks[] = TextBlockParam::with($body);

        return $blocks;
    }

    /**
     * A reply carrying a long quoted thread is common and its tail is never
     * where the answer is. Cut from the end so the newest text survives.
     */
    private function trimForModel(string $body): string
    {
        $limit = (int) config('coi_insurance.llm.max_body_chars', 20000);

        return mb_strlen($body) > $limit ? mb_substr($body, 0, $limit) : $body;
    }

    private function firstText(object $message): ?string
    {
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock || ($block->type ?? null) === 'text') {
                $text = trim($block->text);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * The model's JSON, or a refusal to guess.
     *
     * A reply this cannot read at all throws rather than returning an empty
     * reading: "the agency said nothing useful" and "we failed to parse the
     * answer" must not collapse into the same outcome on the request row.
     *
     * @return array<string, mixed>
     */
    private function parseDetails(string $raw): array
    {
        $json = trim($raw);

        // Tolerate a fenced block, which the model is told not to send but
        // occasionally does anyway. Cheaper than a retry.
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json) ?? $json;
        }

        $decoded = json_decode(trim($json), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The model answered in an unexpected format: '.$raw);
        }

        return [
            'expiry_date' => $this->stringOrNull($decoded['expiry_date'] ?? null),
            'policy_number' => $this->stringOrNull($decoded['policy_number'] ?? null),
            'insurer' => $this->stringOrNull($decoded['insurer'] ?? null),
            'agency' => $this->stringOrNull($decoded['agency'] ?? null),
            'holder_name' => $this->stringOrNull($decoded['holder_name'] ?? null),
            'alternate_email' => $this->stringOrNull($decoded['alternate_email'] ?? null),
            'summary' => $this->stringOrNull($decoded['summary'] ?? null),
            'read_from' => $this->stringOrNull($decoded['read_from'] ?? null),
            'coverages' => $this->listOf($decoded['coverages'] ?? null),
            'exclusions' => array_values(array_filter(
                $this->listOf($decoded['exclusions'] ?? null),
                fn ($e) => is_string($e) && trim($e) !== '',
            )),
            'sub_limits' => $this->listOf($decoded['sub_limits'] ?? null),
            'scheduled_vins' => $this->listOf($decoded['scheduled_vins'] ?? null),
            'signals' => array_values(array_filter(
                $this->listOf($decoded['signals'] ?? null),
                fn ($s) => is_string($s) && trim($s) !== '',
            )),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' || strcasecmp($trimmed, self::NOT_FOUND) === 0
            ? null
            : $trimmed;
    }

    /** @return array<int, mixed> */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Null means the model said there is no date — a real answer, not a
     * failure. A value it cannot read at all throws instead, so the two do not
     * collapse into the same outcome on the request row.
     */
    private function parseDate(?string $value, string $raw): ?CarbonImmutable
    {
        if ($value === null || $value === '' || strcasecmp($value, self::NOT_FOUND) === 0) {
            return null;
        }

        try {
            // Pinned to the format asked for rather than parsed loosely:
            // Carbon reads "04/05/2026" as the 4th of May, and a COI written in
            // the US means the 5th of April. Refusing anything but ISO is the
            // only way that ambiguity cannot silently produce a wrong date.
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw new RuntimeException('The model returned an unparseable date: '.$value);
        }

        if ($date === false) {
            throw new RuntimeException('The model returned an unparseable date: '.$value);
        }

        return $date;
    }

}
