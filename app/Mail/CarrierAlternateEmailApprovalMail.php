<?php

namespace App\Mail;

use App\Models\CarrierConnectRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the carrier's FMCSA-registered address when a broker asks for the
 * onboarding invitation to go to a different inbox.
 *
 * This is a consent request, not an invitation: it carries no onboarding link,
 * only the approval link. Until the carrier follows it, the alternate address
 * has been sent nothing at all.
 */
class CarrierAlternateEmailApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public CarrierConnectRequest $connectRequest;

    public string $approvalUrl;

    public string $brokerName;

    public string $requestedEmail;

    public function __construct(
        CarrierConnectRequest $connectRequest,
        string $approvalUrl,
        string $brokerName,
        string $requestedEmail
    ) {
        $this->connectRequest = $connectRequest;
        $this->approvalUrl = $approvalUrl;
        $this->brokerName = $brokerName;
        $this->requestedEmail = $requestedEmail;
    }

    public function build()
    {
        $companyName = $this->connectRequest->company->company_name;

        return $this->subject('Approve a different email address for '.$companyName.' onboarding')
            ->view('emails.carrier-alternate-email-approval');
    }
}
