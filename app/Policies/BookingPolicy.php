<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;

/**
 * Two-actor policy - takes an untyped $user and branches on instance.
 * Staff routes keep the two-layer pattern: route role:* middleware first,
 * then $this->authorize() re-checks the same rule. See
 * docs/STEP-09-BOOKING-PLAN.md §3.
 */
class BookingPolicy
{
    public function viewAny($user): bool
    {
        if ($user instanceof Employee) {
            return true;
        }

        return false;
    }

    public function view($user, Booking $booking): bool
    {
        if ($user instanceof Customer) {
            return $booking->customer_id === $user->id;
        }

        if ($user instanceof Employee) {
            return $this->receptionistConstraintAllows($user, $booking);
        }

        return false;
    }

    public function create($user): bool
    {
        return $user instanceof Customer;
    }

    public function confirm($user, Booking $booking): bool
    {
        if ($user instanceof Employee) {
            if (! in_array($user->role, ['admin', 'manager', 'receptionist'], true)) {
                return false;
            }

            return $this->receptionistConstraintAllows($user, $booking);
        }

        return false;
    }

    public function cancel($user, Booking $booking): bool
    {
        if ($user instanceof Customer) {
            return $booking->customer_id === $user->id;
        }

        if ($user instanceof Employee) {
            if (! in_array($user->role, ['admin', 'manager', 'receptionist'], true)) {
                return false;
            }

            return $this->receptionistConstraintAllows($user, $booking);
        }

        return false;
    }

    public function checkIn($user, Booking $booking): bool
    {
        if ($user instanceof Employee) {
            if (! in_array($user->role, ['admin', 'manager', 'receptionist'], true)) {
                return false;
            }

            return $this->receptionistConstraintAllows($user, $booking);
        }

        return false;
    }

    public function checkOut($user, Booking $booking): bool
    {
        if ($user instanceof Employee) {
            if (! in_array($user->role, ['admin', 'manager', 'receptionist'], true)) {
                return false;
            }

            return $this->receptionistConstraintAllows($user, $booking);
        }

        return false;
    }

    private function receptionistConstraintAllows(Employee $employee, Booking $booking): bool
    {
        if ($employee->role !== 'receptionist') {
            return true;
        }

        if ($employee->property_id === null) {
            return false;
        }

        return $booking->hasAllRoomsInProperty($employee->property_id);
    }
}
