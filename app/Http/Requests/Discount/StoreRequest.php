<?php

namespace App\Http\Requests\Discount;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // One tier per threshold keeps the single-best-discount
            // resolution deterministic (DiscountService::resolveForNights).
            'min_nights' => ['required', 'integer', 'min:1', Rule::unique('discounts', 'min_nights')],
            // Percentage of each night's base price. Has to be strictly
            // positive (a 0% discount is a no-op definition) and <= 100.
            'percent_off' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ];
    }
}