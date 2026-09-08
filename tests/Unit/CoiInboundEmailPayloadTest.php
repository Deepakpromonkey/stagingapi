<?php

namespace Tests\Unit;

use App\Services\Coi\InboundEmailPayload;
use App\Services\Coi\MimeMessage;
use PHPUnit\Framework\TestCase;

/**
 * Four providers, one shape. These are the payloads the inbound webhook has to
 * survive, and the differences between them are exactly the sort of thing that
 * only shows up when a reply silently fails to route.
 */
class CoiInboundEmailPayloadTest extends TestCase
{
    public function test_it_reads_a_postmark_delivery(): void
    {
        $payload = InboundEmailPayload::fromProviderPayload([
            'FromFull' => ['Email' => 'Agent@Agency.COM', 'Name' => 'Jane Agent'],
            'ToFull' => [['Email' => 'insurance+1234567-abc123@inbox.dollartraq.app']],
            'Subject' => 'RE: Insurance details of the carrier ACME 1234567',
            'TextBody' => "Policy expires 04/30/2026.\n\nOn Mon you wrote:\n> exp 01/01/2020",
            'StrippedTextReply' => 'Policy expires 04/30/2026.',
        ]);

        // Lowercased, because matching downstream is exact.
        $this->assertSame('agent@agency.com', $payload->fromEmail);
        $this->assertSame(['insurance+1234567-abc123@inbox.dollartraq.app'], $payload->recipients);

        // The quoted thread carries a stale expiry date; the stripped reply is
        // what the model must be given.
        $this->assertSame('Policy expires 04/30/2026.', $payload->bodyForExtraction());
    }

    public function test_it_reads_a_mailgun_route_post(): void
    {
        $payload = InboundEmailPayload::fromProviderPayload([
            'sender' => 'agent@agency.com',
            'from' => '"Jane Agent" <agent@agency.com>',
            'recipient' => 'insurance+1234567-abc123@inbox.dollartraq.app',
            'body-plain' => "Expires 2026-04-30\n> quoted",
            'stripped-text' => 'Expires 2026-04-30',
        ]);

        $this->assertSame('agent@agency.com', $payload->fromEmail);
        $this->assertSame('Jane Agent', $payload->fromName);
        $this->assertSame('Expires 2026-04-30', $payload->bodyForExtraction());
    }

    public function test_it_splits_a_generic_address_list(): void
    {
        $payload = InboundEmailPayload::fromProviderPayload([
            'from' => 'Jane Agent <agent@agency.com>',
            'to' => 'insurance+1234567-abc123@inbox.dollartraq.app, someone@else.com',
            'text' => 'Expiry: April 30, 2026',
        ]);

        $this->assertSame(
            ['insurance+1234567-abc123@inbox.dollartraq.app', 'someone@else.com'],
            $payload->recipients,
        );
    }

    /**
     * SendGrid posts the SMTP envelope as a JSON string. It is the address the
     * mail was really delivered to, and on a reply-all it is the only one still
     * carrying our token.
     */
    public function test_it_reads_sendgrid_inbound_parse(): void
    {
        $payload = InboundEmailPayload::fromProviderPayload([
            'from' => 'Jane Agent <agent@agency.com>',
            'to' => 'Jane Agent <agent@agency.com>, insurance@inbox.dollartraq.app',
            'envelope' => '{"to":["insurance+1234567-abc123@inbox.dollartraq.app"],"from":"agent@agency.com"}',
            'subject' => 'Re: Insurance details of the carrier ACME 1234567',
            'text' => 'Policy runs through 2026-04-30.',
        ]);

        $this->assertSame('agent@agency.com', $payload->fromEmail);

        // The sub-addressed envelope recipient must come first: it is what the
        // service matches the request on.
        $this->assertSame('insurance+1234567-abc123@inbox.dollartraq.app', $payload->recipients[0]);
        $this->assertSame('Policy runs through 2026-04-30.', $payload->bodyForExtraction());
    }

    /**
     * SendGrid's "POST the raw, full MIME message" setting replaces every
     * parsed field with one blob. Easy to tick by accident, and the failure it
     * would otherwise cause is a reply with silently no body.
     */
    public function test_it_reads_sendgrid_raw_mime_mode(): void
    {
        $payload = InboundEmailPayload::fromProviderPayload([
            'envelope' => '{"to":["insurance+1234567-abc123@inbox.dollartraq.app"],"from":"agent@agency.com"}',
            'email' => "From: Jane Agent <agent@agency.com>\r\n"
                ."Subject: Re: Insurance details\r\n"
                ."Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                ."Policy runs through 2026-04-30.\r\n",
        ]);

        $this->assertSame('agent@agency.com', $payload->fromEmail);
        $this->assertSame('Policy runs through 2026-04-30.', $payload->bodyForExtraction());
    }

    public function test_it_reads_ses_mime_out_of_an_sns_envelope(): void
    {
        $mime = "Content-Type: multipart/alternative; boundary=\"BB\"\r\n"
            ."Subject: Re: Insurance details\r\n\r\n"
            ."--BB\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            ."Policy expires 30 April 2026=2E\r\n"
            ."--BB\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            ."<p>Policy expires</p>\r\n--BB--\r\n";

        $payload = InboundEmailPayload::fromProviderPayload([
            'Type' => 'Notification',
            'Message' => json_encode([
                'mail' => [
                    'source' => 'agent@agency.com',
                    'destination' => ['insurance+1234567-abc123@inbox.dollartraq.app'],
                    'commonHeaders' => [
                        'from' => ['Jane Agent <agent@agency.com>'],
                        'to' => ['insurance+1234567-abc123@inbox.dollartraq.app'],
                        'subject' => 'Re: Insurance details of the carrier ACME 1234567',
                    ],
                ],
                'content' => base64_encode($mime),
            ]),
        ]);

        $this->assertSame('agent@agency.com', $payload->fromEmail);
        $this->assertSame(['insurance+1234567-abc123@inbox.dollartraq.app'], $payload->recipients);

        // The =2E is a quoted-printable full stop. Leaving it undecoded is the
        // failure mode that makes a body look fine until a date lands on one.
        $this->assertSame('Policy expires 30 April 2026.', $payload->bodyForExtraction());
    }

    public function test_it_unfolds_a_boundary_split_across_header_lines(): void
    {
        $mime = "Content-Type: multipart/mixed;\r\n\tboundary=\"XY\"\r\n\r\n"
            ."--XY\r\nContent-Type: text/plain\r\n\r\nExpires 2026-04-30\r\n--XY--\r\n";

        $this->assertSame('Expires 2026-04-30', MimeMessage::parse($mime)['text']);
    }
}
