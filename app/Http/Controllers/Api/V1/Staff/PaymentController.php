<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\FilterRequest;
use App\Http\Requests\Payment\RefundRequest;
use App\Http\Requests\Payment\RejectPaymentRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use App\Support\PropertyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Staff payment endpoints. The two-layer authorization pattern: route
 * role:* middleware first, then $this->authorize() re-checks the same rule
 * via PaymentPolicy (docs/STEP-10-PAYMENT-PLAN.md §3).
 */
class PaymentController extends Controller
{
    public function index(FilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with('booking.customer');

        $propertyId = PropertyScope::effectiveId($request->user(), $request->validated('property_id'));

        if ($propertyId !== null) {
            $query->byProperty($propertyId);
        }

        if ($request->validated('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('booking', function ($booking) use ($search) {
                    $booking->where('booking_number', 'like', '%' . $search . '%')
                        ->orWhereHas('customer', function ($customer) use ($search) {
                            $customer->where('name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                });
            });
        }

        $payments = $query->orderByDesc('created_at')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return ApiResponse::paginated($payments);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::success($payment->load('booking.customer', 'verifiedBy'));
    }

    public function downloadReceipt(Payment $payment)
    {
        $this->authorize('downloadReceipt', $payment);

        $path = $payment->receipt_path;

        if ($path === null || ! Storage::disk('receipts')->exists($path)) {
            return ApiResponse::error('Receipt not found.', null, 404);
        }

        return Storage::disk('receipts')->response($path);
    }

    public function verify(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $this->authorize('verify', $payment);

        $payment = $service->verify($payment, $request->user());

        return ApiResponse::success($payment, 'Payment verified.');
    }

    public function reject(RejectPaymentRequest $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $this->authorize('reject', $payment);

        $payment = $service->reject($payment, $request->validated('reason'));

        return ApiResponse::success($payment, 'Payment rejected.');
    }

    /**
     * STEP 11 - refund a verified payment. Two-layer authorization: route
     * role:admin,manager middleware first, then PaymentPolicy::refund
     * re-checks via the process-refund Gate. Reason is required and is the
     * only accepted request field.
     */
    public function refund(RefundRequest $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $this->authorize('refund', $payment);

        $payment = $service->refund($payment, $request->validated('reason'));

        return ApiResponse::success($payment, 'Payment refunded.');
    }
}