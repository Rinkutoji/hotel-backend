<?php

namespace App\Http\Requests\RoomImage;

use App\Http\Requests\ApiFormRequest;

/**
 * sort_order only - no 'caption', since the room_images schema has no
 * such column. See docs/STEP-08-ROOM-IMAGE-PLAN.md §1-4 (schema review)
 * - "do not invent unnecessary fields if they are not in the database."
 */
class UpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
