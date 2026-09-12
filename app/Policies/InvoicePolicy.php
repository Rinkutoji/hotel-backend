<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;

/**
 * STEP 16 - two-actor policy (verbatim PaymentPolicy shape): reads only.
 * Customers may read their own invoices (owned through payment.booking.
 * customer_id); every staff role may read any invoice. Generation is not an
 * authorization surface here - it happens inside PaymentService::verify,
 * already gated by the verify route + PaymentPolicy. See
 * docs/STEP-16-IMPLEMENTATION-PLAN.md §4.
 */
class InvoicePolicy
{
    public function viewAny($user): bool
    {
        return $user instanceof Customer || $user instanceof Employee;
    }

    public function view($user, Invoice $invoice): bool
    {
        if ($user instanceof Customer) {
            return $invoice->payment?->booking?->customer_id === $user->id;
        }

        if ($user instanceof Employee) {
            return true;
        }

        return false;
    }
}