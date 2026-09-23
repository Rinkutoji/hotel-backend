<?php

namespace App\Http\Requests\Building;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /staff/buildings/{building} - same shape as StoreRequest, with the
 * code unique check ignoring the building being updated.
 *
 * Unlike store, there is NO {property} route param here (the update route
 * is top-level), so the per-property unique scope resolves through the
 * building model itself: ->where('property_id', route building's
 * property_id). A code held by ANOTHER building in the SAME property
 * still fails; the same code in a DIFFERENT property stays legal.
 */
class UpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('buildings', 'code')
                    ->where('property_id', $this->route('building')->property_id)
                    ->ignore($this->route('building')),
            ],
            'description' => ['nullable', 'string'],
            'floor_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
