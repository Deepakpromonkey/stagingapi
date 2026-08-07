<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an account that is still on a system-generated password to the
 * change-password screen. Everything else stays out of reach until they pick
 * their own password.
 *
 * Both audiences pass through here: broker staff invited into a company, and
 * carriers whose portal account was created at the end of onboarding.
 */
class EnsurePasswordChanged
{
    /**
     * Routes reachable while the temporary password is still in place.
     */
    protected array $allowed = [
        'api/v1/me',
        'api/v1/logout',
        'api/v1/change-password',

        'api/v1/carrier-portal/me',
        'api/v1/carrier-portal/logout',
        'api/v1/carrier-portal/change-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->is($this->allowed)) {
            return response()->json([
                'status' => false,
                'message' => 'Please set a new password before continuing.',
                'errors' => [
                    'must_change_password' => true,
                ],
            ], 403);
        }

        return $next($request);
    }
}
