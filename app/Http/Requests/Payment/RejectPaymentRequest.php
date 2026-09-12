<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

class RejectPaymentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}