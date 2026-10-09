<?php

namespace Tests\Feature;

use App\Models\BrokerAgreementDocument;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierQuestion;
use App\Models\Company;
use App\Models\User;
use App\Services\Carrier\CarrierAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The e-sign step and when an onboarding counts as complete.
 *
 * Signing used to be the last step and completed the onboarding on its own.
 * The wizard now signs before the questions, ELD and bank steps, so signing
 * must only record the signature and the signed copy, and completion has to
 * wait for whichever step settles the last requirement.
 */
class CarrierOnboardingCompletionTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    /**
     * A two-page PDF saved with compressed cross-reference streams, which
     * FPDI's free parser cannot read — the case the wizard's own copy exists
     * for.
     */
    private const COMPRESSED_XREF_PDF =
        'JVBERi0xLjcKJYGBgYEKCjYgMCBvYmoKPDwKL0ZpbHRlciAvRmxhdGVEZWNvZGUKL0xlbmd0aCAx'.
        'MDgKPj4Kc3RyZWFtCnicK+RyCuEyUADBonQufY/UnLLUkszkRF1zA0sLEwsDcwtLBSMThZA0LhDp'.
        'w2UIVmqoYGqgYG5goBCSy2VjYmhmbm5kZgqELkDsam5iZGBuYAYUNTM1MjA2tFMIyeIK0eJyDeEK'.
        '5AIAo7oYcQplbmRzdHJlYW0KZW5kb2JqCgo4IDAgb2JqCjw8Ci9GaWx0ZXIgL0ZsYXRlRGVjb2Rl'.
        'Ci9MZW5ndGggMTA3Cj4+CnN0cmVhbQp4nCvkcgrhMlAAwaJ0Ln2P1Jyy1JLM5ERdS3MTIzMLI1Mz'.
        'CwUjE4WQNC4Q6cNlCFZqqGBqoGBuYKAQkstlY2JoZm5uZGYKhC5A7ArUaGBuYAYUNTM1MjA2slMI'.
        'yeIK0eJyDeEK5AIAousYbwplbmRzdHJlYW0KZW5kb2JqCgo5IDAgb2JqCjw8Ci9GaWx0ZXIgL0Zs'.
        'YXRlRGVjb2RlCi9UeXBlIC9PYmpTdG0KL04gNgovRmlyc3QgMzIKL0xlbmd0aCAzODMKPj4Kc3Ry'.
        'ZWFtCnic1VLfS8MwEH7PX3GP+iBJszZNZQz2o1WQ4dgERfGha8OojETaTOZ/713bbexBfBAfJFyS'.
        'u/vu8l37BSBAQiRgADqEEKKBhgiUFBBDnCQwHDL+8PlugC/yjWkYv6vKBl4QI2CJGNpfGZ+6nfUg'.
        '2WjEThXT3Odbt2FdKQQEPiAWtSt3halhmKVZJkQshFAhmhJCzvCcoiVoEn3MSY13tDjsDWPxQIjB'.
        'GHNZZyruaijfYqO+PsUTsYowsw4b6s4/vktvpV0P+ROfZMT43JWz3Bu4mF1LIVVAJZgPo+dL/By1'.
        'yb37v8O1/Ctnv53w7D9nznrGV7u1b10KBoxP8sZQBvit2X4YXxU546ktXFnZDfDHyo5tUx0C5x1J'.
        'MCSb2mB9pxu+NI3b1QUKiXBtZ7ocm18hO42TxzpBHfdS40/36zdTtFBy072/WXmaqgtQbG7KKp+4'.
        'Papa4FKBROVLUvXYWudJ7a3CrUc25Kle9b+nnMShVFpGSv8xZX2i/AX1ofgYCmVuZHN0cmVhbQpl'.
        'bmRvYmoKCjEwIDAgb2JqCjw8Ci9TaXplIDExCi9Sb290IDIgMCBSCi9JbmZvIDMgMCBSCi9GaWx0'.
        'ZXIgL0ZsYXRlRGVjb2RlCi9UeXBlIC9YUmVmCi9MZW5ndGggNDcKL1cgWyAxIDIgMiBdCi9JbmRl'.
        'eCBbIDAgMTEgXQo+PgpzdHJlYW0KeJwlybEVABAQRMG/dwQyFepDgeo69kkmGaAqGGBkwqRpYv7o'.
        '4oC0H7ngAoH9BAQKZW5kc3RyZWFtCmVuZG9iagoKc3RhcnR4cmVmCjg2MgolJUVPRg==';

    private $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->accounts = Mockery::mock(CarrierAccountService::class);
        $this->app->instance(CarrierAccountService::class, $this->accounts);
    }

    private function connectRequest(string $agreementPdf, array $attributes = []): CarrierConnectRequest
    {
        $company = Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);

        $disk = config('filesystems.default');
        Storage::disk($disk)->put('agreements/agreement.pdf', $agreementPdf);

        $agreement = BrokerAgreementDocument::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'title' => 'Broker carrier agreement',
            'disk' => $disk,
            'file_path' => 'agreements/agreement.pdf',
            'file_name' => 'agreement.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($agreementPdf),
            'is_active' => true,
        ]);

        $request = CarrierConnectRequest::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'carrier_dot_number' => '1234567',
            'carrier_row_id' => '99001',
            'carrier_legal_name' => "Frank's Trucking",
            'carrier_email' => 'frank@franks.test',
            'token' => Str::random(64),
            'status' => CarrierConnectRequest::STATUS_MOBILE_VERIFIED,
            'sent_on' => now(),
            'mobile_verified_at' => now(),
            'agreement_document_id' => $agreement->id,
        ]);

        // Not all of these are mass assignable, so they go in with forceFill.
        $request->forceFill(array_merge([
            'identity_skipped_at' => now(),
            'documents_completed_at' => now(),
        ], $attributes))->save();

        return $request;
    }

    /** A plain two-page PDF FPDI can read. */
    private function classicPdf(): string
    {
        $pdf = new \FPDF;
        $pdf->SetFont('Helvetica', '', 12);

        foreach ([1, 2] as $page) {
            $pdf->AddPage();
            $pdf->Cell(0, 10, "Agreement page {$page}");
        }

        return $pdf->Output('S');
    }

    /** The compressed fixture as qpdf rewrites it, so FPDI can count its pages. */
    private function rewrittenFixture(): string
    {
        $in = tempnam(sys_get_temp_dir(), 'fixture-');
        $out = $in.'-classic.pdf';
        file_put_contents($in, base64_decode(self::COMPRESSED_XREF_PDF));
        exec('qpdf --object-streams=disable '.escapeshellarg($in).' '.escapeshellarg($out));
        $bytes = file_get_contents($out);
        @unlink($in);
        @unlink($out);

        return $bytes;
    }

    private function signaturePng(): UploadedFile
    {
        return UploadedFile::fake()->image('signature.png', 400, 180);
    }

    private function sign(CarrierConnectRequest $request, array $extra = [])
    {
        return $this->post('/api/v1/carrier-connect/esign', array_merge([
            'token' => $request->token,
            'signature' => $this->signaturePng(),
            'page' => 2,
            'x_pct' => 78,
            'y_pct' => 92,
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_signing_stamps_the_original_and_does_not_complete_the_onboarding(): void
    {
        $this->accounts->shouldNotReceive('provisionFor');

        $request = $this->connectRequest($this->classicPdf());

        $this->sign($request)
            ->assertOk()
            ->assertJsonPath('data.signed', true)
            ->assertJsonPath('data.completed', false);

        $request->refresh();

        $this->assertNotSame(CarrierConnectRequest::STATUS_COMPLETED, $request->status);
        $this->assertNotNull($request->signed_agreement_path);

        $signed = Storage::disk($request->signed_agreement_disk)->get($request->signed_agreement_path);

        $this->assertStringStartsWith('%PDF-', $signed);
        $this->assertNotSame($this->classicPdf(), $signed);

        // Re-readable, and still two pages: the signature went onto one of
        // them rather than onto a page of its own.
        $check = new Fpdi;
        $this->assertSame(2, $check->setSourceFile(StreamReader::createByString($signed)));
    }

    public function test_a_pdf_with_compressed_cross_references_is_stamped_on_the_server(): void
    {
        if (! (new \Symfony\Component\Process\ExecutableFinder)->find('qpdf')) {
            $this->markTestSkipped('qpdf is not installed.');
        }

        // What Word and most modern tools write, and what FPDI's free parser
        // cannot read without qpdf rewriting it first.
        $request = $this->connectRequest(base64_decode(self::COMPRESSED_XREF_PDF));

        $this->sign($request)->assertOk();

        $request->refresh();
        $this->assertNotNull($request->signed_agreement_path);

        $signed = Storage::disk($request->signed_agreement_disk)->get($request->signed_agreement_path);

        $this->assertStringStartsWith('%PDF-', $signed);
        // The signature image is embedded, and the pages are all still there.
        $this->assertStringContainsString('/Subtype /Image', $signed);
        $check = new Fpdi;
        $original = new Fpdi;
        $this->assertSame(
            $original->setSourceFile(StreamReader::createByString($this->rewrittenFixture())),
            $check->setSourceFile(StreamReader::createByString($signed))
        );
    }

    public function test_the_wizards_copy_is_kept_when_the_original_cannot_be_stamped(): void
    {
        // qpdf missing or unable to read the file either.
        Process::fake(['*' => Process::result(exitCode: 2)]);

        $request = $this->connectRequest(base64_decode(self::COMPRESSED_XREF_PDF));

        $browserCopy = UploadedFile::fake()->createWithContent('signed.pdf', '%PDF-1.7 stamped in the browser');

        $this->sign($request, ['signed_agreement' => $browserCopy])->assertOk();

        $request->refresh();

        $this->assertSame(
            '%PDF-1.7 stamped in the browser',
            Storage::disk($request->signed_agreement_disk)->get($request->signed_agreement_path)
        );
    }

    public function test_the_carrier_is_shown_the_signed_copy_afterwards(): void
    {
        $request = $this->connectRequest($this->classicPdf());

        $this->sign($request)->assertOk();

        $request->refresh();

        $served = $this->get('/api/v1/carrier-connect/agreement/'.$request->token)
            ->assertOk()
            ->streamedContent();

        $this->assertSame(
            Storage::disk($request->signed_agreement_disk)->get($request->signed_agreement_path),
            $served
        );
    }

    public function test_the_last_step_completes_the_onboarding_and_provisions_the_account(): void
    {
        $request = $this->connectRequest($this->classicPdf());

        $this->sign($request)->assertJsonPath('data.completed', false);

        // No questions configured, so that step has nothing to wait for.
        $this->accounts->shouldReceive('provisionFor')->once()->andReturnNull();

        $this->postJson('/api/v1/carrier-connect/skip', ['token' => $request->token, 'step' => 'eld'])
            ->assertJsonPath('data.completed', false);

        $this->postJson('/api/v1/carrier-connect/factoring', [
            'token' => $request->token,
            'uses_factoring_company' => '0',
        ])->assertJsonPath('data.completed', false);

        $this->postJson('/api/v1/carrier-connect/skip', ['token' => $request->token, 'step' => 'bank'])
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        $this->assertSame(CarrierConnectRequest::STATUS_COMPLETED, $request->refresh()->status);
    }

    public function test_an_older_wizard_that_signs_last_still_completes_on_signing(): void
    {
        $this->accounts->shouldReceive('provisionFor')->once()->andReturnNull();

        $request = $this->connectRequest($this->classicPdf(), [
            'factoring_answered_at' => now(),
            'uses_factoring_company' => false,
            'bank_skipped_at' => now(),
            'questionnaire_completed_at' => now(),
        ]);

        $this->sign($request)->assertOk()->assertJsonPath('data.completed', true);
    }

    public function test_revisiting_a_step_does_not_reopen_a_completed_onboarding(): void
    {
        $this->accounts->shouldNotReceive('provisionFor');

        $request = $this->connectRequest($this->classicPdf(), [
            'status' => CarrierConnectRequest::STATUS_COMPLETED,
            'signed_at' => now(),
        ]);

        $user = User::create([
            'uuid' => Str::uuid(),
            'company_id' => $request->company_id,
            'first_name' => 'Sam',
            'last_name' => 'Okafor',
            'email' => 'sam@northwind.test',
            'password' => 'secret-password',
            'status' => true,
        ]);

        $question = CarrierQuestion::create([
            'company_id' => $request->company_id,
            'user_id' => $user->id,
            'question' => 'Do you haul hazmat?',
            'answer_type' => 'Yes / No',
            'is_required' => true,
        ]);

        $this->postJson('/api/v1/carrier-connect/answers', [
            'token' => $request->token,
            'answers' => [$question->id => ['question_id' => $question->id, 'answer' => 'No']],
        ])->assertOk();

        $this->assertSame(CarrierConnectRequest::STATUS_COMPLETED, $request->refresh()->status);
    }
}
