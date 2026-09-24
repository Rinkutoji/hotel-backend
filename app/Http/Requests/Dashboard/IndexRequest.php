<?php

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for the admin dashboard feed - same window rules
 * as analytics/reports (inclusive from/to, day|week|month granularity).
 * Authorization is the AnalyticsPolicy's job, enforced by
 * DashboardController, mirroring the two-layer convention.
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
        ];
    }
}
