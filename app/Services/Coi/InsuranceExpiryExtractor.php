<?php

namespace App\Services\Coi;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
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
 * The model is asked for one fixed line and nothing else. Everything the caller
 * acts on is parsed out of that line — the free text around it is kept on the
 * response row for a human, never interpreted.
 */
class InsuranceExpiryExtractor
{
    /**
     * The marker the answer is read from. Anything the model says that is not
     * on this line is ignored rather than guessed at.
     */
    private const ANSWER_PREFIX = 'Insurance Expiry Date -';

    private const NOT_FOUND = 'NOT FOUND';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You have been provided an email content containing the carrier insurance
        information. Your task is to extract the insurance expiry date.

        Rules:

        - Answer with exactly one line and nothing else, in this format:

              Insurance Expiry Date - <output date>

        - <output date> must be in YYYY-MM-DD form.
        - If the email states more than one expiry date, give the earliest one
          that is still in the future relative to the other dates in the mail —
          a carrier is only covered until its first policy lapses.
        - If the email does not state an expiry date, or says the policy is
          cancelled, expired or not in force, answer:

              Insurance Expiry Date - NOT FOUND

        - Never infer a date that is not in the email. Do not explain.
        PROMPT;

    public function __construct(private readonly Client $client) {}

    /**
     * @return array{expiry_date: ?CarbonImmutable, raw: string}
     *
     * @throws RuntimeException when the model could not be reached or answered
     *                          in a shape this cannot read
     */
    public function extract(string $emailBody): array
    {
        $body = $this->trimForModel($emailBody);

        if (trim($body) === '') {
            throw new RuntimeException('The reply had no readable body to extract from.');
        }

        $message = $this->client->messages->create(
            maxTokens: (int) config('coi_insurance.llm.max_tokens', 1024),
            messages: [['role' => 'user', 'content' => $body]],
            model: (string) config('coi_insurance.llm.model', 'claude-opus-5'),
            // A one-line extraction. Depth here buys nothing and costs on every
            // reply that arrives.
            outputConfig: ['effort' => 'low'],
            system: self::SYSTEM_PROMPT,
        );

        $raw = $this->firstText($message);

        if ($raw === null) {
            throw new RuntimeException('The model returned no text block.');
        }

        return [
            'expiry_date' => $this->parseAnswer($raw),
            'raw' => $raw,
        ];
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
     * Null means the model said there is no date — a real answer, not a
     * failure. A line this cannot read at all throws instead, so the two do
     * not collapse into the same outcome on the request row.
     */
    private function parseAnswer(string $raw): ?CarbonImmutable
    {
        if (! preg_match('/'.preg_quote(self::ANSWER_PREFIX, '/').'\s*(.+)$/mi', $raw, $match)) {
            throw new RuntimeException('The model answered in an unexpected format: '.$raw);
        }

        $value = trim($match[1], " \t\n\r\0\x0B.\"'");

        if ($value === '' || strcasecmp($value, self::NOT_FOUND) === 0) {
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

        // createFromFormat rolls overflow forward rather than rejecting it, so
        // "2026-13-45" would come back as a date in 2027. Round-tripping is
        // what actually rules that out.
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('The model returned an unparseable date: '.$value);
        }

        return $date;
    }
}
