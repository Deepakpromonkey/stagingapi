<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invitation $invitation;

    public string $temporaryPassword;

    public function __construct(Invitation $invitation, string $temporaryPassword)
    {
        $this->invitation = $invitation;
        $this->temporaryPassword = $temporaryPassword;
    }

    public function build()
    {
        return $this->subject('You are invited to join '.$this->invitation->company->company_name)
            ->view('emails.invitation');
    }
}
