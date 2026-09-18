<?php

namespace Tests\Unit;

use App\Services\Coi\InboundEmailPayload;
use App\Services\Coi\MimeMessage;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Getting the certificate off the reply.
 *
 * The date in the prose is only ever a summary of the document; the document is
 * the thing a broker is actually asked to trust. Each provider hands it over
 * differently — inline base64, a multipart upload, or a MIME part — and the one
 * that breaks silently is the one nobody wrote a test for.
 */
class CoiInboundAttachmentTest extends TestCase
{
    public function test_it_reads_an_inline_postmark_attachment(): void
    {
        $pdf = "%PDF-1.4\nfake certificate bytes";

        $payload = InboundEmailPayload::fromProviderPayload([
            'FromFull' => ['Email' => 'agent@agency.com'],
            'TextBody' => 'Certificate attached.',
            'Attachments' => [[
                'Name' => 'COI ACME.pdf',
                'ContentType' => 'application/pdf',
                'Content' => base64_encode($pdf),
                'ContentLength' => strlen($pdf),
            ]],
        ]);

        $this->assertCount(1, $payload->attachments);
        $this->assertSame('COI ACME.pdf', $payload->attachments[0]->filename);
        $this->assertSame('application/pdf', $payload->attachments[0]->contentType);
        $this->assertSame($pdf, $payload->attachments[0]->content);
        $this->assertFalse($payload->attachments[0]->isInline());
    }

    public function test_it_marks_a_postmark_signature_image_as_inline(): void
    {
        $payload = InboundEmailPayload::fromProviderPayload([
            'FromFull' => ['Email' => 'agent@agency.com'],
            'TextBody' => 'Certificate attached.',
            'Attachments' => [[
                'Name' => 'logo.png',
                'ContentType' => 'image/png',
                'Content' => base64_encode('not really a png'),
                'ContentID' => '<logo@agency>',
            ]],
        ]);

        // The store drops these: a signature logo listed under "Attachments"
        // buries the one file the broker opened the thread for.
        $this->assertTrue($payload->attachments[0]->isInline());
    }

    public function test_it_reads_a_sendgrid_multipart_upload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'coi');
        file_put_contents($path, '%PDF-1.4 certificate');

        $payload = InboundEmailPayload::fromProviderPayload([
            'from' => 'agent@agency.com',
            'to' => 'insurance+1234567-abc123@inbox.dollartraq.app',
            'text' => 'See attached.',
            'attachment-info' => json_encode([
                'attachment1' => ['filename' => 'certificate.pdf', 'type' => 'application/pdf'],
            ]),
            'attachment1' => new UploadedFile($path, 'upload.bin', 'application/octet-stream', null, true),
        ]);

        $this->assertCount(1, $payload->attachments);

        // The provider's own map names the file; the upload's name is whatever
        // the parser happened to write to disk.
        $this->assertSame('certificate.pdf', $payload->attachments[0]->filename);
        $this->assertSame('%PDF-1.4 certificate', $payload->attachments[0]->content);

        // An UploadedFile cannot be json_encoded, and `raw_payload` is a JSON
        // column — leaving it in there takes the whole reply down on insert.
        $this->assertIsArray($payload->raw['attachment1']);
        $this->assertNotFalse(json_encode($payload->raw));

        @unlink($path);
    }

    public function test_it_reads_an_attachment_out_of_raw_mime(): void
    {
        $pdf = "%PDF-1.4\nbinary\x00bytes\xff";

        $mime = implode("\r\n", [
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Expires 2026-04-30',
            '--frontier',
            'Content-Type: application/pdf; name="cert.pdf"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="cert.pdf"',
            '',
            chunk_split(base64_encode($pdf), 76, "\r\n"),
            '--frontier--',
            '',
        ]);

        $parsed = MimeMessage::parse($mime);

        $this->assertSame('Expires 2026-04-30', $parsed['text']);
        $this->assertCount(1, $parsed['attachments']);
        $this->assertSame('cert.pdf', $parsed['attachments'][0]->filename);

        // Byte-for-byte: the text path trims and re-encodes, and either one
        // corrupts a PDF without saying so.
        $this->assertSame($pdf, $parsed['attachments'][0]->content);
    }

    public function test_it_decodes_an_rfc_5987_filename(): void
    {
        $mime = implode("\r\n", [
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Transfer-Encoding: base64',
            "Content-Disposition: attachment; filename*=UTF-8''certificate%20of%20insurance.pdf",
            '',
            base64_encode('%PDF-1.4'),
            '--frontier--',
            '',
        ]);

        $parsed = MimeMessage::parse($mime);

        $this->assertSame('certificate of insurance.pdf', $parsed['attachments'][0]->filename);
    }

    public function test_a_pdf_without_a_disposition_is_not_read_as_body_text(): void
    {
        $mime = implode("\r\n", [
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'Attached.',
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 no disposition header'),
            '--frontier--',
            '',
        ]);

        $parsed = MimeMessage::parse($mime);

        $this->assertSame('Attached.', $parsed['text']);
        $this->assertCount(1, $parsed['attachments']);
    }

    public function test_it_collects_every_attachment_not_just_the_first(): void
    {
        $part = fn (string $name, string $body) => implode("\r\n", [
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="'.$name.'"',
            '',
            base64_encode($body),
        ]);

        $mime = implode("\r\n", [
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'Certificate and endorsement attached.',
            $part('cert.pdf', '%PDF-1.4 cert'),
            $part('endorsement.pdf', '%PDF-1.4 endorsement'),
            '--frontier--',
            '',
        ]);

        $parsed = MimeMessage::parse($mime);

        $this->assertSame(
            ['cert.pdf', 'endorsement.pdf'],
            array_map(fn ($attachment) => $attachment->filename, $parsed['attachments']),
        );
    }
}
