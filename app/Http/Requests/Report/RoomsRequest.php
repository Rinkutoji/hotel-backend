<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for the room inventory report. from/to are
 * optional, but provided together (occupied_room_nights is only
 * meaningful over a window). Authorization is the ReportPolicy's job.
 */
class RoomsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
        ];
    }
}
