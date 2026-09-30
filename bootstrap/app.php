<?php

use App\Exceptions\BillingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
    | Broadcast authorisation. Registered here rather than through
    | withRouting(channels:) so the guard can be named: the web panel and the
    | driver app both authenticate with Sanctum tokens and carry no session
    | cookie, so the default session guard would refuse every subscription.
    */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        /*
        | This app has no web login page at all (see routes/web.php - a
        | welcome view and two public token links, nothing auth-guarded), so
        | there is no route named `login` to redirect anyone to, ever.
        | Laravel's default guest-redirect only skips itself when the
        | request "expects JSON"; otherwise it tries route('login') and
        | throws RouteNotFoundException instead of the 401 the caller
        | should have gotten - happens to any client (Postman, a mobile
        | app, a webhook) that does not happen to send
        | Accept: application/json. Unconditionally null removes the
        | redirect attempt everywhere, not just under api/*.
        */
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Billing / subscription problems -> consistent API envelope, with the
        // status the failure deserves (Stripe unreachable is not a 400).
        $exceptions->render(function (BillingException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ], $e->status);
        });

        // Missing permission / role -> consistent API envelope.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to perform this action.',
                'errors' => [
                    'required_permissions' => $e->getRequiredPermissions(),
                ],
            ], 403);
        });
    })->create();
