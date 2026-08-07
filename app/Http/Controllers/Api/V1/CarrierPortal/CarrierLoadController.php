<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\CarrierLoadResource;
use App\Services\Carrier\CarrierPortalLoadService;
use Illuminate\Http\Request;

/**
 * "My Loads": the shipments brokers have put this carrier on, and every stop
 * on each of them.
 *
 * Read-only. The load is the broker's record — the carrier sees what they are
 * hauling, not a handle to change it.
 */
class CarrierLoadController extends BaseController
{
    public function __construct(
        protected CarrierPortalLoadService $carrierPortalLoadService
    ) {}

    public function index(Request $request)
    {
        $loads = $this->carrierPortalLoadService->forCarrier($request->user());

        // `?status=active` for what is on the road right now.
        $filter = $request->query('status');

        if (in_array($filter, CarrierPortalLoadService::VISIBLE_STATUSES, true)) {
            $loads = $loads->where('status', $filter)->values();
        }

        return $this->success([
            'summary' => $this->carrierPortalLoadService->summarise($loads),
            'loads' => CarrierLoadResource::collection($loads),
        ]);
    }

    public function show(Request $request, string $uuid)
    {
        $load = $this->carrierPortalLoadService->find($request->user(), $uuid);

        if (! $load) {
            return $this->error('That load is not available.', null, 404);
        }

        return $this->success(new CarrierLoadResource($load));
    }
}
