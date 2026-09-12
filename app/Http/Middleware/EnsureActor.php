<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Employee;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered under the single alias 'actor' (bootstrap/app.php). Used as
 * actor:customer or actor:staff on a route - one class, not two, matching
 * the exact syntax specified for STEP 04 (this replaces the two-separate-
 * middleware-classes placeholder sketched as a comment back in STEP 02).
 *
 * This is authentication-level actor separation only - which table the
 * token belongs to. Role-based permission checks (admin vs manager vs
 * receptionist) are Authorization, explicitly out of scope until STEP 05.
 */
class EnsureActor
{
    public function handle(Request $request, Closure $next, string $actor): Response
    {
        $user = $request->user();

        $matches = match ($actor) {
            'customer' => $user instanceof Customer,
            'staff' => $user instanceof Employee,
            default => false,
        };

        if (! $matches) {
            throw new AuthorizationException("This action requires a {$actor} account.");
        }

        return $next($request);
    }
}
