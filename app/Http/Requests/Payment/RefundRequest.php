<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

/**
 * STEP 11 - refund request. Shape-only validation (ApiFormRequest
 * convention): reason is the only accepted field. Whether the requesting
 * user may refund at all is answered by PaymentPolicy::refund / the
 * process-refund Gate, not here.
 */
class RefundRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
