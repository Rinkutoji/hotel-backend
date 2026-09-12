<?php

namespace App\Http\Requests\Room;

use App\Http\Requests\ApiFormRequest;

class ChangeStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
