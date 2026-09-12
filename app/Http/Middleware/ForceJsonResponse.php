<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces every request on the api/* route group to be treated as
 * "expects JSON", so that 404s, 405s, and any other framework-level
 * error renders through our JSON exception handling in
 * bootstrap/app.php instead of Laravel's default HTML error views.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
