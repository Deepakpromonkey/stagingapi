<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Carrier\CarrierActivityService;
use App\Services\Carrier\CarrierAssociationService;
use App\Services\Carrier\CarrierRiskService;
use Illuminate\Http\Request;

class CarrierInsightController extends BaseController
{
    public function __construct(
        protected CarrierRiskService $riskService,
        protected CarrierAssociationService $associationService,
        protected CarrierActivityService $activityService,
    ) {}

    /**
     * Raw risk factor set — every scored signal as a boolean or null.
     */
    public function riskFactors(string $dot)
    {
        $factors = $this->riskService->factors($dot);

        if ($factors === null) {
            return $this->error('Carrier not found.', null, 404);
        }

        return $this->success([
            'dot_number' => $dot,
            'factors' => $factors,
        ], 'Risk factors retrieved successfully.');
    }

    /**
     * Reliability view: the same factors sorted into strengths, weaknesses
     * (by severity) and items still being monitored.
     */
    public function reliability(string $dot)
    {
        $reliability = $this->riskService->reliability($dot);

        if ($reliability === null) {
            return $this->error('Carrier not found.', null, 404);
        }

        return $this->success($reliability, 'Reliability assessment retrieved successfully.');
    }

    /**
     * Other carriers sharing contact details, name or address.
     */
    public function associations(string $dot)
    {
        $associations = $this->associationService->associations($dot);

        if ($associations === null) {
            return $this->error('Carrier not found.', null, 404);
        }

        return $this->success($associations, 'Company associations retrieved successfully.');
    }

    /**
     * Equipment insights — carriers inspected on the same VINs.
     */
    public function equipment(string $dot)
    {
        return $this->success(
            $this->associationService->equipment($dot),
            'Equipment insights retrieved successfully.'
        );
    }

    /**
     * Activity history: ?type=inspections|crashes|violations
     */
    public function activity(Request $request, string $dot)
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:inspections,crashes,violations'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $validated['type'] ?? 'inspections';

        $results = $this->activityService->for(
            $type,
            $dot,
            (int) ($validated['per_page'] ?? 25),
            (int) ($validated['page'] ?? 1),
        );

        return $this->success([
            'dot_number' => $dot,
            'type' => $type,
            'data' => $results->items(),
            'current_page' => $results->currentPage(),
            'per_page' => $results->perPage(),
            'total' => $results->total(),
            'last_page' => $results->lastPage(),
            'has_more_pages' => $results->hasMorePages(),
        ], 'Activity history retrieved successfully.');
    }
}
