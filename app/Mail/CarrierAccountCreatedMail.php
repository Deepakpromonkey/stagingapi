<?php

namespace App\Mail;

use App\Models\CarrierConnectRequest;
use App\Models\CarrierUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CarrierAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CarrierUser $carrierUser,
        public CarrierConnectRequest $connectRequest,
        public string $temporaryPassword,
        public string $portalUrl,
        public string $brokerName
    ) {}

    public function build()
    {
        return $this->subject('Your carrier portal account is ready')
            ->view('emails.carrier-account');
    }
}
