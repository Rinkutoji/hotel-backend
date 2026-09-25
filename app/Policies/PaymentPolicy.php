<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;

/**
 * Two-actor policy - takes an untyped $user and branches on instance.
 * Staff routes keep the two-layer pattern: route role:* middleware first,
 * then $this->authorize() re-checks the same rule. See
 * docs/STEP-10-PAYMENT-PLAN.md §3.
 */
class PaymentPolicy
{
    public function viewAny($user): bool
    {
        return $user instanceof Customer || $user instanceof Employee;
    }

    public function view($user, Payment $payment): bool
    {
        if ($user instanceof Customer) {
            return $payment->booking->customer_id === $user->id;
        }

        if ($user instanceof Employee) {
            return $this->receptionistConstraintAllows($user, $payment);
        }

        return false;
    }

    public function create($user): bool
    {
        return $user instanceof Customer;
    }

    /**
     * Uploading the bank-transfer receipt. Customer-owned only (the §3
     * matrix: staff cells are "-"; staff never upload on the API in this
     * step - there is no staff upload route).
     */
    public function uploadReceipt($user, Payment $payment): bool
    {
        return $user instanceof Customer
            && $payment->booking->customer_id === $user->id;
    }

    public function downloadReceipt($user, Payment $payment): bool
    {
        if ($user instanceof Customer) {
            return $payment->booking->customer_id === $user->id;
        }

        if ($user instanceof Employee) {
            return $this->receptionistConstraintAllows($user, $payment);
        }

        return false;
    }

    public function verify($user, Payment $payment): bool
    {
        if ($user instanceof Employee) {
            if (! in_array($user->role, ['admin', 'manager', 'receptionist'], true)) {
                return false;
            }

            return $this->receptionistConstraintAllows($user, $payment);
        }

        return false;
    }

    public function reject($user, Payment $payment): bool
    {
        if ($user instanceof Employee) {
            if (! in_array($user->role, ['admin', 'manager', 'receptionist'], true)) {
                return false;
            }

            return $this->receptionistConstraintAllows($user, $payment);
        }

        return false;
    }

    /**
     * STEP 11 - refund authorization. Admin + Manager only, delegated to
     * the existing process-refund Gate in AppServiceProvider so that
     * capability stays a single source of truth (docs/STEP-11-IMPLEMENTATION
     * -PLAN.md §3). A receptionist can verify/reject but NOT refund.
     */
    public function refund($user): bool
    {
        if ($user instanceof Employee) {
            return Gate::forUser($user)->allows('process-refund');
        }

        return false;
    }

    private function receptionistConstraintAllows(Employee $employee, Payment $payment): bool
    {
        if ($employee->role !== 'receptionist') {
            return true;
        }

        if ($employee->property_id === null) {
            return false;
        }

        return $payment->booking !== null
            && $payment->booking->hasAllRoomsInProperty($employee->property_id);
    }
}
