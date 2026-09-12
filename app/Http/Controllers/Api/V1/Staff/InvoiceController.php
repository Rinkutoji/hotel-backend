<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\FilterRequest;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * STEP 16 - staff invoice endpoints (read-only). Any staff role can list/
 * show any invoice - the same read tiering as staff payments, and Invoice
 * is never writable from any surface.
 */
class InvoiceController extends Controller
{
    public function index(FilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()->with('payment.booking.customer', 'items');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('payment.booking', function ($booking) use ($search) {
                    $booking->where('booking_number', 'like', '%' . $search . '%')
                        ->orWhereHas('customer', function ($customer) use ($search) {
                            $customer->where('name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                });
            });
        }

        $invoices = $query->orderByDesc('issued_at')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return ApiResponse::paginated($invoices);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success($invoice->load('payment.booking.customer', 'items'));
    }
}