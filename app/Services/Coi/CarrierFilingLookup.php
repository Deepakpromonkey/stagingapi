<?php

namespace App\Services\Coi;

/**
 * The carrier's insurance filings as FMCSA holds them.
 *
 * Behind an interface for one reason: the filings live on the `external_db`
 * connection, which is a real remote host. A test that queried it directly
 * would be reading production every time the suite ran.
 */
interface CarrierFilingLookup
{
    /**
     * Active filings for a DOT — newest first.
     *
     * Each entry: insurer, policy_no, effective_date, cancels_on (nullable).
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeFilings(int $dotNumber): array;
}
