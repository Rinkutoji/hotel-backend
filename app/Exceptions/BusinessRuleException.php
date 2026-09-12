<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an action is well-formed and authorized, but conflicts
 * with a business-state rule - e.g. deleting a room type that still has
 * physical rooms, or an invalid room status transition. Maps to HTTP 409
 * Conflict (registered in bootstrap/app.php) - distinct from validation
 * errors (422, malformed input) and authorization errors (403, wrong
 * role/actor). The request itself was fine; the current state of the
 * resource doesn't allow it right now.
 */
class BusinessRuleException extends Exception
{
    //
}
