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
        $blockedList = CarrierBlocked::where('company_id', $request->user()->company_id)
            ->with(['carrier', 'user:id,first_name,last_name'])
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

        $carrier = Carrier::where('row_id', $request->row_id)->first();

        if (! $carrier) {
            return response()->json(['status' => 'error', 'message' => 'Carrier not found in system.'], 404);
        }

        CarrierBlocked::updateOrCreate(
            ['company_id' => $request->user()->company_id, 'carrier_id' => $carrier->id],
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

        $carrier = Carrier::where('row_id', $request->row_id)->first();

        if (! $carrier) {
            return response()->json(['status' => 'error', 'message' => 'Carrier not found in system.'], 404);
        }

        CarrierBlocked::where('company_id', $request->user()->company_id)
            ->where('carrier_id', $carrier->id)
            ->delete();

        return response()->json(['status' => 'success', 'message' => 'Carrier removed from blocklist.']);
    }
}