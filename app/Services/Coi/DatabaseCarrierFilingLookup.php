<?php

namespace App\Services\Coi;

use App\Models\Carriers\InsuranceFiling;
use Illuminate\Support\Facades\Log;

/**
 * Reads FMCSA filings off the external carrier database.
 *
 * Failure here is not failure of the request. The certificate in hand is still
 * the certificate in hand, and that database has a history of statement
 * timeouts — so an unreachable lookup returns nothing and the verification
 * records that it could not be checked, rather than throwing away a reply.
 */
class DatabaseCarrierFilingLookup implements CarrierFilingLookup
{
    public function activeFilings(int $dotNumber): array
    {
        try {
            return InsuranceFiling::where('dot_number', $dotNumber)
                ->orderByDesc('effective_date')
                ->limit(20)
                ->get()
                ->map(fn (InsuranceFiling $filing) => [
                    'insurer' => $filing->name_company,
                    'policy_no' => $filing->policy_no,
                    'effective_date' => $filing->effective_date?->toDateString(),
                    'cancels_on' => $filing->cancl_effective_date?->toDateString(),
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('COI filing lookup failed', [
                'dot_number' => $dotNumber,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
