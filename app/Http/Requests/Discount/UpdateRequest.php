<?php

namespace App\Http\Requests\Discount;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'min_nights' => [
                'required', 'integer', 'min:1',
                Rule::unique('discounts', 'min_nights')->ignore($this->route('discount')),
            ],
            'percent_off' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ];
    }
}