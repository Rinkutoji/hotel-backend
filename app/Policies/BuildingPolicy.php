<?php

namespace App\Policies;

use App\Models\Building;
use App\Models\Employee;

/**
 * Building management under a property - admin-only CRUD with read access
 * for every staff role, per the D-1 role matrix. Receptionists are scoped
 * to their assigned property (employees.property_id): they may view only
 * buildings belonging to that property. The route group gates role:admin
 * before this class runs; this policy is the second, resource-level layer
 * (the project's two-layer convention).
 */
class BuildingPolicy
{
    public function viewAny(Employee $employee): bool
    {
        return true;
    }

    public function view(Employee $employee, Building $building): bool
    {
        if ($employee->role === 'receptionist') {
            return $employee->property_id === $building->property_id;
        }

        return true;
    }

    public function create(Employee $employee): bool
    {
        return $employee->role === 'admin';
    }

    public function update(Employee $employee): bool
    {
        return $employee->role === 'admin';
    }

    public function delete(Employee $employee): bool
    {
        return $employee->role === 'admin';
    }
}
