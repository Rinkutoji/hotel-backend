<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

class UploadReceiptRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'receipt' => [
                'required',
                'file',
                'mimes:' . implode(',', config('payment.receipt_mimes', ['jpeg', 'png', 'pdf'])),
                'max:' . (int) config('payment.receipt_max_kb', 4096),
            ],
        ];
    }
}