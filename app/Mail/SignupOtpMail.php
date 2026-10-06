<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Uses the stock login_otp design. There is no company yet at signup, so
 * there is no company template to prefer over it.
 */
class SignupOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;

    public int $minutes;

    public function __construct(string $otp, int $minutes)
    {
        $this->otp = $otp;
        $this->minutes = $minutes;
    }

    public function build()
    {
        $rendered = app(EmailTemplateService::class)->resolve(null, 'login_otp', [
            'otp' => $this->otp,
            'minutes' => $this->minutes,
        ]);

        return $this->subject('Verify your email address')
            ->view('emails.layouts.template', [
                'title' => 'Verify your email address',
                'body' => $rendered['body_html'],
            ]);
    }
}
