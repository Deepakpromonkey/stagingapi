<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Carriers\Carrier;
use App\Models\CarrierBlocked;
use Illuminate\Http\Request;

class CarrierBlockedController extends Controller
{
    // 1. GET API to show all Blocked Carriers
    public function index(Request $request)
    {
        // The carrier relation crosses to the EC2 census view, whose rows are
        // very wide. Eager-loading it unqualified pulled every column of every
        // blocked carrier across the network; the blocklist screen reads the
        // handful named here, so ask for those. `id` stays because the
        // belongsTo cannot match the rows back without it.
        $blockedList = CarrierBlocked::where('company_id', $request->user()->company_id)
            ->with([
                'carrier:'.implode(',', [
                    'id',
                    'row_id',
                    'dot_number',
                    'legal_name',
                    'dba_name',
                    'carrier_operation',
                    'telephone',
                    'email_address',
                    'phy_city',
                    'phy_state',
                    'mcs150_mileage',
                    'nbr_power_unit',
                    'driver_total',
                ]),
                'user:id,first_name,last_name',
            ])
            ->latest()
            ->get();

        $carriers = $blockedList->map(function (CarrierBlocked $entry) {
            $carrier = $entry->carrier?->toArray() ?? [];

            $carrier['blocked_by'] = $entry->user
                ? trim($entry->user->first_name.' '.$entry->user->last_name)
                : null;

            $carrier['blocked_at'] = $entry->created_at?->format('m/d/y');

            return $carrier;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Blocked carriers retrieved.',
            'data' => $carriers,
        ]);
    }

    // 2. POST API to manually block a single carrier
    public function store(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // `carriers` is a view that derives row_id as CAST(dot_number AS CHAR),
        // so `where row_id = ?` cannot use the dot_number index and scans the
        // whole census file — ~21s per block. resolveIdFromRowId matches on
        // dot_number instead and caches the answer, which is what made this
        // endpoint slow.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json(['status' => 'error', 'message' => 'Carrier not found in system.'], 404);
        }

        CarrierBlocked::updateOrCreate(
            ['company_id' => $request->user()->company_id, 'carrier_id' => $carrierId],
            ['user_id' => $request->user()->id]
        );

        return response()->json(['status' => 'success', 'message' => 'Carrier blocked successfully.']);
    }

    // 3. DELETE API to unblock a carrier
    public function destroy(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string',
        ]);

        // Same indexed lookup as store() — see the note there.
        $carrierId = Carrier::resolveIdFromRowId($request->row_id);

        if (! $carrierId) {
            return response()->json(['status' => 'error', 'message' => 'Carrier not found in system.'], 404);
        }

        CarrierBlocked::where('company_id', $request->user()->company_id)
            ->where('carrier_id', $carrierId)
            ->delete();

        return response()->json(['status' => 'success', 'message' => 'Carrier removed from blocklist.']);
    }
}