<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\Room;

/**
 * Auto-discovered by Laravel's standard App\Policies\{Model}Policy
 * convention. Type-hinted against Employee only - public browsing never
 * calls $this->authorize() against this policy at all, same pattern as
 * RoomTypePolicy since STEP 06.
 */
class RoomPolicy
{
    public function viewAny(Employee $employee): bool
    {
        return true;
    }

    public function view(Employee $employee, Room $room): bool
    {
        if ($employee->role === 'receptionist') {
            return $employee->property_id !== null
                && $employee->property_id === $room->building?->property_id;
        }

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

    /**
     * Broader than create/update/delete - receptionist can perform
     * operational status changes (maintenance/available) even though
     * they can't create/edit/delete room records themselves. Matches
     * the STEP 07 authorization matrix exactly.
     */
    public function changeStatus(Employee $employee, Room $room): bool
    {
        if (! in_array($employee->role, ['admin', 'manager', 'receptionist'], true)) {
            return false;
        }

        if ($employee->role === 'receptionist') {
            return $employee->property_id !== null
                && $employee->property_id === $room->building?->property_id;
        }

        return true;
    }
}
