<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\CarrierBrokerConnectionResource;
use App\Services\Carrier\CarrierConnectionService;
use Illuminate\Http\Request;

/**
 * The brokers this carrier has onboarded with.
 *
 * Readable by every seat — knowing who you haul for is not a sensitive write —
 * and scoped to the carrier company, so invited staff see the same list as the
 * owner who signed.
 */
class CarrierBrokerController extends BaseController
{
    public function __construct(
        protected CarrierConnectionService $carrierConnectionService
    ) {}

    public function index(Request $request)
    {
        $connections = $this->carrierConnectionService->forCarrier($request->user());

        // `?status=active` for brokers who can book today, `in_progress` for
        // onboardings still to finish.
        $filter = $request->query('status');

        if ($filter === 'active') {
            $connections = $connections->where('status', 'completed')->values();
        } elseif ($filter === 'in_progress') {
            $connections = $connections->where('status', '!=', 'completed')->values();
        }

        return $this->success([
            'summary' => $this->carrierConnectionService->summarise($connections),
            'brokers' => CarrierBrokerConnectionResource::collection($connections),
        ]);
    }
}
