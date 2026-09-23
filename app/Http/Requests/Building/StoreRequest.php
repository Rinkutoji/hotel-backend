<?php

namespace App\Http\Requests\Building;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /staff/properties/{property}/buildings - shape validation for a
 * new building. The property comes from the route, so the code unique
 * check is scoped to that property: building codes are unique PER
 * PROPERTY, not globally (matching the DB-level UNIQUE(property_id, code)).
 *
 * Same deliberate absence of ->whereNull('deleted_at') as the property
 * requests: the DB unique constraint includes soft-deleted rows, so a
 * soft-deleted code can never be reused.
 */
class StoreRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('buildings', 'code')
                    ->where('property_id', $this->route('property')->id),
            ],
            'description' => ['nullable', 'string'],
            'floor_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
