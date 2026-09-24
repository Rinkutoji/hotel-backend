<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for the bookings summary report. The range is
 * required (a report always has a window; unbounded aggregation would
 * be unbounded); authorization (admin/manager) is the ReportPolicy's
 * job, enforced by ReportController, not here.
 */
class BookingsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', 'string', 'in:day,week,month'],
            'status' => ['nullable', 'string', 'in:pending_payment,confirmed,checked_in,checked_out,cancelled,no_show'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
        ];
    }
}
