<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\ApiFormRequest;

class CancelRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}