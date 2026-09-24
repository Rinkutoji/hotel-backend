<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\ApiFormRequest;

/**
 * Shared window + granularity validation for the extended report types
 * (guests, sources, check-ins, check-outs, payments). Mirrors the
 * RevenueRequest rules so the axis stays consistent across types.
 */
class ReportWindowRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'group_by' => ['nullable', 'string', 'in:day,week,month'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
        ];
    }
}
