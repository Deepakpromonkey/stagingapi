<?php

namespace Tests\Feature;

use App\Models\CoiInsuranceRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The certificate itself, from the agency's mail to the broker's screen.
 *
 * The extracted expiry date is a machine reading of a document; this is the
 * document. A broker about to book a load on that date needs to be able to open
 * the PDF the date came out of, which means it has to survive the webhook, the
 * disk, the API and the company scoping — and the last of those is the one that
 * matters most, because these are somebody's insurance certificates.
 */
class CoiReplyAttachmentTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private const PDF = "%PDF-1.4\nCERTIFICATE OF LIABILITY INSURANCE\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['coi_insurance.webhook.secret' => 'test-secret']);
    }

    private function company(string $name = 'Northwind Logistics'): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => $name,
            'status' => true,
        ]);
    }

    private function brokerUser(Company $company): User
    {
        return User::create([
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
    }

    private function insuranceRequest(Company $company, User $user): CoiInsuranceRequest
    {
        return CoiInsuranceRequest::create([
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
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function deliverReply(CoiInsuranceRequest $request, array $attachments): void
    {
        $this->postJson(
            '/api/v1/webhooks/inbound-email?secret=test-secret',
            [
                'FromFull' => ['Email' => 'lortega@dswig.com', 'Name' => 'Luis Ortega'],
                'ToFull' => [['Email' => $request->replyToAddress()]],
                'Subject' => 'RE: '.$request->subject,
                'TextBody' => 'Certificate attached. Expires 04/30/2026.',
                'Attachments' => $attachments,
            ],
        )->assertOk();
    }

    public function test_a_certificate_on_the_reply_is_stored_and_listed(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        $this->deliverReply($request, [[
            'Name' => 'COI Copper Canyon.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode(self::PDF),
        ]]);

        $response = $request->fresh()->responses()->with('attachments')->first();
        $attachment = $response->attachments->first();

        $this->assertNotNull($attachment, 'The reply arrived with a PDF and none was stored.');
        $this->assertSame('COI Copper Canyon.pdf', $attachment->filename);
        $this->assertSame('application/pdf', $attachment->content_type);
        $this->assertSame(strlen(self::PDF), $attachment->size_bytes);

        // The stored name is ours, never the sender's — a filename is the one
        // part of this a stranger controls.
        $this->assertStringContainsString($attachment->uuid, $attachment->path);
        Storage::disk('s3')->assertExists($attachment->path);

        Sanctum::actingAs($user);

        $listed = $this->getJson("/api/v1/carrier-insurance-requests/responses/{$response->uuid}")
            ->assertOk()
            ->json('data.attachments');

        $this->assertCount(1, $listed);
        $this->assertTrue($listed[0]['previewable']);
        $this->assertSame(
            '/carrier-insurance-requests/attachments/'.$attachment->uuid,
            $listed[0]['path'],
        );

        // And on the thread, hanging off the message it arrived with.
        $thread = $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
            ->assertOk()
            ->json('data.messages');

        $this->assertSame([], $thread[0]['attachments']);
        $this->assertCount(1, $thread[1]['attachments']);
    }

    public function test_the_broker_can_download_the_bytes_that_arrived(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        $this->deliverReply($request, [[
            'Name' => 'cert.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode(self::PDF),
        ]]);

        $attachment = $request->fresh()->responses()->first()->attachments()->first();

        Sanctum::actingAs($user);

        $download = $this->get("/api/v1/carrier-insurance-requests/attachments/{$attachment->uuid}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Inline, because the card shows the certificate in place rather than
        // dropping it in a downloads folder.
        $this->assertStringStartsWith('inline;', $download->headers->get('Content-Disposition'));
        $this->assertSame(self::PDF, $download->streamedContent());
    }

    public function test_another_company_cannot_read_the_certificate(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        $this->deliverReply($request, [[
            'Name' => 'cert.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode(self::PDF),
        ]]);

        $attachment = $request->fresh()->responses()->first()->attachments()->first();

        // A logged-in broker at a different brokerage, with the uuid in hand.
        Sanctum::actingAs($this->brokerUser($this->company('Southbound Freight')));

        $this->get("/api/v1/carrier-insurance-requests/attachments/{$attachment->uuid}")
            ->assertNotFound();
    }

    public function test_a_signature_logo_is_not_listed_as_a_certificate(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        $this->deliverReply($request, [
            [
                'Name' => 'logo.png',
                'ContentType' => 'image/png',
                'Content' => base64_encode('pretend png'),
                'ContentID' => '<logo@agency>',
            ],
            [
                'Name' => 'cert.pdf',
                'ContentType' => 'application/pdf',
                'Content' => base64_encode(self::PDF),
            ],
        ]);

        $attachments = $request->fresh()->responses()->first()->attachments;

        $this->assertCount(1, $attachments);
        $this->assertSame('cert.pdf', $attachments->first()->filename);
    }

    public function test_an_executable_disguised_as_a_certificate_is_refused(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        /*
         | The inbox address rides on every request that goes out, so anyone can
         | mail it anything. The sender's Content-Type is a claim, not evidence
         | — what the file actually is decides whether it is kept.
         */
        $this->deliverReply($request, [[
            'Name' => 'certificate.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode("MZ\x90\x00\x03".str_repeat("\x00", 64)),
        ]]);

        $this->assertCount(0, $request->fresh()->responses()->first()->attachments);
    }

    public function test_the_reply_survives_an_attachment_that_cannot_be_stored(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        config(['coi_insurance.attachments.max_bytes' => 8]);

        $this->deliverReply($request, [[
            'Name' => 'huge.pdf',
            'ContentType' => 'application/pdf',
            'Content' => base64_encode(self::PDF),
        ]]);

        $response = $request->fresh()->responses()->first();

        // The prose is the fallback answer, and losing it because a file was
        // oversized would be the worse of the two failures.
        $this->assertNotNull($response);
        $this->assertStringContainsString('Expires 04/30/2026', $response->body_text);
        $this->assertCount(0, $response->attachments);
    }
}
