<?php

namespace App\Services\Carrier;

use App\Models\Carriers\Crash;
use App\Models\Carriers\Inspection;
use App\Models\Carriers\ViolationDetail;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Paginated activity history — inspections, crashes and violations.
 *
 * Kept out of the profile response because a large carrier has tens of
 * thousands of rows across these tables.
 */
class CarrierActivityService
{
    public function inspections(string $dot, int $perPage, int $page): LengthAwarePaginator
    {
        return Inspection::query()
            ->where('dot_number', $dot)
            ->orderByDesc('insp_date')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function crashes(string $dot, int $perPage, int $page): LengthAwarePaginator
    {
        return Crash::query()
            ->where('dot_number', $dot)
            ->orderByDesc('report_date')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function violations(string $dot, int $perPage, int $page): LengthAwarePaginator
    {
        return ViolationDetail::query()
            ->where('dot_number', $dot)
            ->orderByDesc('insp_date')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function for(string $type, string $dot, int $perPage, int $page): LengthAwarePaginator
    {
        return match ($type) {
            'crashes' => $this->crashes($dot, $perPage, $page),
            'violations' => $this->violations($dot, $perPage, $page),
            default => $this->inspections($dot, $perPage, $page),
        };
    }
}
