<?php

namespace Tests\Feature;

use App\Mail\CarrierInsuranceRequestMail;
use App\Models\Company;
use App\Models\CoiInsuranceRequest;
use App\Models\CoiInsuranceResponse;
use App\Models\User;
use App\Services\Coi\CarrierInsuranceRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * Phase 3 — chasing a silent agency, and writing to the one that can answer.
 *
 * Sequence 05 is silence and a cadence that has to stop. Sequences 06, 17 and
 * 20 are replies whose only content is a different address.
 */
class CoiChaseAndRerouteTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private function request(array $attributes = []): CoiInsuranceRequest
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

        return CoiInsuranceRequest::create(array_merge([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'dot_number' => 3595533,
            'carrier_name' => 'Golden Plains Trucking Co',
            'recipient_email' => 'agent@oldagency.example',
            'recipient_source' => 'ocr',
            'status' => CoiInsuranceRequest::STATUS_PENDING,
            'subject' => CoiInsuranceRequest::buildSubject('Golden Plains Trucking Co', 3595533),
            'sent_at' => now()->subDays(2),
        ], $attributes));
    }

    public function test_a_silent_request_is_chased_then_left_alone(): void
    {
        Mail::fake();

        $request = $this->request();

        // Two chases land, and the third run finds nothing left to do.
        $this->artisan('coi:chase-requests', ['--hours' => 24, '--max' => 2])->assertSuccessful();
        $request->refresh()->forceFill(['last_chase_at' => now()->subDays(2)])->save();

        $this->artisan('coi:chase-requests', ['--hours' => 24, '--max' => 2])->assertSuccessful();
        $request->refresh()->forceFill(['last_chase_at' => now()->subDays(2)])->save();

        $this->artisan('coi:chase-requests', ['--hours' => 24, '--max' => 2])->assertSuccessful();

        $this->assertSame(2, $request->fresh()->chase_count);
        Mail::assertQueued(CarrierInsuranceRequestMail::class, 2);
    }

    public function test_a_request_that_was_answered_is_not_chased(): void
    {
        Mail::fake();

        $request = $this->request(['status' => CoiInsuranceRequest::STATUS_AWAITING]);

        CoiInsuranceResponse::create([
            'coi_insurance_request_id' => $request->id,
            'from_email' => 'agent@oldagency.example',
            'body_text' => 'Waiting on the insured to approve.',
            'received_at' => now()->subHours(30),
        ]);

        $this->artisan('coi:chase-requests', ['--hours' => 24])->assertSuccessful();

        // Chasing here would talk over a conversation already in progress.
        $this->assertSame(0, $request->fresh()->chase_count);
        Mail::assertNothingQueued();
    }

    public function test_an_out_of_office_re_sends_to_the_address_it_names(): void
    {
        Mail::fake();

        // Production behaviour: no test recipient standing in the way.
        config(['coi_insurance.force_recipient' => null]);

        $request = $this->request(['chase_count' => 2, 'last_chase_at' => now()]);

        $rerouted = app(CarrierInsuranceRequestService::class)->rerouteIfAsked($request, [
            'alternate_email' => 'certs@goldenplainsagency.example',
            'signals' => ['out_of_office'],
        ]);

        $this->assertTrue($rerouted);

        $request->refresh();
        $this->assertSame('certs@goldenplainsagency.example', $request->recipient_email);
        $this->assertSame('reply', $request->recipient_source);
        $this->assertSame(CoiInsuranceRequest::STATUS_PENDING, $request->status);

        // The new address has not been asked yet, so its cadence starts over.
        $this->assertSame(0, $request->chase_count);
        $this->assertSame(1, $request->reroute_count);

        Mail::assertQueued(CarrierInsuranceRequestMail::class);
    }

    public function test_a_reroute_on_staging_still_goes_to_the_test_recipient(): void
    {
        Mail::fake();

        // The whole point of the test recipient: the sequences that re-route
        // are the ones whose replies carry a stranger's address.
        config(['coi_insurance.force_recipient' => 'tester@dollartraq.test']);

        $request = $this->request();

        app(CarrierInsuranceRequestService::class)->rerouteIfAsked($request, [
            'alternate_email' => 'certs@somebodyelse.example',
            'signals' => ['out_of_office'],
        ]);

        $request->refresh();

        $this->assertSame('tester@dollartraq.test', $request->recipient_email);
        $this->assertSame('test:reply', $request->recipient_source);

        // Where it would have gone is still recorded, or a staging run would
        // report that every re-route resolved perfectly.
        $this->assertSame('certs@somebodyelse.example', $request->rerouted_to);
    }

    public function test_an_address_without_an_instruction_is_not_followed(): void
    {
        Mail::fake();
        config(['coi_insurance.force_recipient' => null]);

        $request = $this->request();

        // An agency signing off with its own contact details is not asking to
        // be written to somewhere else.
        $rerouted = app(CarrierInsuranceRequestService::class)->rerouteIfAsked($request, [
            'alternate_email' => 'dana@oldagency.example',
            'signals' => ['renewal_pending'],
        ]);

        $this->assertFalse($rerouted);
        $this->assertSame('agent@oldagency.example', $request->fresh()->recipient_email);
        Mail::assertNothingQueued();
    }

    public function test_two_agencies_pointing_at_each_other_cannot_loop(): void
    {
        Mail::fake();

        $request = $this->request(['reroute_count' => 2]);

        $rerouted = app(CarrierInsuranceRequestService::class)->rerouteIfAsked($request, [
            'alternate_email' => 'someone@third.example',
            'signals' => ['wrong_agency'],
        ]);

        $this->assertFalse($rerouted);
        Mail::assertNothingQueued();
    }
}
