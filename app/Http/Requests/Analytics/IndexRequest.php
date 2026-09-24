<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for the analytics dashboard feed. A window is
 * required (an unbounded aggregate would defeat the point of the
 * date-filtered dashboard); authorization (admin/manager) is the
 * AnalyticsPolicy's job, enforced by AnalyticsController - same two-layer
 * convention as the reports module.
 *
 * group_by mirrors the reports 'day|week|month' allowlist so the axis
 * buckets are consistent across both modules.
 */
class IndexRequest extends ApiFormRequest
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
