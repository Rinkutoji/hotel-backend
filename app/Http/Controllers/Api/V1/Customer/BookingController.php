<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelRequest;
use App\Http\Requests\Booking\CreateBookingRequest;
use App\Http\Requests\Booking\FilterRequest;
use App\Models\Booking;
use App\Services\BookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer booking endpoints. All handlers enforce ownership twice:
 * $this->authorize() (BookingPolicy) AND query scoping on the customer_id
 * - a bad route can never leak another customer's bookings.
 */
class BookingController extends Controller
{
    public function store(CreateBookingRequest $request, BookingService $service): JsonResponse
    {
        $this->authorize('create', Booking::class);

        $booking = $service->create($request->validated(), $request->user()->id);

        // STEP 17 (D-4): disclose an applied pricing tier in the booking
        // create response. The summary is transient (never persisted), so
        // show/list responses carry no such key.
        $data = $booking->toArray();
        if ($service->discountSummary() !== null) {
            $data['discount'] = $service->discountSummary();
        }

        return ApiResponse::success($data, 'Booking created.', 201);
    }

    public function index(Request $request, BookingService $service): JsonResponse
    {
        $customer = $request->user();

        $query = Booking::query()
            ->with(['bookingRooms.room', 'bookingRooms.roomType'])
            ->where('customer_id', $customer->id);

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $bookings = $query->orderByDesc('created_at')->paginate(15);

        return ApiResponse::paginated($bookings);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $customer = $request->user();

        // Query scoping first - another customer's booking resolves to a
        // clean 404 here (ModelNotFoundException), matching the API
        // contract "404 unless own" (docs/STEP-09-BOOKING-PLAN.md §2).
        $booking = Booking::where('customer_id', $customer->id)->findOrFail($booking->id);

        $this->authorize('view', $booking);

        return ApiResponse::success($booking->load('bookingRooms.room', 'bookingRooms.roomType', 'customer'));
    }

    public function cancel(CancelRequest $request, Booking $booking, BookingService $service): JsonResponse
    {
        $customer = $request->user();

        // Same scoped lookup - a customer can never act on another
        // customer's booking, regardless of route shape.
        $booking = Booking::where('customer_id', $customer->id)->findOrFail($booking->id);

        $this->authorize('cancel', $booking);

        $booking = $service->cancelByCustomer($booking, $request->validated('reason'));

        return ApiResponse::success($booking, 'Booking cancelled.');
    }
}