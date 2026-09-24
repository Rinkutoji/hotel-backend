<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

class FilterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:pending,receipt_uploaded,verified,rejected,refunded'],
            'search' => ['nullable', 'string', 'max:255'],
            // Allowlist - an invalid value is rejected (422) here before it
            // reaches the query. Capped like the room index convention
            // (docs/STEP-10-PAYMENT-PLAN.md §8).
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ];
    }
}
