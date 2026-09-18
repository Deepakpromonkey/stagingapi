<?php

namespace App\Services\Coi;

use App\Models\CoiInsuranceResponse;
use App\Models\CoiInsuranceResponseAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts the files that arrived on a reply somewhere the card can read them.
 *
 * Anyone in the world can mail the insurance inbox — the address is on the
 * request that went out — so everything here treats the file as hostile: only
 * the handful of types a certificate actually arrives as are kept, the size is
 * capped, the stored name is a uuid rather than anything the sender chose, and
 * a file that cannot be written is logged and skipped rather than failing the
 * reply. The prose has already been saved by the time this runs, and losing the
 * whole answer because a PDF would not upload is the worse outcome.
 */
class CoiAttachmentStore
{
    /**
     * @param  array<int, InboundAttachment>  $attachments
     * @return array<int, CoiInsuranceResponseAttachment>
     */
    public function store(CoiInsuranceResponse $response, array $attachments): array
    {
        $disk = (string) config('coi_insurance.attachments.disk', 's3');
        $maxBytes = (int) config('coi_insurance.attachments.max_bytes', 15 * 1024 * 1024);
        $maxCount = (int) config('coi_insurance.attachments.max_per_reply', 10);

        $stored = [];

        foreach ($attachments as $attachment) {
            if (count($stored) >= $maxCount) {
                break;
            }

            if (! $this->isKeepable($attachment, $maxBytes, $response)) {
                continue;
            }

            $uuid = (string) Str::uuid();

            /*
             | The path is built entirely from values this application chose.
             | The agency's filename is kept on the row for display and never
             | reaches the disk, so `../../` in a filename goes nowhere.
             */
            $path = sprintf(
                'coi-insurance/%d/%s/%s%s',
                $response->coi_insurance_request_id,
                $response->uuid,
                $uuid,
                $this->extensionFor($attachment),
            );

            try {
                $written = Storage::disk($disk)->put($path, $attachment->content);
            } catch (\Throwable $e) {
                $written = false;

                Log::error('COI attachment could not be stored', [
                    'response_uuid' => $response->uuid,
                    'filename' => $attachment->filename,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($written === false) {
                continue;
            }

            $stored[] = $response->attachments()->create([
                'uuid' => $uuid,
                'filename' => $this->safeFilename($attachment->filename),
                'content_type' => $this->contentTypeFor($attachment),
                'size_bytes' => $attachment->size(),
                'disk' => $disk,
                'path' => $path,
                'sha256' => hash('sha256', $attachment->content),
            ]);
        }

        return $stored;
    }

    private function isKeepable(InboundAttachment $attachment, int $maxBytes, CoiInsuranceResponse $response): bool
    {
        if ($attachment->size() === 0 || $attachment->size() > $maxBytes) {
            return false;
        }

        /*
         | An inline image is part of the message body — a signature logo, a
         | tracking pixel — not something the agency sent. Listing those under
         | "Attachments" buries the one file the broker came for.
         */
        if ($attachment->isInline() && ! $this->looksLikeDocument($attachment)) {
            return false;
        }

        if (! $this->isAllowedType($attachment)) {
            Log::info('COI attachment dropped for its type', [
                'response_uuid' => $response->uuid,
                'filename' => $attachment->filename,
                'content_type' => $attachment->contentType,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The type as it should be believed.
     *
     * A sender's Content-Type is a claim, and several mail clients send every
     * attachment as `application/octet-stream` regardless of what it is — so
     * the content is read first, and the sender's header is only consulted when
     * the content does not say.
     */
    private function contentTypeFor(InboundAttachment $attachment): ?string
    {
        return $this->sniff($attachment) ?? $attachment->contentType;
    }

    /**
     * What the file's own bytes say it is, or null when they say nothing.
     *
     * The distinction is the whole of the check below: a definite answer
     * overrules the sender, and only genuine silence lets the sender's claim
     * and the extension decide.
     */
    private function sniff(InboundAttachment $attachment): ?string
    {
        if (str_starts_with($attachment->content, '%PDF-')) {
            return 'application/pdf';
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $sniffed = @finfo_buffer($finfo, $attachment->content) ?: null;
        finfo_close($finfo);

        if (! is_string($sniffed) || $sniffed === '' || $sniffed === 'application/octet-stream') {
            return null;
        }

        return strtolower($sniffed);
    }

    private function isAllowedType(InboundAttachment $attachment): bool
    {
        /*
         | A file that says what it is settles it. This is the case the check
         | exists for: a Windows executable posted as `certificate.pdf` with a
         | Content-Type of `application/pdf` is refused on its first two bytes,
         | and neither the name nor the header it arrived under gets a vote.
         */
        $sniffed = $this->sniff($attachment);

        if ($sniffed !== null) {
            return $this->matchesAllowList($sniffed);
        }

        if ($this->matchesAllowList(strtolower((string) $attachment->contentType))) {
            return true;
        }

        // Nothing but `application/octet-stream` and a name: some clients send
        // every attachment that way, and the extension is the only evidence
        // left.
        return in_array(
            $attachment->extension(),
            (array) config('coi_insurance.attachments.allowed_extensions', []),
            true,
        );
    }

    private function matchesAllowList(string $type): bool
    {
        foreach ((array) config('coi_insurance.attachments.allowed_types', []) as $pattern) {
            if ($type !== '' && fnmatch(strtolower((string) $pattern), $type)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeDocument(InboundAttachment $attachment): bool
    {
        return $attachment->extension() === 'pdf'
            || str_starts_with($attachment->content, '%PDF-');
    }

    /**
     * The name the broker sees: the agency's, stripped of any path it carried.
     */
    private function safeFilename(string $filename): string
    {
        $name = trim(basename(str_replace('\\', '/', $filename)));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? $name;

        return Str::limit($name === '' ? 'attachment' : $name, 180, '');
    }

    private function extensionFor(InboundAttachment $attachment): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', $attachment->extension()) ?? '';

        if ($extension !== '') {
            return '.'.$extension;
        }

        return $this->contentTypeFor($attachment) === 'application/pdf' ? '.pdf' : '';
    }
}
