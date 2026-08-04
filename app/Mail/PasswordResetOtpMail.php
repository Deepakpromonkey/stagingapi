<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;

    public int $minutes;

    public function __construct(string $otp, int $minutes = 10)
    {
        $this->otp = $otp;
        $this->minutes = $minutes;
    }

    public function build()
    {
        return $this->subject('Your Password Reset Code')
            ->view('emails.password-reset-otp');
    }
}
