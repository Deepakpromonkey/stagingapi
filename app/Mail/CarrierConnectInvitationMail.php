<?php

namespace App\Mail;

use App\Models\CarrierConnectRequest;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation that starts carrier onboarding, in the broker company's
 * carrier_connect template or the stock design.
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
        $connectRequest = $this->connectRequest;

        $rendered = app(EmailTemplateService::class)->resolve($connectRequest->company_id, 'carrier_connect', [
            'carrier_name' => $connectRequest->carrier_legal_name,
            'dot_number' => $connectRequest->carrier_dot_number,
            'company_name' => $connectRequest->company->company_name,
            'sender_name' => $this->brokerName,
            'connect_url' => $this->connectUrl,
            'sent_at' => ($connectRequest->sent_on ?? now())->format('m/d/y'),
            'expires_at' => ($connectRequest->sent_on ?? now())
                ->copy()
                ->addHours((int) config('carrier_connect.request_lifetime_hours', 72))
                ->format('m/d/y h:i A'),
        ]);

        return $this->subject($rendered['subject'])
            ->view('emails.layouts.template', [
                'title' => $rendered['subject'],
                'body' => $rendered['body_html'],
            ]);
    }
}
