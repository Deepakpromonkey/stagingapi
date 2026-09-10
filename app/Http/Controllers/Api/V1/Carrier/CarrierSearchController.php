<?php

namespace App\Http\Controllers\Api\V1\Carrier;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Carrier\SearchCarrierRequest;
use App\Services\CarrierSearchService;

class CarrierSearchController extends BaseController
{
    public function __construct(
        protected CarrierSearchService $carrierSearchService
    ) {}

    /**
     * Search carriers held in the EC2 carrier database.
     *
     * Filter by any combination of dot_number, mc_number, phone, email and
     * company_name, or pass `q` to search across all of them.
     */
    public function index(SearchCarrierRequest $request)
    {
        $carriers = $this->carrierSearchService->search(
            $request->validated()
        );

        return $this->success([
            'data' => $carriers->items(),
            'current_page' => $carriers->currentPage(),
            'per_page' => $carriers->perPage(),
            'total' => $carriers->total(),
            'last_page' => $carriers->lastPage(),
            'has_more_pages' => $carriers->hasMorePages(),
        ], 'Carriers retrieved successfully.');
    }
}
