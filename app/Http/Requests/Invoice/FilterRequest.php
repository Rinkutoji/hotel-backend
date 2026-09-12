<?php

namespace App\Http\Requests\Invoice;

use App\Http\Requests\ApiFormRequest;

class FilterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // Invoices have no status - a client sending one is shape-invalid
            // (docs/STEP-16-IMPLEMENTATION-PLAN.md §13 test 14).
            'status' => ['prohibited'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}