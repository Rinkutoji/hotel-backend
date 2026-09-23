<?php

namespace App\Http\Requests\Room;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT semantics - full-resource replacement, all fields required.
 * Deliberately has no rule for 'status' - see StoreRequest.php's note;
 * the same reasoning applies here.
 */
class UpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'building_id' => [
                'required', 'integer',
                // A room can never be moved into a deleted building.
                Rule::exists('buildings', 'id')->whereNull('deleted_at'),
            ],
            'room_number' => [
                'required', 'string', 'max:50',
                // Scoped per building INCLUDING soft-deleted rows - mirrors
                // the DB composite unique(building_id, room_number), so a
                // soft-deleted number is rejected here (422) instead of
                // dying as a QueryException at insert.
                Rule::unique('rooms', 'room_number')
                    ->where('building_id', $this->input('building_id'))
                    ->ignore($this->route('room')),
            ],
            'room_type_id' => [
                'required', 'integer',
                Rule::exists('room_types', 'id')->whereNull('deleted_at'),
            ],
            'floor' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }
}
