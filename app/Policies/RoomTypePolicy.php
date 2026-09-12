<?php

namespace App\Policies;

use App\Models\Employee;

/**
 * Auto-discovered by Laravel's standard App\Policies\{Model}Policy
 * convention - no manual registration needed.
 *
 * Deliberately type-hinted against Employee only. Public browsing never
 * calls $this->authorize() against this policy at all (see
 * docs/STEP-06-ROOM-TYPE-PLAN.md §4) - a Customer never reaches these
 * methods, so there's no need to handle that case here.
 */
class RoomTypePolicy
{
    public function viewAny(Employee $employee): bool
    {
        // Any authenticated staff member - receptionist included.
        return true;
    }

    public function view(Employee $employee): bool
    {
        return true;
    }

    public function create(Employee $employee): bool
    {
        return in_array($employee->role, ['admin', 'manager'], true);
    }

    public function update(Employee $employee): bool
    {
        return in_array($employee->role, ['admin', 'manager'], true);
    }

    public function delete(Employee $employee): bool
    {
        return in_array($employee->role, ['admin', 'manager'], true);
    }
}
