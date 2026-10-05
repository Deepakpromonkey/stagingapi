<?php

namespace App\Mail;

use App\Models\Company;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;

    /**
     * The broker company whose login_otp template to use. Null for the carrier
     * portal, which has no broker company and keeps its own design, since the
     * template's copy is written for the Broker Dashboard.
     */
    public ?int $companyId;

    public ?string $firstName;

    public int $minutes;

    public function __construct(string $otp, ?int $companyId = null, ?string $firstName = null, int $minutes = 10)
    {
        $this->otp = $otp;
        $this->companyId = $companyId;
        $this->firstName = $firstName;
        $this->minutes = $minutes;
    }

    public function build()
    {
        if ($this->companyId === null) {
            return $this->subject('Your Login OTP')
                ->view('emails.login-otp');
        }

        $rendered = app(EmailTemplateService::class)->resolve($this->companyId, 'login_otp', [
            'first_name' => $this->firstName,
            'otp' => $this->otp,
            'minutes' => $this->minutes,
            'company_name' => Company::find($this->companyId)?->company_name,
        ]);

        return $this->subject($rendered['subject'])
            ->view('emails.layouts.template', [
                'title' => $rendered['subject'],
                'body' => $rendered['body_html'],
            ]);
    }
}
