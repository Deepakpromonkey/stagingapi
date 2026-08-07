<?php

namespace App\Http\Controllers;

use App\Models\SearchHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchHistoryController extends Controller
{
    public function index(Request $request)
    {
        // Company-wide history: anyone on the team sees what the company
        // looked at, not just their own views.
        $history = SearchHistory::where('company_id', $request->user()->company_id)
            ->latest()
            ->take(10)
            ->get();

        // Carrier attributes live in the EC2 database, so they are fetched for
        // the whole page in one query rather than per row.
        $carriers = $this->carrierSummaries(
            $history->pluck('carrier_id')->unique()->all()
        );

        $data = $history->map(function (SearchHistory $log) use ($carriers) {
            $carrier = $carriers[$log->carrier_id] ?? null;

            return [
               'id' => $carrier?->row_id,
                'carrier_id' => $log->carrier_id,
                'carrier_name' => $carrier?->legal_name,
                'dot_number' => $carrier?->dot_number,
                'mc_number' => $carrier?->mc_number,

                // Active if any authority type is live.
                'authority_active' => $carrier
                    ? in_array('A', [$carrier->common_stat, $carrier->contract_stat, $carrier->broker_stat], true)
                    : null,

                // Same rule the carrier search uses: an insurance filing on record.

                'dt_score' => $log->dt_score !== null ? (float) $log->dt_score : null,

                'searched_on' => $log->updated_at?->format('m/d/y'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'carrier_id' => 'required|integer',
        ]);

        $log = SearchHistory::updateOrCreate(
            [
                'company_id' => $request->user()->company_id,
                'carrier_id' => $request->carrier_id,
            ],
            [
                // Who on the team looked it up most recently.
                'user_id' => $request->user()->id,
            ]
        );

        $log->touch();

        return response()->json([
            'status' => 'success',
            'message' => 'Search logged successfully.',
            'data' => $log,
        ]);
    }

    /**
     * Name, identifiers, authority status and insurance presence, keyed by
     * carrier id.
     */
    protected function carrierSummaries(array $carrierIds)
    {
        if (empty($carrierIds)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($carrierIds), '?'));

        return collect(DB::connection('external_db')->select("
            SELECT
                c.id,
                c.row_id,
                c.dot_number,
                c.legal_name,
                ca.docket_number AS mc_number,
                ca.common_stat,
                ca.contract_stat,
                ca.broker_stat,
                EXISTS (
                    SELECT 1 FROM insurance_filings f WHERE f.dot_number = c.dot_number
                ) AS insurance_active
            FROM carriers c
            LEFT JOIN carrier_authorities ca ON ca.dot_number = c.dot_number
            WHERE c.id IN ({$placeholders})
        ", $carrierIds))->keyBy('id');
    }
}
