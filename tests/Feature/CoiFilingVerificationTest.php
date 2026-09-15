<?php

namespace Tests\Feature;

use App\Models\CoiInsuranceRequest;
use App\Services\Coi\CarrierFilingLookup;
use App\Services\Coi\CoiFilingVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The certificate against the federal filing.
 *
 * No database: the filings are handed in through the lookup interface, which
 * is exactly why that interface exists — the real one reads a live external
 * host, and a suite that queried it would be reading production.
 */
class CoiFilingVerificationTest extends TestCase
{
    private function verifier(array $filings): CoiFilingVerifier
    {
        return new CoiFilingVerifier(new class($filings) implements CarrierFilingLookup
        {
            public function __construct(private array $filings) {}

            public function activeFilings(int $dotNumber): array
            {
                return $this->filings;
            }
        });
    }

    private function request(): CoiInsuranceRequest
    {
        $request = new CoiInsuranceRequest();
        $request->dot_number = 3610477;

        return $request;
    }

    public function test_the_same_insurer_written_two_ways_still_matches(): void
    {
        // A certificate says "Great Northern Casualty Co."; the filing shouts
        // "GREAT NORTHERN CASUALTY COMPANY". Those are one insurer.
        $result = $this->verifier([
            ['insurer' => 'GREAT NORTHERN CASUALTY COMPANY', 'policy_no' => 'GN-77', 'cancels_on' => null],
        ])->verify($this->request(), ['insurer' => 'Great Northern Casualty Co.']);

        $this->assertSame('matches', $result['verdict']);
    }

    public function test_an_explained_move_reads_as_filing_lag_not_a_mismatch(): void
    {
        // Sequence 16: valid certificate, L&I still showing the prior carrier.
        $result = $this->verifier([
            ['insurer' => 'Prior Mutual', 'policy_no' => 'PM-1', 'cancels_on' => null],
        ])->verify($this->request(), [
            'insurer' => 'Great Northern Casualty',
            'signals' => ['insurer_changed', 'filing_lag'],
        ]);

        $this->assertSame('filing_lag', $result['verdict']);

        // Held, with a date to look again rather than a flat refusal.
        $this->assertNotNull($result['recheck_after']);
    }

    public function test_an_unexplained_different_insurer_is_a_mismatch(): void
    {
        $result = $this->verifier([
            ['insurer' => 'Prior Mutual', 'policy_no' => 'PM-1', 'cancels_on' => null],
        ])->verify($this->request(), ['insurer' => 'Somebody Else Casualty']);

        $this->assertSame('insurer_mismatch', $result['verdict']);
    }

    public function test_a_pending_cancellation_outranks_a_matching_insurer(): void
    {
        // Sequence 10's alert: the certificate is fine and the filing is not.
        $result = $this->verifier([
            [
                'insurer' => 'Great Northern Casualty',
                'policy_no' => 'GN-77',
                'cancels_on' => now()->addDays(10)->toDateString(),
            ],
        ])->verify($this->request(), ['insurer' => 'Great Northern Casualty']);

        $this->assertSame('pending_cancellation', $result['verdict']);
        $this->assertSame(now()->addDays(10)->toDateString(), $result['pending_cancellation_on']);
    }

    public function test_a_rescinded_cancellation_stops_outranking_it(): void
    {
        // Sequence 09: the agency has already withdrawn the cancellation.
        $result = $this->verifier([
            [
                'insurer' => 'Desert Southwest',
                'policy_no' => 'NB-CA-7902214',
                'cancels_on' => now()->addDays(10)->toDateString(),
            ],
        ])->verify($this->request(), [
            'insurer' => 'Desert Southwest',
            'signals' => ['cancellation_rescinded'],
        ]);

        $this->assertSame('matches', $result['verdict']);
    }

    public function test_a_past_cancellation_is_not_pending(): void
    {
        $result = $this->verifier([
            [
                'insurer' => 'Great Northern Casualty',
                'policy_no' => 'GN-77',
                'cancels_on' => now()->subYear()->toDateString(),
            ],
        ])->verify($this->request(), ['insurer' => 'Great Northern Casualty']);

        $this->assertSame('matches', $result['verdict']);
    }

    public function test_an_unreachable_filing_database_does_not_fail_the_reply(): void
    {
        // That host has a history of statement timeouts. A certificate in hand
        // is still a certificate in hand.
        $result = $this->verifier([])->verify($this->request(), ['insurer' => 'Anyone']);

        $this->assertSame('not_checked', $result['verdict']);
    }
}
