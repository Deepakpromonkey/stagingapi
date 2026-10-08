<?php

use App\Exceptions\BillingException;
use App\Exceptions\DrayageException;
use App\Services\Ops\OpsAlert;
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

        /*
        | Every reported exception - the ones that become a 500, not 404s,
        | validation or sign-in failures, which Laravel does not report - goes
        | to the Teams channel and the alert mailboxes. Sent once the response
        | has gone out, so a slow webhook or mail server never holds up the
        | broker. See config/ops.php; nothing is sent unless OPS_ROLE is set.
        */
        $exceptions->report(function (Throwable $e) {
            if (! OpsAlert::enabled()) {
                return;
            }

            $request = app()->bound('request') ? request() : null;
            $send = fn () => app(OpsAlert::class)->exception($e, $request);

            app()->runningInConsole() ? $send() : app()->terminating($send);
        });

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

        // Drayage directory refusals (no dataset yet, import already running,
        // unreadable file) -> the same envelope, with the status they carry.
        $exceptions->render(function (DrayageException $e, Request $request) {
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
