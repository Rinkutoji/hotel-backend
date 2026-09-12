<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered under the alias 'role'. Used as role:admin or
 * role:admin,manager - Laravel splits comma-separated middleware
 * parameters automatically into $roles.
 *
 * Always used AFTER actor:staff on the same route (the approved layered
 * flow: auth:sanctum -> actor:staff -> role:*), but this middleware
 * does not assume that already ran - it independently fails closed if
 * the resolved user isn't an Employee, rather than trusting route
 * composition alone.
 *
 * The role checked is always $user->role, read from the authenticated
 * Employee model - i.e. from the database, via the already-verified
 * Sanctum token. Nothing here (or anywhere else in the app) reads a
 * "role" field from request input for authorization purposes.
 *
 * No implicit admin bypass: 'admin' is not automatically added to every
 * allowed-roles list. A route that should be admin-accessible (the
 * normal case) must list it explicitly - role:admin,manager,receptionist,
 * not just role:receptionist. See docs/STEP-05-AUTHORIZATION-PLAN.md §2
 * for why this is deliberate.
 */
class EnsureStaffHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof Employee) {
            throw new AuthorizationException('This action requires a staff account.');
        }

        if (! in_array($user->role, $roles, true)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
