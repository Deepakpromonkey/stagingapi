<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Carrier;

class CarrierShortlistController extends Controller
{
    public function index(Request $request)
    {
        $carriers = $request->user()->shortlistedCarriers;

        return response()->json([
            'status' => 'success',
            'message' => 'Shortlisted carriers retrieved.',
            'data' => $carriers
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string'
        ]);

        $carrier = Carrier::where('row_id', $request->row_id)->first();

        if (!$carrier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.'
            ], 404);
        }

        $request->user()->shortlistedCarriers()->syncWithoutDetaching([$carrier->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier added to shortlist successfully.'
        ]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'row_id' => 'required|string'
        ]);

        $carrier = Carrier::where('row_id', $request->row_id)->first();

        if (!$carrier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Carrier not found in system.'
            ], 404);
        }

        $request->user()->shortlistedCarriers()->detach($carrier->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Carrier removed from shortlist.'
        ]);
    }
}