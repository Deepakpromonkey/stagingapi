<?php

namespace App\Mail;

use App\Models\CarrierConnectRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Fallback invitation mail, used when the broker company has not set up a
 * carrier_connect email template of its own.
 */
class CarrierConnectInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public CarrierConnectRequest $connectRequest;

    public string $connectUrl;

    public string $brokerName;

    public function __construct(
        CarrierConnectRequest $connectRequest,
        string $connectUrl,
        string $brokerName
    ) {
        $this->connectRequest = $connectRequest;
        $this->connectUrl = $connectUrl;
        $this->brokerName = $brokerName;
    }

    public function build()
    {
        $companyName = $this->connectRequest->company->company_name;

        return $this->subject($companyName.' would like to connect with you on DollarTraq')
            ->view('emails.carrier-connect-invitation');
    }
}
