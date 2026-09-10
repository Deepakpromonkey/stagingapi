<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\CarrierProfileService;
use Illuminate\Http\Request;

class CarrierProfileController extends BaseController
{
    public function __construct(
        protected CarrierProfileService $carrierProfileService
    ) {}

    /** Sections that each cost an extra query against the EC2 database. */
    protected const INCLUDABLE = ['activity', 'inspections', 'crashes', 'contacts', 'authority_orders', 'insurance_pending'];

    /**
     * Full carrier profile.
     *
     * Accepts a DOT number or a row_id (UUID).
     *   ?include=activity,inspections adds the heavier sections
     *   (activity = VIN/state/violation aggregates)
     *   ?fmcsa=1                      adds a live FMCSA snapshot
     */
    public function show(Request $request, string $identifier)
    {
        $include = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', (string) $request->query('include')))),
            self::INCLUDABLE
        ));

        $profile = $this->carrierProfileService->profile(
            $identifier,
            $request->boolean('fmcsa'),
            $include
        );

        if (! $profile) {
            return $this->error('Carrier not found.', null, 404);
        }

        return $this->success($profile, 'Carrier profile retrieved successfully.');
    }
}
