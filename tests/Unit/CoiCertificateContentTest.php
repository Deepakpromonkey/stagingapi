<?php

namespace Tests\Unit;

use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlockParam;
use App\Services\Coi\InsuranceExpiryExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * How a reply is handed to the model once a certificate rides along with it.
 *
 * The call itself is not exercised here. What matters is the shape: the
 * document has to be its own content block, ahead of the prose, and a scan has
 * to go as an image rather than as a PDF that will not open.
 */
class CoiCertificateContentTest extends TestCase
{
    /**
     * @param  array<int, array<string, string>>  $documents
     * @return array<int, mixed>
     */
    private function content(string $body, array $documents): array
    {
        $method = new ReflectionMethod(InsuranceExpiryExtractor::class, 'contentFor');

        return $method->invoke(
            (new \ReflectionClass(InsuranceExpiryExtractor::class))->newInstanceWithoutConstructor(),
            $body,
            $documents,
        );
    }

    public function test_the_certificate_goes_ahead_of_the_prose(): void
    {
        $content = $this->content('Certificate attached.', [[
            'data' => base64_encode('%PDF-1.4 certificate'),
            'media_type' => 'application/pdf',
            'filename' => 'ACME COI 2026.pdf',
        ]]);

        $this->assertCount(2, $content);

        // Order is the documented one and it is not cosmetic: a document placed
        // after the text that refers to it is read less reliably.
        $this->assertInstanceOf(DocumentBlockParam::class, $content[0]);
        $this->assertInstanceOf(TextBlockParam::class, $content[1]);

        // The agency's own filename travels with it, so a reply carrying a
        // certificate and an endorsement can be told apart.
        $this->assertSame('ACME COI 2026.pdf', $content[0]->title);
        $this->assertSame('Certificate attached.', $content[1]->text);
    }

    public function test_a_scanned_certificate_goes_as_an_image(): void
    {
        $content = $this->content('See attached.', [[
            'data' => base64_encode('fake png bytes'),
            'media_type' => 'image/png',
            'filename' => 'scan.png',
        ]]);

        // A PDF block pointed at a PNG is rejected by the API, so the branch
        // matters more than it looks.
        $this->assertInstanceOf(ImageBlockParam::class, $content[0]);
        $this->assertInstanceOf(TextBlockParam::class, $content[1]);
    }

    public function test_every_attached_document_is_sent(): void
    {
        $content = $this->content('Certificate and endorsement attached.', [
            ['data' => base64_encode('%PDF-1.4 cert'), 'media_type' => 'application/pdf', 'filename' => 'cert.pdf'],
            ['data' => base64_encode('%PDF-1.4 endorsement'), 'media_type' => 'application/pdf', 'filename' => 'endorsement.pdf'],
        ]);

        $this->assertCount(3, $content);
        $this->assertSame('cert.pdf', $content[0]->title);
        $this->assertSame('endorsement.pdf', $content[1]->title);
        $this->assertInstanceOf(TextBlockParam::class, $content[2]);
    }

    public function test_an_empty_document_is_skipped_rather_than_sent(): void
    {
        $content = $this->content('Attached.', [
            ['data' => '', 'media_type' => 'application/pdf', 'filename' => 'empty.pdf'],
        ]);

        // A document block with no data is a 400 for the whole request, which
        // would lose the prose as well.
        $this->assertCount(1, $content);
        $this->assertInstanceOf(TextBlockParam::class, $content[0]);
    }

    public function test_the_reading_records_where_it_was_read_from(): void
    {
        $method = new ReflectionMethod(InsuranceExpiryExtractor::class, 'parseDetails');

        $details = $method->invoke(
            (new \ReflectionClass(InsuranceExpiryExtractor::class))->newInstanceWithoutConstructor(),
            json_encode(['expiry_date' => '2027-04-30', 'read_from' => 'certificate']),
        );

        // Shown to the broker: a date taken off the certificate and a date
        // taken off a covering note are not worth the same.
        $this->assertSame('certificate', $details['read_from']);
    }
}
