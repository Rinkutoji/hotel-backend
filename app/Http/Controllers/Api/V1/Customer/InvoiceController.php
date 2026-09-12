<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\FilterRequest;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 16 - customer invoice endpoints (read-only, own invoices only).
 * Ownership is enforced twice - InvoicePolicy AND query scoping on
 * payment.booking.customer_id - so one customer can never observe another
 * customer's invoice (404, never 403/200). Invoices are immutable; Money,
 * invoice_number and issued_at can never be sent from here.
 */
class InvoiceController extends Controller
{
    public function index(FilterRequest $request): JsonResponse
    {
        $customer = $request->user();

        $invoices = Invoice::query()
            ->with('payment.booking.customer', 'items')
            ->whereHas('payment.booking', function ($query) use ($customer) {
                $query->where('customer_id', $customer->id);
            })
            ->orderByDesc('issued_at')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return ApiResponse::paginated($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->scopedOwnedInvoice($request, $invoice);

        $this->authorize('view', $invoice);

        return ApiResponse::success($invoice->load('payment.booking.customer', 'items'));
    }

    private function scopedOwnedInvoice(Request $request, Invoice $invoice): Invoice
    {
        return Invoice::query()
            ->whereHas('payment.booking', function ($query) use ($request) {
                $query->where('customer_id', $request->user()->id);
            })
            ->findOrFail($invoice->id);
    }
}