<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentRequest;
use App\Http\Requests\Payment\FilterRequest;
use App\Http\Requests\Payment\UploadReceiptRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Customer payment endpoints. Ownership is enforced twice - PaymentPolicy
 * AND query scoping on booking.customer_id - so one customer can never
 * observe or act on another customer's payment (404, never 403/200).
 * Money, status and verification fields can never be sent from here.
 */
class PaymentController extends Controller
{
    public function index(FilterRequest $request): JsonResponse
    {
        $customer = $request->user();

        $payments = Payment::query()
            ->with('booking')
            ->whereHas('booking', function ($query) use ($customer) {
                $query->where('customer_id', $customer->id);
            })
            ->when($request->validated('status'), function ($query, string $status) {
                $query->where('status', $status);
            })
            ->orderByDesc('created_at')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return ApiResponse::paginated($payments);
    }

    public function store(CreatePaymentRequest $request, PaymentService $service): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $booking = Booking::where('customer_id', $request->user()->id)
            ->findOrFail($request->validated('booking_id'));

        $payment = $service->create($booking, $request->validated('method'));

        return ApiResponse::success($payment, 'Payment created.', 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $payment = $this->scopedOwnedPayment($request, $payment);

        $this->authorize('view', $payment);

        return ApiResponse::success($payment->load('booking', 'verifiedBy'));
    }

    public function uploadReceipt(UploadReceiptRequest $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $payment = $this->scopedOwnedPayment($request, $payment);

        $this->authorize('uploadReceipt', $payment);

        $payment = $service->uploadReceipt($payment, $request->file('receipt'));

        return ApiResponse::success($payment, 'Receipt uploaded.');
    }

    public function downloadReceipt(Request $request, Payment $payment)
    {
        $payment = $this->scopedOwnedPayment($request, $payment);

        $this->authorize('downloadReceipt', $payment);

        $path = $payment->receipt_path;

        if ($path === null || ! Storage::disk('receipts')->exists($path)) {
            return ApiResponse::error('Receipt not found.', null, 404);
        }

        return Storage::disk('receipts')->response($path);
    }

    private function scopedOwnedPayment(Request $request, Payment $payment): Payment
    {
        return Payment::query()
            ->whereHas('booking', function ($query) use ($request) {
                $query->where('customer_id', $request->user()->id);
            })
            ->findOrFail($payment->id);
    }
}