<?php

namespace Tests\Feature;

use App\Mail\CarrierInsuranceRequestMail;
use App\Models\CoiInsuranceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * What the outbound request actually asks for.
 *
 * The reading can only find what an agency chose to write. Asking for the
 * schedule and the sub-limits is the difference between a feature that works
 * when an agency happens to volunteer them and one that works.
 */
class CoiRequestMailAsksTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    private function rendered(?array $asks = null, ?string $holder = null, ?string $note = null): string
    {
        $request = new CoiInsuranceRequest([
            'dot_number' => 3712558,
            'carrier_name' => 'Delgado Bros Transport Inc',
            'carrier_mc' => '1355901',
            'asks' => $asks ?? array_keys(CoiInsuranceRequest::ASKS),
            'holder_name' => $holder,
            'ask_note' => $note,
        ]);
        $request->subject = CoiInsuranceRequest::buildSubject('Delgado Bros Transport Inc', 3712558);

        return (new CarrierInsuranceRequestMail($request))->render();
    }

    public function test_a_broker_can_ask_for_only_what_they_need(): void
    {
        $body = $this->rendered(['schedule']);

        $this->assertStringContainsString('Scheduled Autos', $body);

        // Nothing they did not ask for.
        $this->assertStringNotContainsString('sub-limits', $body);
        $this->assertStringNotContainsString('policy number', $body);
    }

    public function test_the_holder_ask_names_the_holder(): void
    {
        // Sequence 13: a certificate made out to the wrong name is the
        // agency's error only if the right one was given to them.
        $body = $this->rendered(['holder'], 'Warrior Trucking LLC');

        $this->assertStringContainsString('Warrior Trucking LLC', $body);
    }

    public function test_a_broker_can_add_a_question_of_their_own(): void
    {
        $body = $this->rendered(['expiry'], null, 'Does the cargo form cover frozen seafood?');

        $this->assertStringContainsString('frozen seafood', $body);
    }

    public function test_it_asks_which_kind_of_auto_policy_it_is(): void
    {
        $body = $this->rendered();

        // A broker cannot tell Any Auto from Scheduled Autos by looking at a
        // certificate, and on the second kind an unlisted truck is uninsured.
        $this->assertStringContainsString('Any Auto', $body);
        $this->assertStringContainsString('Scheduled Autos', $body);
        $this->assertStringContainsString('VIN', $body);
    }

    public function test_it_asks_for_the_terms_a_load_is_held_on(): void
    {
        $body = $this->rendered();

        $this->assertStringContainsString('expiry date', $body);
        $this->assertStringContainsString('sub-limits', $body);
        $this->assertStringContainsString('exclusions', $body);
    }

    public function test_it_still_identifies_the_carrier_being_asked_about(): void
    {
        $body = $this->rendered();

        $this->assertStringContainsString('Delgado Bros Transport Inc', $body);
        $this->assertStringContainsString('3712558', $body);
    }
}
