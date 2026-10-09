<?php

namespace App\Services\Coi;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads the producer's email address straight off the carrier's certificate.
 *
 * The OCR extraction keeps the PRODUCER block's name and street address but
 * drops its E-MAIL line, so on most carriers it has nothing to offer the
 * resolver. The certificate itself still has it, and it differs on every one —
 * certificates@ at one agency, a named account manager at the next — so it is
 * read off the newest document on file rather than guessed.
 *
 * A failure here returns null, never throws: the caller turns "no address" into
 * a message the broker can read, and a model outage should not become a 500 on
 * a button click.
 */
class CoiProducerEmailReader
{
    private const NONE = 'NONE';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are reading an ACORD 25 certificate of liability insurance.
        Reply with only the email address printed in the PRODUCER / CONTACT
        block (the agency that issued the certificate) — nothing else.
        Never return the insured's, the certificate holder's or an insurer's
        address. If the producer block has no email address, reply NONE.
        PROMPT;

    public function __construct(private readonly Client $client) {}

    public function read(int|string $dotNumber): ?string
    {
        $document = DB::connection('external_db')
            ->table('coi_documents')
            ->where('dot_number', $dotNumber)
            ->whereNotNull('s3_key')
            ->orderByDesc('uploaded_at')
            ->first();

        if ($document === null) {
            return null;
        }

        try {
            $bytes = Storage::disk(config('coi_insurance.contact.disk', 's3'))->get($document->s3_key);

            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            if (strlen($bytes) > (int) config('coi_insurance.contact.max_bytes', 8 * 1024 * 1024)) {
                Log::warning('COI document too large to read producer email', [
                    'dot_number' => $dotNumber,
                    's3_key' => $document->s3_key,
                    'bytes' => strlen($bytes),
                ]);

                return null;
            }

            $message = $this->client->messages->create(
                maxTokens: 200,
                messages: [['role' => 'user', 'content' => [
                    $this->documentBlock($bytes, (string) $document->s3_key),
                    TextBlockParam::with('What is the producer email address on this certificate?'),
                ]]],
                model: (string) config('coi_insurance.contact.model', 'claude-haiku-4-5'),
                system: self::SYSTEM_PROMPT,
            );

            return $this->parseEmail($this->firstText($message) ?? '');
        } catch (Throwable $e) {
            Log::error('Reading producer email from COI document failed', [
                'dot_number' => $dotNumber,
                's3_key' => $document->s3_key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function documentBlock(string $bytes, string $key): DocumentBlockParam|ImageBlockParam
    {
        $mediaType = match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/pdf',
        };

        if ($mediaType !== 'application/pdf') {
            return ImageBlockParam::with(source: Base64ImageSource::with(base64_encode($bytes), $mediaType));
        }

        return DocumentBlockParam::with(source: Base64PDFSource::with(base64_encode($bytes)));
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
     * The model is told to answer with the bare address, but a stray sentence
     * around it is tolerated — the address is what is kept, and it is checked
     * before anything is mailed to it.
     */
    private function parseEmail(string $raw): ?string
    {
        if (trim($raw) === '' || strcasecmp(trim($raw), self::NONE) === 0) {
            return null;
        }

        if (! preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $raw, $match)) {
            return null;
        }

        $email = strtolower(rtrim($match[0], '.,;:'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
