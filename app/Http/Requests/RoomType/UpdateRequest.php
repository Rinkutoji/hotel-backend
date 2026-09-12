<?php

namespace App\Http\Requests\RoomType;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT semantics - full-resource replacement, all fields required, not a
 * partial PATCH. Matches the route being defined as PUT, not PATCH.
 */
class UpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('room_types', 'name')
                    ->ignore($this->route('roomType'))
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'capacity_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'capacity_children' => ['required', 'integer', 'min:0', 'max:20'],
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'max:100'],
        ];
    }
}
