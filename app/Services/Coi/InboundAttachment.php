<?php

namespace App\Services\Coi;

/**
 * One file that arrived attached to a reply, already decoded.
 *
 * Providers disagree about everything else, but they all eventually hand over
 * the same three things, so the rest of the feature is written against this
 * rather than against four sets of field names.
 *
 * The bytes are held in memory on purpose: a certificate is a few hundred
 * kilobytes, it is written to the disk within the same request, and a
 * temporary file would only add a path to clean up on every failure branch.
 */
class InboundAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly ?string $contentType,
        public readonly string $content,
        public readonly ?string $contentId = null,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    /**
     * An image referenced by the HTML body rather than sent as a file.
     *
     * A signature logo is not a certificate, and listing one under
     * "Attachments" on the card is noise the broker has to read past.
     */
    public function isInline(): bool
    {
        return $this->contentId !== null && $this->contentId !== '';
    }

    public function extension(): string
    {
        return strtolower((string) pathinfo($this->filename, PATHINFO_EXTENSION));
    }
}
