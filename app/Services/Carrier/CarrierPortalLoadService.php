<?php

namespace App\Services\Carrier;

use App\Models\CarrierUser;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Collection;

/**
 * The loads a carrier has been put on, as the carrier sees them.
 *
 * A shipment holds no foreign key to a carrier account — the broker types the
 * carrier's details in when they build the load, so the only link back is the
 * DOT number snapshotted onto the row. Matching on that alone would mean a
 * broker's typo could hand one carrier's portal a stranger's load, so the
 * search is also fenced to the brokers this carrier has actually onboarded
 * with. That is what the page has always claimed to show: "loads tendered to
 * you across all connected brokers".
 *
 * Drafts never appear. A draft is a load the broker is still building; it has
 * not been tendered to anybody yet.
 */
class CarrierPortalLoadService
{
    /** Broker-side statuses a carrier is allowed to see. */
    public const VISIBLE_STATUSES = ['active', 'completed', 'cancelled'];

    public function __construct(
        protected CarrierConnectionService $carrierConnectionService
    ) {}

    /**
     * @return Collection<int, Shipment>
     */
    public function forCarrier(CarrierUser $carrierUser): Collection
    {
        $dotNumber = trim((string) ($carrierUser->carrierCompany?->dot_number ?: $carrierUser->dot_number));

        $brokerCompanyIds = $this->brokerCompanyIds($carrierUser);

        // No DOT to match on, or no broker connections to match within: there
        // is nothing this carrier can legitimately be shown.
        if ($dotNumber === '' || $brokerCompanyIds->isEmpty()) {
            return new Collection;
        }

        return Shipment::with(['stops', 'company'])
            ->whereIn('company_id', $brokerCompanyIds)
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->where('carrier_dot', $dotNumber)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One load, or null when it is not this carrier's to see.
     */
    public function find(CarrierUser $carrierUser, string $uuid): ?Shipment
    {
        return $this->forCarrier($carrierUser)->firstWhere('uuid', $uuid);
    }

    /**
     * Counts for the page header, over the same scoped set.
     */
    public function summarise(Collection $loads): array
    {
        return [
            'total' => $loads->count(),
            'active' => $loads->where('status', 'active')->count(),
            'completed' => $loads->where('status', 'completed')->count(),
            'cancelled' => $loads->where('status', 'cancelled')->count(),
        ];
    }

    /**
     * The broker companies behind this carrier's connections.
     */
    protected function brokerCompanyIds(CarrierUser $carrierUser)
    {
        return $this->carrierConnectionService
            ->forCarrier($carrierUser)
            ->pluck('company_id')
            ->filter()
            ->unique()
            ->values();
    }
}
