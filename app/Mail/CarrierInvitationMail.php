<?php

namespace App\Mail;

use App\Models\CarrierInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CarrierInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CarrierInvitation $invitation,
        public string $temporaryPassword,
        public string $portalUrl
    ) {}

    public function build()
    {
        $carrier = $this->invitation->carrierCompany?->displayName() ?? 'your carrier';

        return $this->subject('You have been added to '.$carrier.' on DollarTraq')
            ->view('emails.carrier-invitation');
    }
}
