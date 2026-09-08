<?php

namespace App\Services\Carrier;

use App\Models\CarrierConnectRequest;
use App\Models\CarrierUser;
use Illuminate\Database\Eloquent\Collection;

/**
 * The brokers a carrier is connected to.
 *
 * An onboarding is raised against the carrier as a business, not against one
 * login, so this is scoped to the whole carrier company: a dispatcher invited
 * last week sees the same broker list as the owner who signed the agreement
 * two years ago.
 */
class CarrierConnectionService
{
    /**
     * Every onboarding that belongs to this carrier, newest first.
     *
     * Matched two ways: onboardings already tied to one of the carrier's
     * logins, and — for onboardings raised before the portal account existed,
     * or still in progress — anything carrying the same DOT number.
     */
    public function forCarrier(CarrierUser $carrierUser): Collection
    {
        $company = $carrierUser->carrierCompany;

        // A login with no company behind it can still only see its own.
        if (! $company) {
            return CarrierConnectRequest::with(['company', 'user'])
                ->where('carrier_user_id', $carrierUser->id)
                ->orderByDesc('id')
                ->get();
        }

        $loginIds = CarrierUser::where('carrier_company_id', $company->id)
            ->pluck('id');

        return CarrierConnectRequest::with(['company', 'user'])
            ->where(function ($query) use ($loginIds, $company) {

                $query->whereIn('carrier_user_id', $loginIds);

                if ($company->dot_number) {
                    $query->orWhere('carrier_dot_number', $company->dot_number);
                }
            })
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Headline counts for the portal's connections screen.
     *
     * @return array{total: int, active: int, in_progress: int}
     */
    public function summarise(Collection $connections): array
    {
        $active = $connections
            ->where('status', CarrierConnectRequest::STATUS_COMPLETED)
            ->count();

        return [
            'total' => $connections->count(),
            'active' => $active,
            'in_progress' => $connections->count() - $active,
        ];
    }
}
