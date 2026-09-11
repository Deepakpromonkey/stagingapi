<?php

namespace Tests\Feature;

use App\Jobs\ExtractInsuranceExpiry;
use App\Models\Company;
use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Models\User;
use App\Services\Coi\CarrierInsuranceRequestService;
use App\Services\Coi\InboundEmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Phase 1 — keeping the conversation alive.
 *
 * Six of the twenty sequences answer the first mail without a certificate:
 * the agency wants the insured's authorization (04), the renewal is still
 * with underwriting (11), the policy is direct with the insurer (17), it asks
 * who the holder is (19), it is an out-of-office (20), or a cancellation is
 * later rescinded (09). In every one of them the certificate arrives in a
 * later mail, and that mail is only read while the request is still open.
 */
class CoiFollowUpRepliesTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
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

    private function request(array $attributes = []): CoiInsuranceRequest
    {
        $company = $this->company();

        return CoiInsuranceRequest::create(array_merge([
            'company_id' => $company->id,
            'user_id' => $this->brokerUser($company)->id,
            'dot_number' => 3623190,
            'carrier_name' => 'J&K Express Trucking Corp',
            'recipient_email' => 'certs@agency.example',
            'recipient_source' => 'ocr',
            'status' => CoiInsuranceRequest::STATUS_PENDING,
            'subject' => CoiInsuranceRequest::buildSubject('J&K Express Trucking Corp', 3623190),
            'sent_at' => now()->subDay(),
        ], $attributes));
    }

    /** A reply arriving at the sub-addressed inbox for the given request. */
    private function replyTo(CoiInsuranceRequest $request, string $body): InboundEmailPayload
    {
        return InboundEmailPayload::fromProviderPayload([
            'FromFull' => ['Email' => 'agent@agency.example', 'Name' => 'Dana Whitaker'],
            'ToFull' => [['Email' => $request->replyToAddress()]],
            'Subject' => 'RE: '.$request->subject,
            'TextBody' => $body,
            'StrippedTextReply' => $body,
        ]);
    }

    public function test_a_reply_without_a_date_leaves_the_request_open(): void
    {
        $request = $this->request([
            'status' => CoiInsuranceRequest::STATUS_RESPONDED,
            'responded_at' => now(),
        ]);

        $response = CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'agent@agency.example',
            'body_text' => 'We need the insured\'s authorization before we can release anything.',
            'received_at' => now(),
        ]);

        // The extractor found nothing, which is what sequence 04's first reply
        // looks like.
        (new ExtractInsuranceExpiry($response->id))->handle(
            new FakeExpiryExtractor(null)
        );

        $request->refresh();

        $this->assertSame(CoiInsuranceRequest::STATUS_AWAITING, $request->status);
        $this->assertTrue($request->isOpen());

        // Still open, so nothing has resolved it.
        $this->assertNull($request->resolved_at);
    }

    public function test_a_follow_up_on_an_awaiting_request_is_read(): void
    {
        Queue::fake();

        $request = $this->request([
            'status' => CoiInsuranceRequest::STATUS_AWAITING,
            'responded_at' => now()->subHour(),
            'last_error' => 'The reply did not state an insurance expiry date.',
        ]);

        // The first reply — the one that asked for authorization and put the
        // request into awaiting.
        CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'agent@agency.example',
            'body_text' => 'We need the insured\'s authorization first.',
            'received_at' => now()->subHour(),
        ]);

        app(CarrierInsuranceRequestService::class)->recordReply(
            $this->replyTo($request, 'Approved by the insured — certificate attached, expiring 09/30/2027.')
        );

        // The whole point: the second mail gets an extraction of its own.
        Queue::assertPushed(ExtractInsuranceExpiry::class);

        $this->assertSame(2, $request->responses()->count());
    }

    public function test_a_follow_up_after_success_is_stored_but_not_re_read(): void
    {
        Queue::fake();

        $request = $this->request([
            'status' => CoiInsuranceRequest::STATUS_SUCCESS,
            'insurance_expiry_date' => '2027-09-30',
            'responded_at' => now()->subHour(),
            'resolved_at' => now()->subHour(),
        ]);

        app(CarrierInsuranceRequestService::class)->recordReply(
            $this->replyTo($request, 'Thanks — let us know if you need anything else.')
        );

        // A pleasantry must not knock a good answer back or spend a call.
        Queue::assertNotPushed(ExtractInsuranceExpiry::class);
        $this->assertSame(CoiInsuranceRequest::STATUS_SUCCESS, $request->fresh()->status);
    }

    public function test_the_sweep_gives_up_on_a_promise_nobody_kept(): void
    {
        $stale = $this->request([
            'status' => CoiInsuranceRequest::STATUS_AWAITING,
            'responded_at' => now()->subDays(20),
        ]);

        // Replied only yesterday — the agency has not gone quiet.
        $recent = $this->request([
            'status' => CoiInsuranceRequest::STATUS_AWAITING,
            'responded_at' => now()->subDay(),
        ]);

        $this->artisan('coi:expire-requests', ['--days' => 14])->assertSuccessful();

        $this->assertSame(CoiInsuranceRequest::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertSame(CoiInsuranceRequest::STATUS_AWAITING, $recent->fresh()->status);
    }

    public function test_the_state_path_stops_at_awaiting(): void
    {
        $request = $this->request([
            'status' => CoiInsuranceRequest::STATUS_AWAITING,
            'responded_at' => now(),
            'last_error' => 'The reply did not state an insurance expiry date.',
        ]);

        CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'agent@agency.example',
            'body_text' => 'Who is the certificate holder?',
            'received_at' => now(),
        ]);

        $this->assertSame(
            ['REQUEST_SENT', 'REPLY_RECEIVED', 'AWAITING_DETAILS', 'VERIFIED'],
            array_column($request->statePath(), 'state')
        );

        // The last step is the one still outstanding.
        $this->assertFalse($request->statePath()[3]['reached']);
    }
}

/** Stands in for the Claude call so the suite neither pays nor flakes. */
class FakeExpiryExtractor extends \App\Services\Coi\InsuranceExpiryExtractor
{
    public function __construct(private readonly ?string $date)
    {
    }

    public function extract(string $body): array
    {
        return [
            'expiry_date' => $this->date ? \Carbon\CarbonImmutable::parse($this->date) : null,
            'raw' => 'Insurance Expiry Date - '.($this->date ?: 'NOT FOUND'),
        ];
    }
}
