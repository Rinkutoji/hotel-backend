<?php

namespace App\Http\Requests\RoomType;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                // Scoped to active rows only - see the schema fix in
                // docs/STEP-06-ROOM-TYPE-PLAN.md §0. A soft-deleted room
                // type's name must be reusable.
                Rule::unique('room_types', 'name')->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'capacity_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'capacity_children' => ['required', 'integer', 'min:0', 'max:20'],
            // A flat array of short strings only - e.g. ["WiFi", "Mini
            // Bar"]. Rejects nested objects/arrays or any other shape.
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'max:100'],
        ];
    }
}
