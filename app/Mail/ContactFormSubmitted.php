<?php

namespace App\Mail;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactFormSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public ContactRequest $contactRequest;

    public function __construct(ContactRequest $contactRequest)
    {
        $this->contactRequest = $contactRequest;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New DollarTraq Lead: ' . $this->contactRequest->company,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact_form', // We will create this view next
        );
    }
}