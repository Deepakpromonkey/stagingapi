<?php

namespace App\Services\Coi;

use App\Models\CoiDocumentExtraction;

/**
 * Works out who to ask for a carrier's current insurance details.
 *
 * The address wanted is the agency's, not the carrier's: an ACORD 25 names the
 * producer — the broker of record who issued the certificate — and they are the
 * only party who can answer "what is it now". The carrier's own FMCSA census
 * address is deliberately not used: it reaches the carrier, not the agency.
 * With no producer address the request is refused rather than misdirected.
 *
 * The extraction JSON is not a fixed shape. It is whatever the OCR made of a
 * scanned PDF, and the key names differ between certificates, so this walks the
 * structure looking for an address rather than reading a known path.
 */
class CoiContactResolver
{
    public function __construct(private readonly CoiProducerEmailReader $documents) {}

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

        // The OCR rarely keeps the E-MAIL line, so the certificate itself is
        // read for it — every agency's address is different.
        $fromDocument = $this->documents->read($dotNumber);

        if ($fromDocument !== null && $this->isUsable($fromDocument)) {
            return ['email' => $fromDocument, 'source' => 'coi_document'];
        }

        return null;
    }

    /**
     * Newest certificate first — an agency that changed hands should be chased
     * at the address on the most recent document, not the first one filed.
     *
     * Only an address found under a producer / agency key counts. Any other
     * address on a certificate may be the insured's or the holder's.
     */
    private function fromExtractions(int|string $dotNumber): ?string
    {
        $extractions = CoiDocumentExtraction::where('dot_number', $dotNumber)
            ->orderByDesc('extracted_at')
            ->limit(5)
            ->get();

        foreach ($extractions as $extraction) {
            foreach ($this->collectEmails($extraction->extracted_json ?? []) as $candidate) {
                if ($candidate['is_producer']) {
                    return $candidate['email'];
                }
            }
        }

        return null;
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
