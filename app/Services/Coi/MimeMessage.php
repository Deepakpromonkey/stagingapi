<?php

namespace App\Services\Coi;

/**
 * Just enough MIME to get the text out of an SES delivery.
 *
 * Only the SES path needs this — every other provider hands over the body as a
 * field. Attachments, nested multiparts beyond what a mail client produces, and
 * anything that is not a text part are ignored on purpose: the only thing read
 * out of a reply is a date, so a parser that understands text/plain and
 * text/html and nothing else is the whole requirement.
 */
class MimeMessage
{
    /**
     * @return array{text: ?string, html: ?string}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);

        $contentType = $headers['content-type'] ?? 'text/plain';

        if (stripos($contentType, 'multipart/') === 0) {
            $boundary = self::boundary($contentType);

            return $boundary === null
                ? ['text' => null, 'html' => null]
                : self::parseMultipart($body, $boundary);
        }

        $decoded = self::decode($body, $headers['content-transfer-encoding'] ?? '', $contentType);

        return stripos($contentType, 'text/html') === 0
            ? ['text' => null, 'html' => $decoded]
            : ['text' => $decoded, 'html' => null];
    }

    /**
     * @return array{text: ?string, html: ?string}
     */
    private static function parseMultipart(string $body, string $boundary): array
    {
        $result = ['text' => null, 'html' => null];

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

            if ($result['text'] !== null && $result['html'] !== null) {
                break;
            }
        }

        return $result;
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

    private static function decode(string $body, string $encoding, string $contentType): string
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
}
