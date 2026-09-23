<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /staff/properties - shape validation for a new property.
 *
 * NOTE: the code unique check deliberately has NO ->whereNull('deleted_at').
 * The DB-level UNIQUE on properties.code includes soft-deleted rows and
 * the rule mirrors that fail-safe - reusing a soft-deleted code is
 * rejected here exactly as the database would.
 */
class StoreRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('properties', 'code')],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province_state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
