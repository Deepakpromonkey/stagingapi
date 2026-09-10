<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EnterpriseInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{contact_name: ?string, contact_email: ?string, contact_phone: ?string, monthly_loads: ?int, message: ?string}  $details
     */
    public function __construct(
        public Company $company,
        public array $details
    ) {}

    public function build()
    {
        return $this->subject('Enterprise plan enquiry — '.$this->company->company_name)
            ->replyTo(
                $this->details['contact_email'] ?: $this->company->company_email,
                $this->details['contact_name'] ?: $this->company->company_name
            )
            ->view('emails.enterprise-inquiry');
    }
}
