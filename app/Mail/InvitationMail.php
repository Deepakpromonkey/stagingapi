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

    /**
     * Where the invitee goes to choose their own password.
     *
     * This used to be a temporary password printed in the body. Microsoft
     * quarantined those as phishing, and rightly so -- a password plus a
     * "sign in" button is the shape of a credential-harvesting mail, and no
     * amount of SPF/DKIM/DMARC argues a filter out of that. A one-time link
     * carries no credential, so there is nothing to intercept or forward.
     */
    public string $acceptUrl;

    public function __construct(Invitation $invitation, string $acceptUrl)
    {
        $this->invitation = $invitation;
        $this->acceptUrl = $acceptUrl;
    }

    public function build()
    {
        // A text/plain alternative alongside the HTML. Filters score an
        // HTML-only transactional mail worse than a multipart one, and this
        // is the cheapest deliverability win available.
        return $this->subject($this->invitation->company->company_name.' added you to their team')
            ->view('emails.invitation')
            ->text('emails.invitation-text');
    }
}
