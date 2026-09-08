<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the driver app routes to driver tokens.
 *
 * Drivers share the Sanctum guard with broker staff and carriers, so without
 * this a broker or carrier token would satisfy `auth:sanctum` here and reach
 * endpoints that then read `phone_e164` off a model that has none. The mirror
 * of EnsureBrokerUser and EnsureCarrierUser.
 */
class EnsureDriver
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Driver) {
            return response()->json([
                'status' => false,
                'message' => 'This endpoint is for the driver app.',
            ], 403);
        }

        // Deactivating a driver has to take effect on the next request, not
        // whenever their token happens to expire.
        if (! $user->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'This driver account is no longer active.',
            ], 403);
        }

        return $next($request);
    }
}
