<?php

namespace App\Http\Requests\Calendar;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for the staff booking calendar. The range is
 * the calendar's visible window; a booking whose stay overlaps either
 * end of the window is included (check_in_date <= to AND check_out_date
 * >= from). Authorization (any staff role) is BookingPolicy::viewAny.
 */
class BookingsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'in:pending_payment,confirmed,checked_in,checked_out,cancelled,no_show'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ];
    }
}
