<?php

namespace App\Policies;

use App\Models\Employee;

/**
 * Auto-discovered by Laravel's standard App\Policies\{Model}Policy
 * convention. Same shape as RoomTypePolicy/RoomPolicy - view is any
 * staff role, write actions are admin/manager only. Unlike RoomPolicy's
 * changeStatus, there is no receptionist carve-out here - the STEP 08
 * matrix is flat: receptionist forbidden on every management action.
 */
class RoomImagePolicy
{
    public function viewAny(Employee $employee): bool
    {
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
