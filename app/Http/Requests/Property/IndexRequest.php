<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\ApiFormRequest;

/**
 * GET /staff/properties - list filters, mirroring Room/IndexRequest.
 */
class IndexRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'province_state' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            // Allowlist - an invalid value is rejected here (422) before
            // it ever reaches the query.
            'sort' => ['nullable', 'string', 'in:name,code,city,country,status,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
