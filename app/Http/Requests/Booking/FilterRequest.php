<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\ApiFormRequest;

class FilterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:pending_payment,confirmed,checked_in,checked_out,cancelled,no_show'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
        ];
    }
}
