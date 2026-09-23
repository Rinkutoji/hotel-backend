<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The ONLY place the effective property filter for a staff request is
 * decided. Receptionists are hard-scoped to their assigned property -
 * request params can never widen that scope (spec §8.1).
 */
class PropertyScope
{
    /**
     * @return int|null effective property id filter; null = all properties
     *
     * @throws AuthorizationException when a receptionist has no assignment
     *                                (fail closed - legacy data edge)
     */
    public static function effectiveId(Employee $employee, ?int $requestedPropertyId): ?int
    {
        if (in_array($employee->role, ['admin', 'manager'], true)) {
            return $requestedPropertyId;
        }

        if ($employee->property_id === null) {
            throw new AuthorizationException('Your account is not assigned to a property.');
        }

        return (int) $employee->property_id; // request param deliberately ignored
    }
}
