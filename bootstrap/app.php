<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
/*
|--------------------------------------------------------------------------
| STEP 02 note on this file
|--------------------------------------------------------------------------
|
| The genuine Laravel 11 skeleton's version of this file calls
| ->withRouting(web: ..., commands: ..., health: '/up') with empty
| withMiddleware()/withExceptions() closures. This project has no
| routes/web.php (API-only backend, React is a fully separate app), so
| `web:` is replaced with `api:`, and the two closures below are filled
| in with STEP 02's approved foundation pieces. Nothing here touches
| auth/authorization - that starts STEP 04.
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Every /api/* response is forced to JSON, including framework-level
        // errors (404, 405, etc.) that would otherwise render Laravel's
        // default HTML error pages.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // STEP 04: single parameterized middleware, not two separate
        // classes - matches the actor:customer / actor:staff syntax.
        // STEP 05: same pattern for role:admin / role:admin,manager.
        $middleware->alias([
            'actor' => \App\Http\Middleware\EnsureActor::class,
            'role' => \App\Http\Middleware\EnsureStaffHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Force every exception on an /api/* route to render as JSON,
        // regardless of the client's Accept header.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unauthenticated.',
                'errors' => null,
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'This action is unauthorized.',
                'errors' => null,
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'The requested resource was not found.',
                'errors' => null,
            ], 404);
        });

        // STEP 07: well-formed, authorized request that conflicts with
        // the resource's current business state (e.g. an invalid room
        // status transition, or deleting a room type that still has
        // rooms) - distinct from 422 (malformed input) and 403 (wrong
        // role/actor).
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => null,
            ], 409);
        });

        // Catches Laravel's NotFoundHttpException (bad route), MethodNotAllowedHttpException
        // (wrong verb), ThrottleRequestsException (rate limit), and anything else that
        // already carries a proper HTTP status code.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'HTTP error.',
                'errors' => null,
            ], $e->getStatusCode());
        });

        // Final catch-all. Never leaks a stack trace in production; includes a
        // short summary in local/debug for developer convenience.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            report($e);

            $debug = (bool) config('app.debug');

            return response()->json([
                'success' => false,
                'message' => $debug ? $e->getMessage() : 'Something went wrong. Please try again later.',
                'errors' => $debug ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ] : null,
            ], 500);
        });
    })->create();
