<?php

/*
|--------------------------------------------------------------------------
| Payment Business Rules
|--------------------------------------------------------------------------
|
| Numbers here are read via config('payment.xxx') everywhere in the app -
| never env() directly outside this file. Receipt upload limits are enforced
| in UploadReceiptRequest, not in the service.
|
*/

return [

    // Maximum receipt upload size in kilobytes (docs/STEP-10-PAYMENT-PLAN.md §6).
    'receipt_max_kb' => (int) env('PAYMENT_RECEIPT_MAX_KB', 4096),

    // Allowed receipt MIME types. Enforced in UploadReceiptRequest.
    'receipt_mimes' => ['jpeg', 'png', 'pdf'],

];