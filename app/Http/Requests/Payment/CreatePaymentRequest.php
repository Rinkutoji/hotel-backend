<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape only (Architecture Baseline §8) - "does this booking belong to the
 * caller" is answered in the controller via query scoping, not here. Money
 * and status are intentionally not accepted anywhere on this request.
 */
class CreatePaymentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // Ownership exists is deliberately NOT checked here (no `exists:
            // bookings,id` rule): the controller resolves the booking via a
            // customer-scoped findOrFail, so another customer's / a missing
            // booking is a clean 404, never 422/403. Shape only (§8).
            'booking_id' => ['required', 'integer'],
            'method' => ['required', 'string', 'in:bank_transfer,cash'],
        ];
    }
}