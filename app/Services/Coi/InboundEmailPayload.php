<?php

namespace App\Services\Coi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * One inbound mail, flattened out of whatever the provider posted.
 *
 * Four shapes reach this endpoint depending on who fronts the mailbox —
 * Postmark, Mailgun routes, SendGrid Inbound Parse, and SES delivering through
 * SNS — and they agree on nothing, not even whether the body is a field or a
 * base64 MIME blob. The rest of the feature is written against this class so
 * that swapping providers is a change in one file.
 *
 * Detection is by payload shape rather than by configuration, so a provider
 * that is being trialled alongside the current one still routes.
 */
class InboundEmailPayload
{
    /**
     * @param  array<int, string>  $recipients
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $recipients,
        public readonly ?string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly ?string $inReplyTo,
        public readonly ?CarbonImmutable $receivedAt,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromProviderPayload(array $payload): self
    {
        // Postmark: capitalised keys, and the only one that hands over the
        // reply already stripped of the quoted thread.
        if (isset($payload['FromFull']) || isset($payload['TextBody'])) {
            return self::fromPostmark($payload);
        }

        // Mailgun routes post form fields, hyphenated.
        if (isset($payload['body-plain']) || isset($payload['stripped-text'])) {
            return self::fromMailgun($payload);
        }

        // SES arrives wrapped in an SNS envelope; the mail itself is a JSON
        // string inside `Message`.
        if (isset($payload['Type']) && isset($payload['Message'])) {
            return self::fromSns($payload);
        }

        // SendGrid Inbound Parse, and anything else posting the obvious names.
        return self::fromGeneric($payload);
    }

    /**
     * The body the extraction should read.
     *
     * Text is preferred over HTML because the model is being asked for one
     * date and markup is nothing but noise around it. Postmark's stripped
     * reply wins over the full text where it exists — a thread quoted eight
     * replies deep usually contains an older, now-wrong expiry date, and
     * feeding both invites the model to pick the stale one.
     */
    public function bodyForExtraction(): string
    {
        $stripped = trim((string) Arr::get($this->raw, 'StrippedTextReply', ''));

        if ($stripped !== '') {
            return $stripped;
        }

        $strippedMailgun = trim((string) Arr::get($this->raw, 'stripped-text', ''));

        if ($strippedMailgun !== '') {
            return $strippedMailgun;
        }

        $text = trim((string) $this->text);

        if ($text !== '') {
            return $text;
        }

        return trim(html_entity_decode(strip_tags((string) $this->html)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromPostmark(array $payload): self
    {
        $recipients = array_merge(
            self::addressesFrom(Arr::get($payload, 'ToFull', []), 'Email'),
            self::addressesFrom(Arr::get($payload, 'CcFull', []), 'Email'),
            self::splitAddressList(Arr::get($payload, 'To')),
            self::splitAddressList(Arr::get($payload, 'OriginalRecipient')),
        );

        return new self(
            fromEmail: self::normaliseAddress(Arr::get($payload, 'FromFull.Email') ?? Arr::get($payload, 'From')),
            fromName: Arr::get($payload, 'FromFull.Name') ?: null,
            recipients: self::unique($recipients),
            subject: Arr::get($payload, 'Subject'),
            text: Arr::get($payload, 'TextBody'),
            html: Arr::get($payload, 'HtmlBody'),
            inReplyTo: self::headerFromList(Arr::get($payload, 'Headers', []), 'In-Reply-To'),
            receivedAt: self::parseDate(Arr::get($payload, 'Date')),
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromMailgun(array $payload): self
    {
        $recipients = array_merge(
            self::splitAddressList(Arr::get($payload, 'recipient')),
            self::splitAddressList(Arr::get($payload, 'To')),
            self::splitAddressList(Arr::get($payload, 'to')),
        );

        return new self(
            fromEmail: self::normaliseAddress(Arr::get($payload, 'sender') ?? Arr::get($payload, 'from')),
            fromName: self::nameFromAddress(Arr::get($payload, 'from')),
            recipients: self::unique($recipients),
            subject: Arr::get($payload, 'subject'),
            text: Arr::get($payload, 'body-plain'),
            html: Arr::get($payload, 'body-html'),
            inReplyTo: Arr::get($payload, 'In-Reply-To'),
            receivedAt: self::parseDate(Arr::get($payload, 'Date')),
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromSns(array $payload): self
    {
        $message = Arr::get($payload, 'Message');
        $decoded = is_string($message) ? json_decode($message, true) : $message;
        $decoded = is_array($decoded) ? $decoded : [];

        $headers = Arr::get($decoded, 'mail.commonHeaders', []);

        /*
         | SES only includes the mail itself when the receipt rule action says
         | so. Without it there is a notification and no body, which is not
         | something this feature can do anything with — the parse below simply
         | yields nulls and the caller records that it could not read the reply.
         */
        $mime = Arr::get($decoded, 'content');
        $parsed = is_string($mime) ? MimeMessage::parse(self::maybeBase64($mime)) : ['text' => null, 'html' => null];

        return new self(
            fromEmail: self::normaliseAddress(Arr::get($headers, 'from.0') ?? Arr::get($decoded, 'mail.source')),
            fromName: self::nameFromAddress(Arr::get($headers, 'from.0')),
            recipients: self::unique(array_merge(
                array_map(self::normaliseAddress(...), (array) Arr::get($headers, 'to', [])),
                array_map(self::normaliseAddress(...), (array) Arr::get($decoded, 'mail.destination', [])),
                (array) Arr::get($decoded, 'receipt.recipients', []),
            )),
            subject: Arr::get($headers, 'subject'),
            text: $parsed['text'],
            html: $parsed['html'],
            inReplyTo: Arr::get($headers, 'inReplyTo'),
            receivedAt: self::parseDate(Arr::get($headers, 'date') ?? Arr::get($decoded, 'mail.timestamp')),
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromGeneric(array $payload): self
    {
        /*
         | SendGrid posts the SMTP envelope as a JSON *string*, not as nested
         | fields, so `envelope.to` reads as nothing without decoding it first.
         | It matters more than the To header: the header is what the agency's
         | client wrote and can be rewritten or dropped on a reply-all, while
         | the envelope is the address the mail was actually delivered to —
         | which is the one carrying our token.
         */
        $envelope = Arr::get($payload, 'envelope');

        if (is_string($envelope)) {
            $decoded = json_decode($envelope, true);
            $envelope = is_array($decoded) ? $decoded : [];
        }

        $recipients = array_merge(
            self::splitAddressList(Arr::get((array) $envelope, 'to')),
            self::splitAddressList(Arr::get($payload, 'to')),
            self::splitAddressList(Arr::get($payload, 'To')),
            (array) Arr::get($payload, 'recipients', []),
        );

        /*
         | SendGrid's "POST the raw, full MIME message" setting replaces every
         | parsed field with one `email` blob. Handled rather than forbidden,
         | because the box is easy to tick by accident and the failure it
         | causes otherwise is a reply that silently has no body.
         */
        $rawMime = Arr::get($payload, 'email');
        $parsed = is_string($rawMime) && trim($rawMime) !== ''
            ? MimeMessage::parse($rawMime)
            : ['text' => null, 'html' => null];

        return new self(
            fromEmail: self::normaliseAddress(
                Arr::get($payload, 'from') ?? Arr::get($payload, 'From') ?? Arr::get((array) $envelope, 'from')
            ),
            fromName: self::nameFromAddress(Arr::get($payload, 'from') ?? Arr::get($payload, 'From')),
            recipients: self::unique($recipients),
            subject: Arr::get($payload, 'subject') ?? Arr::get($payload, 'Subject'),
            text: Arr::get($payload, 'text') ?? Arr::get($payload, 'plain') ?? $parsed['text'],
            html: Arr::get($payload, 'html') ?? $parsed['html'],
            inReplyTo: Arr::get($payload, 'in_reply_to') ?? Arr::get($payload, 'In-Reply-To'),
            receivedAt: self::parseDate(Arr::get($payload, 'date') ?? Arr::get($payload, 'Date')),
            raw: $payload,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function addressesFrom(mixed $list, string $key): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($entry) => is_array($entry) ? self::normaliseAddress($entry[$key] ?? null) : self::normaliseAddress($entry),
            $list,
        )));
    }

    /**
     * @return array<int, string>
     */
    private static function splitAddressList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(self::normaliseAddress(...), $value)));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            self::normaliseAddress(...),
            preg_split('/[,;]/', $value) ?: [],
        )));
    }

    /**
     * Pulls the bare address out of `Name <a@b.com>` and lowercases it —
     * mailbox comparison downstream is exact, and providers are inconsistent
     * about the display name and about case.
     */
    private static function normaliseAddress(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $match)) {
            $value = $match[1];
        }

        $value = strtolower(trim($value, " \t\n\r<>\"'"));

        return $value === '' ? null : $value;
    }

    private static function nameFromAddress(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\s*"?([^"<]+?)"?\s*</', $value, $match)) {
            return null;
        }

        $name = trim($match[1]);

        return $name === '' ? null : $name;
    }

    private static function headerFromList(mixed $headers, string $name): ?string
    {
        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $header) {
            if (is_array($header) && strcasecmp((string) ($header['Name'] ?? ''), $name) === 0) {
                return $header['Value'] ?? null;
            }
        }

        return null;
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * SES posts the raw MIME base64-encoded under some configurations and
     * plain under others; both start recognisably differently.
     */
    private static function maybeBase64(string $value): string
    {
        if (str_contains(substr($value, 0, 200), ':')) {
            return $value;
        }

        return base64_decode($value, true) ?: $value;
    }

    /**
     * @param  array<int, ?string>  $addresses
     * @return array<int, string>
     */
    private static function unique(array $addresses): array
    {
        return array_values(array_unique(array_filter($addresses)));
    }
}
