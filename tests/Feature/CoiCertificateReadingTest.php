<?php

namespace Tests\Feature;

use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Models\Company;
use App\Models\User;
use App\Services\Coi\CoiAttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Handing the stored certificate back to the extraction.
 *
 * The caps here are the ones that matter in production: storing a file costs
 * pennies, sending it to the model is paid for per megabyte, and the inbox
 * address rides on every request that goes out - so what gets read is bounded
 * whatever a stranger mails us.
 */
class CoiCertificateReadingTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const PDF = "%PDF-1.4\nCERTIFICATE OF LIABILITY INSURANCE\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function response(): CoiInsuranceResponse
    {
        $company = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);

        $user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam-'.Str::random(6).'@northwind.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'status' => true,
            'is_owner' => true,
        ]);

        $request = CoiInsuranceRequest::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'dot_number' => 3477865,
            'carrier_name' => 'Copper Canyon Trucking Inc',
            'recipient_email' => 'lortega@dswig.com',
            'recipient_source' => 'ocr',
            'status' => CoiInsuranceRequest::STATUS_PENDING,
            'subject' => CoiInsuranceRequest::buildSubject('Copper Canyon Trucking Inc', 3477865),
            'sent_at' => now()->subDay(),
        ]);

        return $request->responses()->create([
            'from_email' => 'lortega@dswig.com',
            'subject' => 'RE: '.$request->subject,
            'body_text' => 'Certificate attached.',
            'received_at' => now(),
        ]);
    }

    private function attach(CoiInsuranceResponse $response, string $filename, string $type, string $contents): void
    {
        $uuid = (string) Str::uuid();
        $path = 'coi-insurance/test/'.$uuid;

        Storage::disk('s3')->put($path, $contents);

        $response->attachments()->create([
            'uuid' => $uuid,
            'filename' => $filename,
            'content_type' => $type,
            'size_bytes' => strlen($contents),
            'disk' => 's3',
            'path' => $path,
            'sha256' => hash('sha256', $contents),
        ]);
    }

    public function test_a_stored_certificate_comes_back_ready_to_send(): void
    {
        $response = $this->response();
        $this->attach($response, 'COI.pdf', 'application/pdf', self::PDF);

        $documents = app(CoiAttachmentStore::class)->documentsFor($response->fresh('attachments'));

        $this->assertCount(1, $documents);
        $this->assertSame('application/pdf', $documents[0]['media_type']);
        $this->assertSame('COI.pdf', $documents[0]['filename']);

        // Byte-for-byte after the round trip through the disk and base64 - a
        // corrupted PDF reads as a refusal, not as an error.
        $this->assertSame(self::PDF, base64_decode($documents[0]['data']));
    }

    public function test_a_type_the_model_cannot_open_is_not_sent(): void
    {
        $response = $this->response();
        $this->attach($response, 'notes.txt', 'text/plain', 'just text');
        $this->attach($response, 'COI.pdf', 'application/pdf', self::PDF);

        $documents = app(CoiAttachmentStore::class)->documentsFor($response->fresh('attachments'));

        // Uploading a type the model will refuse costs exactly as much as one
        // it can read.
        $this->assertCount(1, $documents);
        $this->assertSame('COI.pdf', $documents[0]['filename']);
    }

    public function test_a_scan_is_sent_as_well_as_a_pdf(): void
    {
        $response = $this->response();
        $this->attach($response, 'scan.png', 'image/png', 'fake png bytes');

        $documents = app(CoiAttachmentStore::class)->documentsFor($response->fresh('attachments'));

        // Plenty of agencies send a photographed or faxed certificate.
        $this->assertCount(1, $documents);
        $this->assertSame('image/png', $documents[0]['media_type']);
    }

    public function test_it_stops_at_the_document_cap(): void
    {
        $response = $this->response();

        foreach (range(1, 5) as $n) {
            $this->attach($response, "cert-{$n}.pdf", 'application/pdf', self::PDF." {$n}");
        }

        config(['coi_insurance.attachments.max_documents_read' => 3]);

        $documents = app(CoiAttachmentStore::class)->documentsFor($response->fresh('attachments'));

        $this->assertCount(3, $documents);
    }

    public function test_an_oversized_file_is_left_on_the_disk(): void
    {
        $response = $this->response();
        $this->attach($response, 'huge.pdf', 'application/pdf', self::PDF);

        config(['coi_insurance.attachments.max_read_bytes' => 8]);

        $documents = app(CoiAttachmentStore::class)->documentsFor($response->fresh('attachments'));

        // Still downloadable from the card - just not paid for on every
        // extraction.
        $this->assertSame([], $documents);
    }

    public function test_a_file_missing_from_the_disk_does_not_break_the_reading(): void
    {
        $response = $this->response();
        $this->attach($response, 'COI.pdf', 'application/pdf', self::PDF);

        Storage::disk('s3')->deleteDirectory('coi-insurance');

        $documents = app(CoiAttachmentStore::class)->documentsFor($response->fresh('attachments'));

        // The prose is still a real answer; losing it because a file went
        // missing would be the worse of the two failures.
        $this->assertSame([], $documents);
    }
}
