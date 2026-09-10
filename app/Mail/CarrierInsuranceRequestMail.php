<?php

namespace App\Mail;

use App\Models\CoiInsuranceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The mail that goes to the carrier's insurance agency.
 *
 * Plain, short, and signed off by the team rather than by an individual — it is
 * a request to a stranger at an agency, and the reply is parsed by machine, so
 * anything decorative in here only makes the extraction harder.
 */
class CarrierInsuranceRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CoiInsuranceRequest $insuranceRequest) {}

    public function envelope(): Envelope
    {
        $from = config('coi_insurance.inbox.from_address');

        return new Envelope(
            // Falls back to the application from-address, so an install with
            // no dedicated inbox still sends rather than throwing.
            from: $from
                ? new Address($from, config('coi_insurance.inbox.from_name'))
                : null,

            /*
             | The whole routing mechanism. The agency replies to the
             | sub-addressed inbox, the webhook reads the DOT and the token out
             | of the address, and the reply lands back on this row without
             | anyone having to match on subject text.
             */
            replyTo: [new Address(
                $this->insuranceRequest->replyToAddress(),
                config('coi_insurance.inbox.from_name'),
            )],

            subject: $this->insuranceRequest->subject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->insuranceRequest->uuid.'@'.config('coi_insurance.inbox.domain'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.carrier-insurance-request',
            with: ['request' => $this->insuranceRequest],
        );
    }
}
