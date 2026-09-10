<?php

namespace App\Http\Middleware;

use App\Models\CarrierUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carrier portal routes.
 *
 * Sanctum's tokenable is polymorphic, so `auth:sanctum` alone would accept a
 * broker's token here just as happily as a carrier's. This pins the portal to
 * CarrierUser tokens carrying the portal ability.
 */
class EnsureCarrierUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof CarrierUser
            || ! $user->tokenCan(CarrierUser::TOKEN_ABILITY)) {

            return response()->json([
                'status' => false,
                'message' => 'This endpoint is for carrier portal accounts.',
                'errors' => null,
            ], 403);
        }

        if (! $user->status) {
            return response()->json([
                'status' => false,
                'message' => 'This account has been disabled. Please contact your broker.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
