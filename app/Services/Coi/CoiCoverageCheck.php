<?php

namespace App\Services\Coi;

use App\Models\CoiInsuranceRequest;

/**
 * Two questions asked at tender time, answered from the certificate on file.
 *
 * Is this truck on the policy (02, 08), and is this load's commodity actually
 * covered for what it is worth (18). Both are questions about one load, and
 * neither should touch the carrier's own verification status: a seafood load
 * that exceeds a commodity sub-limit is a bad load for this carrier today, not
 * a bad carrier.
 */
class CoiCoverageCheck
{
    /** The most recent answered request for a DOT within a company. */
    private function latestCoverage(int $companyId, int $dotNumber): ?CoiInsuranceRequest
    {
        return CoiInsuranceRequest::where('company_id', $companyId)
            ->where('dot_number', $dotNumber)
            ->where('status', CoiInsuranceRequest::STATUS_SUCCESS)
            ->whereNotNull('coverage')
            ->latest('resolved_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function unitScheduled(int $companyId, int $dotNumber, string $vin): array
    {
        $request = $this->latestCoverage($companyId, $dotNumber);
        $scheduled = $request?->coverage['scheduled_vins'] ?? [];

        if ($request === null || $scheduled === []) {
            /*
             | No schedule is not the same as "not on the schedule". Plenty of
             | policies are written Any Auto, where every unit is covered and
             | there is no list to be on — answering "not scheduled" there
             | would hold loads that are perfectly insured.
             */
            return [
                'verdict' => 'no_schedule',
                'reason' => 'This policy has no unit schedule on file; it may be written Any Auto.',
                'vin' => $vin,
            ];
        }

        $wanted = $this->normaliseVin($vin);

        foreach ($scheduled as $unit) {
            if ($this->normaliseVin($unit['vin'] ?? '') === $wanted) {
                return [
                    'verdict' => 'scheduled',
                    'reason' => 'This unit is on the scheduled-auto policy.',
                    'vin' => $vin,
                    'description' => $unit['description'] ?? null,
                ];
            }
        }

        return [
            'verdict' => 'not_scheduled',
            'reason' => 'This unit is not on the carrier\'s scheduled-auto policy. '
                .'An endorsement is needed before it hauls.',
            'vin' => $vin,
            'scheduled_count' => count($scheduled),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commodityCovered(int $companyId, int $dotNumber, string $commodity, float $value): array
    {
        $request = $this->latestCoverage($companyId, $dotNumber);

        if ($request === null) {
            return [
                'verdict' => 'unknown',
                'reason' => 'No answered certificate request on file for this carrier.',
            ];
        }

        $coverage = $request->coverage ?? [];
        $cargoLimit = $this->cargoLimit($coverage['coverages'] ?? []);
        $subLimit = $this->subLimitFor($coverage['sub_limits'] ?? [], $commodity);

        // The lower of the two is what actually applies to this load.
        $applies = $subLimit['limit'] ?? $cargoLimit;

        if ($applies === null) {
            return [
                'verdict' => 'unknown',
                'reason' => 'The certificate on file does not state a cargo limit.',
            ];
        }

        if ($value > $applies) {
            return [
                'verdict' => 'under_insured',
                'reason' => $subLimit
                    ? 'A '.$subLimit['commodity'].' sub-limit of $'.number_format((float) $applies)
                        .' applies to this load, below its $'.number_format($value).' value.'
                    : 'The cargo limit of $'.number_format((float) $applies)
                        .' is below this load\'s $'.number_format($value).' value.',
                'limit_applied' => $applies,
                'sub_limit' => $subLimit['commodity'] ?? null,
                'cargo_limit' => $cargoLimit,
                'load_value' => $value,
            ];
        }

        return [
            'verdict' => 'covered',
            'reason' => 'The load value is within the limit that applies to it.',
            'limit_applied' => $applies,
            'sub_limit' => $subLimit['commodity'] ?? null,
            'cargo_limit' => $cargoLimit,
            'load_value' => $value,
        ];
    }

    private function cargoLimit(array $coverages): ?float
    {
        foreach ($coverages as $coverage) {
            if (str_contains(strtolower((string) ($coverage['type'] ?? '')), 'cargo')
                && is_numeric($coverage['limit'] ?? null)) {
                return (float) $coverage['limit'];
            }
        }

        return null;
    }

    /**
     * The sub-limit whose commodity the load's own description contains, or
     * the other way round — "seafood" must match a "fresh seafood" load and a
     * sub-limit written "seafood and shellfish".
     *
     * @return array{commodity: string, limit: float}|null
     */
    private function subLimitFor(array $subLimits, string $commodity): ?array
    {
        $wanted = strtolower(trim($commodity));

        if ($wanted === '') {
            return null;
        }

        foreach ($subLimits as $limit) {
            $named = strtolower(trim((string) ($limit['commodity'] ?? '')));

            if ($named === '' || ! is_numeric($limit['limit'] ?? null)) {
                continue;
            }

            foreach (explode(' ', $named) as $word) {
                if (strlen($word) > 3 && str_contains($wanted, $word)) {
                    return ['commodity' => $limit['commodity'], 'limit' => (float) $limit['limit']];
                }
            }

            if (str_contains($named, $wanted)) {
                return ['commodity' => $limit['commodity'], 'limit' => (float) $limit['limit']];
            }
        }

        return null;
    }

    /** VINs are quoted with spaces and hyphens as often as not. */
    private function normaliseVin(string $vin): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin) ?? $vin);
    }
}
