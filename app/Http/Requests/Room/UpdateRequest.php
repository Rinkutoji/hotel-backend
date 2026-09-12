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
            'room_number' => [
                'required', 'string', 'max:50',
                Rule::unique('rooms', 'room_number')
                    ->ignore($this->route('room'))
                    ->whereNull('deleted_at'),
            ],
            'room_type_id' => [
                'required', 'integer',
                Rule::exists('room_types', 'id')->whereNull('deleted_at'),
            ],
            'floor' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }
}
