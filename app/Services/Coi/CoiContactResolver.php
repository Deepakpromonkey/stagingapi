<?php

namespace App\Services\Coi;

use App\Models\Carriers\Carrier;
use App\Models\CoiDocumentExtraction;

/**
 * Works out who to ask for a carrier's current insurance details.
 *
 * The address wanted is the agency's, not the carrier's: an ACORD 25 names the
 * producer — the broker of record who issued the certificate — and they are the
 * only party who can answer "what is it now". The carrier's own census address
 * is the fallback, and it is a poor one, so which of the two was used is
 * recorded on the request rather than left to be guessed later.
 *
 * The extraction JSON is not a fixed shape. It is whatever the OCR made of a
 * scanned PDF, and the key names differ between certificates, so this walks the
 * structure looking for an address rather than reading a known path.
 */
class CoiContactResolver
{
    /**
     * Key fragments that mark the producer / agency side of a certificate.
     * Matched case-insensitively against the path a value was found at.
     */
    private const PRODUCER_HINTS = ['producer', 'agent', 'agency', 'broker'];

    /**
     * Addresses that belong to the certificate's plumbing rather than to a
     * person who can answer a question about it.
     */
    private const IGNORED_DOMAINS = [
        'acord.org',
        'example.com',
        'noreply',
        'no-reply',
        'donotreply',
    ];

    /**
     * @return array{email: string, source: string}|null
     */
    public function resolve(int|string $dotNumber): ?array
    {
        $fromOcr = $this->fromExtractions($dotNumber);

        if ($fromOcr !== null) {
            return ['email' => $fromOcr, 'source' => 'ocr'];
        }

        $fromCensus = $this->fromCarrierRecord($dotNumber);

        if ($fromCensus !== null) {
            return ['email' => $fromCensus, 'source' => 'fmcsa'];
        }

        return null;
    }

    /**
     * Newest certificate first — an agency that changed hands should be chased
     * at the address on the most recent document, not the first one filed.
     */
    private function fromExtractions(int|string $dotNumber): ?string
    {
        $extractions = CoiDocumentExtraction::where('dot_number', $dotNumber)
            ->orderByDesc('extracted_at')
            ->limit(5)
            ->get();

        $fallback = null;

        foreach ($extractions as $extraction) {
            $candidates = $this->collectEmails($extraction->extracted_json ?? []);

            foreach ($candidates as $candidate) {
                if ($candidate['is_producer']) {
                    return $candidate['email'];
                }

                // Any other address on the certificate is better than nothing,
                // but only once every producer field has been ruled out —
                // across every extraction, not just this one.
                $fallback ??= $candidate['email'];
            }
        }

        return $fallback;
    }

    private function fromCarrierRecord(int|string $dotNumber): ?string
    {
        $email = Carrier::where('dot_number', $dotNumber)->value('email_address');

        return $this->isUsable((string) $email) ? strtolower(trim((string) $email)) : null;
    }

    /**
     * Walk the extraction and return every address in it, tagged with whether
     * the path it was found at looks like the producer block.
     *
     * @return array<int, array{email: string, is_producer: bool}>
     */
    private function collectEmails(mixed $node, string $path = ''): array
    {
        if (is_string($node)) {
            $email = $this->firstEmailIn($node);

            if ($email === null) {
                return [];
            }

            return [['email' => $email, 'is_producer' => $this->looksLikeProducer($path)]];
        }

        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            $found = array_merge($found, $this->collectEmails($value, $path.'.'.$key));
        }

        return $found;
    }

    private function firstEmailIn(string $value): ?string
    {
        // OCR routinely drops a space into an address or wraps it in angle
        // brackets; strip the whitespace before matching so those still read.
        $value = preg_replace('/\s+/', '', $value) ?? $value;

        if (! preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $match)) {
            return null;
        }

        $email = strtolower(rtrim($match[0], '.,;:'));

        return $this->isUsable($email) ? $email : null;
    }

    private function isUsable(string $email): bool
    {
        $email = strtolower(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        foreach (self::IGNORED_DOMAINS as $ignored) {
            if (str_contains($email, $ignored)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeProducer(string $path): bool
    {
        $path = strtolower($path);

        foreach (self::PRODUCER_HINTS as $hint) {
            if (str_contains($path, $hint)) {
                return true;
            }
        }

        return false;
    }
}
