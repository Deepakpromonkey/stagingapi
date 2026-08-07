<?php

namespace App\Services;

use App\Models\Carriers\Carrier;
use App\Models\Carriers\CarrierAuthority;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searches the carrier database hosted on EC2 (the `external_db` connection).
 *
 * The response shape mirrors the carrier platform's own search endpoint so the
 * two stay interchangeable for the frontend.
 */
class CarrierSearchService
{
    /**
     * FMCSA fleet-size codes, as used by the carrier platform.
     */
    protected const FLEET_SIZE = [
        'A' => '1',
        'B' => '2-3',
        'C' => '4-6',
        'D' => '7-8',
        'E' => '9-11',
        'F' => '12-14',
        'G' => '15-17',
        'H' => '18-19',
        'I' => '20-23',
        'J' => '24-28',
        'K' => '29-32',
        'L' => '33-38',
        'M' => '39-44',
        'N' => '45-55',
        'O' => '56-75',
        'P' => '76-100',
        'Q' => '101-200',
        'R' => '201-300',
        'S' => '301-400',
        'T' => '401-550',
        'U' => '551-999',
        'V' => '1000-2000',
        'W' => '2001-3000',
        'X' => '3001-4000',
        'Y' => '4001-5000',
        'Z' => 'OVER 5000',
    ];

    public function search(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $filters);

        $direction = ($filters['sort'] ?? null) === 'name_desc' ? 'desc' : 'asc';

        $results = $query
            ->orderBy('legal_name', $direction)
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 10),
                page: (int) ($filters['page'] ?? 1),
            );

        $results->setCollection(
            $results->getCollection()->map(fn (Carrier $carrier) => $this->transform($carrier))
        );

        return $results;
    }

    protected function baseQuery(): Builder
    {
        return Carrier::query()
            ->select([
                'id',
                'row_id',
                'dot_number',
                'legal_name',
                'dba_name',
                'telephone',
                'email_address',
                'phy_street',
                'phy_city',
                'phy_state',
                'phy_zip',
                'mcs150_mileage',
                'nbr_power_unit',
                'driver_total',
                'carrier_operation',
            ])
            ->with([
                'authority:dot_number,docket_number,common_stat,contract_stat,broker_stat',
                'carrierDetail:dot_number,fleetsize,status_code,safety_rating,dun_bradstreet_no',
                'inspections' => fn ($query) => $query->select('dot_number', 'vin')->limit(1),
            ])
            ->withExists('insuranceFilings');
    }

    /**
     * Every filter is independent, so they can be combined.
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['dot_number'])) {
            $query->where('dot_number', $filters['dot_number']);
        }

        // MC (docket) numbers live on carrier_authorities, so resolve to a DOT
        // number first — matching how the carrier platform does it.
        if (! empty($filters['mc_number'])) {
            $dotNumber = CarrierAuthority::where('docket_number', $filters['mc_number'])
                ->value('dot_number');

            $dotNumber
                ? $query->where('dot_number', $dotNumber)
                : $query->whereRaw('1 = 0');
        }

        if (! empty($filters['phone'])) {
            $query->where('telephone', $filters['phone']);
        }

        if (! empty($filters['email'])) {
            $query->where('email_address', $filters['email']);
        }

        if (! empty($filters['company_name'])) {
            $query->where('legal_name', 'like', "%{$filters['company_name']}%");
        }

        // Free text: whichever identifier the broker happened to type.
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);

            $dotNumber = CarrierAuthority::where('docket_number', $term)->value('dot_number');

            $query->where(function (Builder $builder) use ($term, $dotNumber) {
                $builder->where('legal_name', 'like', "%{$term}%")
                    ->orWhere('dba_name', 'like', "%{$term}%")
                    ->orWhere('dot_number', $term)
                    ->orWhere('telephone', $term)
                    ->orWhere('email_address', $term);

                if ($dotNumber) {
                    $builder->orWhere('dot_number', $dotNumber);
                }
            });
        }
    }

    protected function transform(Carrier $carrier): array
    {
        $authority = $carrier->authority;
        $detail = $carrier->carrierDetail;

        return [
            'id' => $carrier->id,
            'row_id' => $carrier->row_id,
            'carrier_operation' => $carrier->carrier_operation,
            'company_name' => $carrier->legal_name,
            'dba_name' => $carrier->dba_name,
            'dot_number' => $carrier->dot_number,
            'mc_number' => $authority?->docket_number,
            'phone' => $carrier->telephone,
            'email' => $carrier->email_address,
            'duns' => $detail?->dun_bradstreet_no,

            'address' => collect([
                $carrier->phy_street,
                $carrier->phy_city,
                $carrier->phy_state,
                $carrier->phy_zip,
            ])->filter()->implode(', '),

            'insurance_current' => (bool) $carrier->insurance_filings_exists,

            'vin' => $carrier->inspections->first()?->vin,

            'mileage' => $carrier->mcs150_mileage,

            'fleet_size' => self::FLEET_SIZE[$detail?->fleetsize] ?? null,

            'drivers' => $carrier->driver_total,

            'is_broker' => $authority?->broker_stat === 'ACTIVE',

            'active_authority' => $detail?->status_code,

            'authority_verified' => $authority?->common_stat === 'ACTIVE'
                || $authority?->contract_stat === 'ACTIVE'
                || $authority?->broker_stat === 'ACTIVE',

            'risk_level' => $detail?->safety_rating,
        ];
    }
}
