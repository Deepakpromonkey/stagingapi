<?php

namespace App\Mail;

use App\Models\SubscriptionInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * DollarTraq's own copy of an invoice, with the branded PDF attached.
 *
 * Stripe may also email its record of the same charge — see
 * config('billing.ask_stripe_to_email') for why both are sent.
 */
class SubscriptionInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $pdf  Raw PDF bytes from App\Services\InvoicePdfService.
     */
    public function __construct(
        public SubscriptionInvoice $invoice,
        public string $pdf
    ) {}

    public function build()
    {
        $reference = $this->invoice->number ?: $this->invoice->stripe_invoice_id;

        $subject = $this->invoice->isPaid()
            ? 'Your DollarTraq receipt · '.$reference
            : 'Your DollarTraq invoice · '.$reference;

        return $this->subject($subject)
            ->view('emails.subscription-invoice', [
                'invoice' => $this->invoice,
                'company' => $this->invoice->company,
                'brand' => config('billing.brand'),
                'issuer' => config('billing.issuer'),
                'reference' => $reference,
            ])
            ->attachData($this->pdf, $this->invoice->fileName(), [
                'mime' => 'application/pdf',
            ]);
    }
}
