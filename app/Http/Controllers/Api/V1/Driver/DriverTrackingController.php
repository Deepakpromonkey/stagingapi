<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Driver;

class DriverTrackingController extends Controller
{
    public function updateInterval(Request $request, $uuid)
    {
        $request->validate([
            'interval_seconds' => 'required|integer|min:10' 
        ]);

        $driver = Driver::where('uuid', $uuid)->firstOrFail();
        
        $driver->tracking_interval_seconds = $request->interval_seconds;
        $driver->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Driver tracking interval updated successfully.',
            'data' => [
                'driver_uuid' => $driver->uuid,
                'interval_seconds' => $driver->tracking_interval_seconds
            ]
        ]);
    }
}