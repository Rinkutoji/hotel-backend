<?php

namespace App\Http\Requests\Room;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately has NO rule for 'status' - a client can send it, but
 * $request->validated() only ever returns keys that have a rule defined,
 * so it's silently dropped regardless. Status is set exclusively by
 * RoomService::create(), never from client input. See
 * docs/STEP-07-ROOM-PLAN.md §5.
 */
class StoreRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'room_number' => [
                'required', 'string', 'max:50',
                // Scoped to active rows only - see the schema fix in
                // docs/STEP-07-ROOM-PLAN.md §0.
                Rule::unique('rooms', 'room_number')->whereNull('deleted_at'),
            ],
            'room_type_id' => [
                'required', 'integer',
                // "A room cannot be created with a deleted room type" -
                // only active room types satisfy this.
                Rule::exists('room_types', 'id')->whereNull('deleted_at'),
            ],
            'floor' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }
}
