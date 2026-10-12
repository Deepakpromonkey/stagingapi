<?php

namespace App\Pay\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the DT Pay admin endpoints. Only DollarTraq's admin backend calls
 * them, server to server, with the shared DTPAY_ADMIN_TOKEN, and names the
 * signed-in admin in X-Admin-Name / X-Admin-Email for the audit trail.
 * With no token configured the endpoints are switched off.
 */
class AdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('dtpay.admin_token');

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Not allowed.'], 401);
        }

        $email = trim((string) $request->header('X-Admin-Email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'The admin making this change must be named (X-Admin-Email).'], 422);
        }

        $request->attributes->set('dtpay_admin', [
            'email' => $email,
            'name' => mb_substr(trim((string) $request->header('X-Admin-Name')) ?: $email, 0, 120),
        ]);

        return $next($request);
    }
}
