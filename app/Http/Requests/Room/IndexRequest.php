<?php

namespace App\Http\Requests\Room;

use App\Http\Requests\ApiFormRequest;

class IndexRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
            'floor' => ['nullable', 'integer', 'min:1', 'max:200'],
            'status' => ['nullable', 'string', 'in:available,reserved,occupied,cleaning,maintenance'],
            // Allowlist - an invalid value is rejected here (422) before
            // it ever reaches the query.
            'sort' => ['nullable', 'string', 'in:room_number,floor,status,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
