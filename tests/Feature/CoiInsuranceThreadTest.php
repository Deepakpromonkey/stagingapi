<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The Track button's thread: the whole correspondence for one COI request,
 * plus the path the request took to get where it is.
 *
 * The case worth protecting is the unanswered request. That is the one a
 * broker opens Track for — "who did we mail, and how long ago?" — and it has
 * no reply row to hang the answer off, so it has to come from the request.
 */
class CoiInsuranceThreadTest extends TestCase
{
    // Both traits define migrateFreshUsing(); ours keeps the MySQL-only
    // migrations out of the sqlite run.
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

    private function insuranceRequest(Company $company, User $user, array $attributes = []): CoiInsuranceRequest
    {
        return CoiInsuranceRequest::create(array_merge([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'dot_number' => 3477865,
            'carrier_name' => 'Copper Canyon Trucking Inc',
            'carrier_mc' => '1250903',
            'recipient_email' => 'lortega@dswig.com',
            'recipient_source' => 'ocr',
            'status' => CoiInsuranceRequest::STATUS_PENDING,
            'subject' => CoiInsuranceRequest::buildSubject('Copper Canyon Trucking Inc', 3477865),
            'sent_at' => now()->subDays(9),
        ], $attributes));
    }

    public function test_an_unanswered_request_still_has_a_thread_and_a_path(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);
        $request = $this->insuranceRequest($company, $user);

        Sanctum::actingAs($user);

        $body = $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
            ->assertOk()
            ->json('data');

        // The outbound mail is the thread on its own until the agency answers.
        $this->assertCount(1, $body['messages']);
        $this->assertSame('outbound', $body['messages'][0]['direction']);
        $this->assertSame('lortega@dswig.com', $body['messages'][0]['to_email']);

        // Sent, and waiting: the first step is reached, the second is not.
        $this->assertSame('REQUEST_SENT', $body['state_path'][0]['state']);
        $this->assertTrue($body['state_path'][0]['reached']);
        $this->assertSame('REPLY_RECEIVED', $body['state_path'][1]['state']);
        $this->assertFalse($body['state_path'][1]['reached']);
    }

    public function test_replies_are_returned_oldest_first_with_the_extracted_date(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);

        $request = $this->insuranceRequest($company, $user, [
            'status' => CoiInsuranceRequest::STATUS_SUCCESS,
            'insurance_expiry_date' => '2027-04-30',
            'responded_at' => now()->subDay(),
            'resolved_at' => now(),
        ]);

        // Inserted newest first on purpose: the endpoint orders them, and a
        // thread that reads backwards is worse than no thread at all.
        CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'lortega@dswig.com',
            'from_name' => 'Luis Ortega',
            'subject' => 'RE: Insurance details',
            'body_text' => 'Policy expires 04/30/2027.',
            'extracted_expiry_date' => '2027-04-30',
            'received_at' => now()->subHours(2),
        ]);

        CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'lortega@dswig.com',
            'from_name' => 'Luis Ortega',
            'subject' => 'RE: Insurance details',
            'body_text' => 'Pulling the certificate now.',
            'received_at' => now()->subHours(6),
        ]);

        Sanctum::actingAs($user);

        $body = $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
            ->assertOk()
            ->json('data');

        // Outbound first, then the two replies oldest to newest.
        $this->assertCount(3, $body['messages']);
        $this->assertSame('outbound', $body['messages'][0]['direction']);
        $this->assertSame('Pulling the certificate now.', $body['messages'][1]['body_text']);
        $this->assertSame('Policy expires 04/30/2027.', $body['messages'][2]['body_text']);
        $this->assertSame('2027-04-30', $body['messages'][2]['extracted_expiry_date']);

        // Every step reached, ending on the date being read.
        $states = array_column($body['state_path'], 'state');
        $this->assertSame(
            ['REQUEST_SENT', 'REPLY_RECEIVED', 'READING_REPLY', 'VERIFIED'],
            $states
        );
        $this->assertTrue($body['state_path'][3]['reached']);
    }

    public function test_a_silent_request_ends_the_path_at_no_response(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);

        $request = $this->insuranceRequest($company, $user, [
            'status' => CoiInsuranceRequest::STATUS_EXPIRED,
            'resolved_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $states = array_column(
            $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
                ->assertOk()
                ->json('data.state_path'),
            'state'
        );

        // No dangling "reading the reply" step on a request nobody answered.
        $this->assertSame(['REQUEST_SENT', 'REPLY_RECEIVED', 'NO_RESPONSE'], $states);
    }

    public function test_a_reply_without_a_date_does_not_read_as_undelivered(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);

        // The agency wrote back saying the policy is gone. Delivered, replied,
        // resolved as failed — which is not the same as never arriving.
        $request = $this->insuranceRequest($company, $user, [
            'status' => CoiInsuranceRequest::STATUS_FAILED,
            'last_error' => 'The reply did not state an insurance expiry date.',
            'responded_at' => now()->subMinutes(5),
            'resolved_at' => now(),
        ]);

        CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'lortega@dswig.com',
            'body_text' => 'That policy cancelled on 03/01 for non-payment.',
            'received_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($user);

        $path = $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
            ->assertOk()
            ->json('data.state_path');

        $this->assertSame(
            ['REQUEST_SENT', 'REPLY_RECEIVED', 'NO_DATE_IN_REPLY'],
            array_column($path, 'state')
        );
    }

    public function test_a_send_failure_with_no_reply_reads_as_undelivered(): void
    {
        $company = $this->company();
        $user = $this->brokerUser($company);

        $request = $this->insuranceRequest($company, $user, [
            'status' => CoiInsuranceRequest::STATUS_FAILED,
            'last_error' => 'SMTP: mailbox unavailable.',
            'resolved_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $path = $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
            ->assertOk()
            ->json('data.state_path');

        $this->assertSame(
            ['REQUEST_SENT', 'REPLY_RECEIVED', 'FAILED'],
            array_column($path, 'state')
        );
    }

    public function test_a_thread_is_not_readable_from_another_company(): void
    {
        $owner = $this->company('Northwind Logistics');
        $request = $this->insuranceRequest($owner, $this->brokerUser($owner));

        $outsider = $this->brokerUser($this->company('Warrior Trucking'));

        Sanctum::actingAs($outsider);

        // 404 rather than 403: the uuid of another company's request should
        // not be confirmable as a real one.
        $this->getJson("/api/v1/carrier-insurance-requests/{$request->uuid}/thread")
            ->assertNotFound();
    }
}
