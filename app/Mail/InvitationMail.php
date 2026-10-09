<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Services\EmailTemplateService;
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
        $invitation = $this->invitation;

        $rendered = app(EmailTemplateService::class)->resolve($invitation->company_id, 'invitation', [
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,
            'email' => $invitation->email,
            'role_name' => $invitation->role?->name,
            'company_name' => $invitation->company->company_name,
            'invited_by' => trim(($invitation->creator?->first_name ?? '').' '.($invitation->creator?->last_name ?? '')),
            'accept_url' => $this->acceptUrl,
            'login_url' => rtrim((string) config('app.frontend_url'), '/').'/login',
            'sent_at' => now()->format('m/d/y'),
            'expires_at' => $invitation->expires_at?->format('m/d/y'),
        ]);

        // A text/plain alternative alongside the HTML. Filters score an
        // HTML-only transactional mail worse than a multipart one, and this
        // is the cheapest deliverability win available.
        return $this->subject($rendered['subject'])
            ->view('emails.layouts.template', [
                'title' => $rendered['subject'],
                'body' => $rendered['body_html'],
            ])
            ->text('emails.invitation-text');
    }
}
