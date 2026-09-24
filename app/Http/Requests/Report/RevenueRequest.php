<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for the revenue report. verified_total /
 * refunded_total are aggregated by verified_at / refunded_at, so a
 * verified payment that was never refunded simply never appears in
 * refunded_total. Authorization is the ReportPolicy's job.
 */
class RevenueRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', 'string', 'in:day,week,month'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
        ];
    }
}
