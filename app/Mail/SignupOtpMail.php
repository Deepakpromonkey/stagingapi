<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
        return $this->subject('Verify your email address')
            ->view('emails.signup-otp');
    }
}
