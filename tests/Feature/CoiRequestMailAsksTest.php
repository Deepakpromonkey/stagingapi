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

    private function rendered(): string
    {
        $request = new CoiInsuranceRequest([
            'dot_number' => 3712558,
            'carrier_name' => 'Delgado Bros Transport Inc',
            'carrier_mc' => '1355901',
        ]);
        $request->subject = CoiInsuranceRequest::buildSubject('Delgado Bros Transport Inc', 3712558);

        return (new CarrierInsuranceRequestMail($request))->render();
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
