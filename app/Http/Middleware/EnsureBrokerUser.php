<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mirror of EnsureCarrierUser, on the broker side.
 *
 * Now that carriers authenticate through the same Sanctum guard, a carrier
 * token would otherwise satisfy `auth:sanctum` on the broker routes — and the
 * ones with no permission middleware would go on to read `company_id` off a
 * model that has none. Everything behind this middleware is for staff of a
 * broker company only.
 */
class EnsureBrokerUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json([
                'status' => false,
                'message' => 'This endpoint is not available to carrier portal accounts.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
