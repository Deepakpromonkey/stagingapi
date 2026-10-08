<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One operational alert - the same facts the Teams card shows.
 *
 * Sent inline, never queued: an alert that waits in the database queue is no
 * use when the database is what went down.
 */
class OpsAlertMail extends Mailable
{
    /**
     * @param  array<string, scalar|null>  $facts
     */
    public function __construct(
        public string $headline,
        public string $level,
        public array $facts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[DollarTraq] '.$this->headline);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ops-alert');
    }
}
