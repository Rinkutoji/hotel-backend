<?php

namespace App\Http\Requests\RoomType;

use App\Http\Requests\ApiFormRequest;

/**
 * Shared by both the public and staff index endpoints - the
 * search/filter/sort/pagination behavior is identical for both, only
 * the underlying authorization differs (handled by the controller/
 * middleware, not this class).
 */
class IndexRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_adults' => ['nullable', 'integer', 'min:0'],
            'min_children' => ['nullable', 'integer', 'min:0'],
            // Allowlist - an invalid value is rejected here (422) before
            // it ever reaches the query, not silently defaulted.
            'sort' => ['nullable', 'string', 'in:name,base_price,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
