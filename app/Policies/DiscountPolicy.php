<?php

namespace App\Policies;

use App\Models\Employee;

/**
 * STEP 17 - discount/tier definitions are ADMIN+MANAGER management data
 * (docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-5, approved value
 * "admin+manager"). Auto-discovered by Laravel's standard
 * App\Policies\{Model}Policy convention - no manual registration needed.
 * Deliberately type-hinted against Employee only: the customer/staff actor
 * split is enforced by route middleware before this class ever runs.
 */
class DiscountPolicy
{
    public function viewAny(Employee $employee): bool
    {
        return in_array($employee->role, ['admin', 'manager'], true);
    }

    public function view(Employee $employee): bool
    {
        return in_array($employee->role, ['admin', 'manager'], true);
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