<?php

namespace App\Services\Coi;

/**
 * Just enough MIME to get the text and the attachments out of an SES delivery.
 *
 * Only the SES path needs this — every other provider hands over the body and
 * the files as fields. Nested multiparts beyond what a mail client produces are
 * still ignored, but attachments are not: the certificate itself usually
 * arrives as a PDF hanging off the reply, and a parser that reads the prose and
 * drops the document was answering half the question.
 */
class MimeMessage
{
    /**
     * @return array{text: ?string, html: ?string, attachments: array<int, InboundAttachment>}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);

        $contentType = $headers['content-type'] ?? 'text/plain';
        $disposition = $headers['content-disposition'] ?? '';

        if (stripos($contentType, 'multipart/') === 0) {
            $boundary = self::boundary($contentType);

            return $boundary === null
                ? self::empty()
                : self::parseMultipart($body, $boundary);
        }

        if (self::isAttachmentPart($contentType, $disposition)) {
            $attachment = self::attachmentFrom($headers, $body, $contentType, $disposition);

            return $attachment === null
                ? self::empty()
                : ['text' => null, 'html' => null, 'attachments' => [$attachment]];
        }

        $decoded = self::decodeText($body, $headers['content-transfer-encoding'] ?? '', $contentType);

        return stripos($contentType, 'text/html') === 0
            ? ['text' => null, 'html' => $decoded, 'attachments' => []]
            : ['text' => $decoded, 'html' => null, 'attachments' => []];
    }

    /**
     * @return array{text: ?string, html: ?string, attachments: array<int, InboundAttachment>}
     */
    private static function parseMultipart(string $body, string $boundary): array
    {
        $result = self::empty();

        $parts = preg_split('/\R--'.preg_quote($boundary, '/').'(--)?\R?/', "\r\n".$body) ?: [];

        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }

            $nested = self::parse(ltrim($part, "\r\n"));

            // First one of each kind wins. A mail client puts the readable
            // alternative first and the fallback after it.
            $result['text'] ??= $nested['text'];
            $result['html'] ??= $nested['html'];

            /*
             | Attachments accumulate rather than stopping at the first, and the
             | loop no longer breaks once both bodies are in hand: an agency
             | attaching a certificate and an endorsement sends two files, and
             | the files come after the bodies in every mail there is.
             */
            $result['attachments'] = array_merge($result['attachments'], $nested['attachments']);
        }

        return $result;
    }

    /**
     * @return array{text: null, html: null, attachments: array<int, InboundAttachment>}
     */
    private static function empty(): array
    {
        return ['text' => null, 'html' => null, 'attachments' => []];
    }

    /**
     * A part is a file when it says so, or when it is not text at all.
     *
     * The second half matters: plenty of mail clients attach a PDF with no
     * Content-Disposition whatsoever, and treating those as body text puts a
     * screenful of binary where the reply should be.
     */
    private static function isAttachmentPart(string $contentType, string $disposition): bool
    {
        if (stripos($disposition, 'attachment') === 0) {
            return true;
        }

        // `inline` with a name is a signature logo or an embedded scan — a file
        // either way, and one the store below drops once it sees the Content-ID.
        if (stripos($disposition, 'inline') === 0 && self::parameter($disposition, 'filename') !== null) {
            return true;
        }

        if (self::parameter($contentType, 'name') !== null) {
            return true;
        }

        return stripos($contentType, 'text/') !== 0;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function attachmentFrom(
        array $headers,
        string $body,
        string $contentType,
        string $disposition,
    ): ?InboundAttachment {
        $content = self::decodeBinary($body, $headers['content-transfer-encoding'] ?? '');

        if ($content === '') {
            return null;
        }

        $filename = self::parameter($disposition, 'filename')
            ?? self::parameter($contentType, 'name');

        return new InboundAttachment(
            filename: $filename ?? 'attachment',
            contentType: trim(explode(';', $contentType)[0]) ?: null,
            content: $content,
            contentId: trim((string) ($headers['content-id'] ?? ''), " <>") ?: null,
        );
    }

    /**
     * One parameter off a header value, MIME word and RFC 5987 forms included.
     *
     * Both turn up on real mail: `filename="=?UTF-8?B?...?="` from clients that
     * encode the whole name, and `filename*=UTF-8''cert%20of%20insurance.pdf`
     * from the ones that follow the newer rule.
     */
    private static function parameter(string $header, string $name): ?string
    {
        if (preg_match('/'.preg_quote($name, '/').'\*=(?:([^\']*)\'[^\']*\')?"?([^";]+)"?/i', $header, $match)) {
            $value = rawurldecode($match[2]);
            $charset = strtoupper($match[1] ?? '');

            if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
                $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
                $value = $converted !== false ? $converted : $value;
            }

            return trim($value) ?: null;
        }

        if (! preg_match('/'.preg_quote($name, '/').'=\s*"([^"]*)"|'.preg_quote($name, '/').'=\s*([^;]+)/i', $header, $match)) {
            return null;
        }

        $value = trim($match[1] !== '' ? $match[1] : ($match[2] ?? ''));

        if ($value === '') {
            return null;
        }

        $decoded = @mb_decode_mimeheader($value);

        return trim($decoded !== false && $decoded !== '' ? $decoded : $value) ?: null;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);

        $break = strpos($raw, "\n\n");

        if ($break === false) {
            return [[], $raw];
        }

        $headerBlock = substr($raw, 0, $break);
        $body = substr($raw, $break + 2);

        // Unfold continuation lines before splitting — a long Content-Type with
        // its boundary on the second line is the common case, and reading it
        // line by line loses the boundary entirely.
        $headerBlock = preg_replace('/\n[ \t]+/', ' ', $headerBlock) ?? $headerBlock;

        $headers = [];

        foreach (explode("\n", $headerBlock) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$headers, $body];
    }

    private static function boundary(string $contentType): ?string
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', $contentType, $match)) {
            return null;
        }

        return trim($match[1]);
    }

    private static function decodeText(string $body, string $encoding, string $contentType): string
    {
        $decoded = match (strtolower(trim($encoding))) {
            'base64' => base64_decode($body, true) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        $charset = preg_match('/charset="?([^";\s]+)"?/i', $contentType, $match)
            ? strtoupper(trim($match[1]))
            : 'UTF-8';

        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            $decoded = $converted !== false ? $converted : $decoded;
        }

        return trim($decoded);
    }

    /**
     * The same decode without the text handling.
     *
     * Neither the trim nor the charset conversion above is safe on a PDF: one
     * eats leading and trailing bytes, the other rewrites whatever it decides
     * are invalid sequences, and both corrupt the file silently.
     */
    private static function decodeBinary(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? '', true) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
