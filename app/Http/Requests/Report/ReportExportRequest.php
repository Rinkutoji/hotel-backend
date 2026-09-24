<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\ApiFormRequest;

/**
 * Export request for /reports/{report}/export. All filters are optional so
 * the same download route works across windowed and snapshot report types
 * (outstanding ignores the window entirely). Values present are validated
 * to the same allowlists as the JSON endpoints.
 */
class ReportExportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'group_by' => ['nullable', 'string', 'in:day,week,month'],
            'status' => ['nullable', 'string', 'in:pending_payment,confirmed,checked_in,checked_out,cancelled,no_show'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
        ];
    }
}
