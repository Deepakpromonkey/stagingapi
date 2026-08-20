<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Models\CarrierShortlist;
use Illuminate\Http\Request;

class CarrierShortlistController extends Controller
{
    /**
     * The shortlist belongs to the company, so everyone on the team sees the
     * same carriers regardless of who added them.
     */
    public function index(Request $request)
    {
        $shortlist = CarrierShortlist::where('company_id', $request->user()->company_id)
            ->with(['carrier', 'user:id,first_name,last_name'])
            ->latest()
            ->get();

        $carriers = $shortlist->map(function (CarrierShortlist $entry) {
            $carrier = $entry->carrier?->toArray() ?? [];

            // Who on the team put it on the list.
            $carrier['shortlisted_by'] = $entry->user
                ? trim($entry->user->first_name.' '.$entry->user->last_name)
                : null;

            $carrier['shortlisted_at'] = $entry->created_at?->format('m/d/y');

            return $carrier;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Shortlisted carriers retrieved.',
            'data' => $carriers,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Only the key is needed here, and resolving it is cached — the
        // shortlist itself is local, so the remote carrier lookup was the whole
        // of the delay on this endpoint.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.',
            ], 404);
        }

        CarrierShortlist::updateOrCreate(
            [
                'company_id' => $request->user()->company_id,
                'carrier_id' => $carrierId,
            ],
            [
                'user_id' => $request->user()->id,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier added to shortlist successfully.',
        ]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Only the key is needed here, and resolving it is cached — the
        // shortlist itself is local, so the remote carrier lookup was the whole
        // of the delay on this endpoint.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.',
            ], 404);
        }

        CarrierShortlist::where('company_id', $request->user()->company_id)
            ->where('carrier_id', $carrierId)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier removed from shortlist.',
        ]);
    }
}
