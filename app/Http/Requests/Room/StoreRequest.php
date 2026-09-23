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
            'building_id' => [
                'required', 'integer',
                // A room can never be created in a deleted building.
                Rule::exists('buildings', 'id')->whereNull('deleted_at'),
            ],
            'room_number' => [
                'required', 'string', 'max:50',
                // Scoped per building INCLUDING soft-deleted rows - mirrors
                // the DB composite unique(building_id, room_number), so a
                // soft-deleted number is rejected here (422) instead of
                // dying as a QueryException at insert.
                Rule::unique('rooms', 'room_number')
                    ->where('building_id', $this->input('building_id')),
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
