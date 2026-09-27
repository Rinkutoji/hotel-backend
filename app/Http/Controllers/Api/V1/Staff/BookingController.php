<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelRequest;
use App\Http\Requests\Booking\FilterRequest;
use App\Models\Booking;
use App\Services\BookingService;
use App\Support\ApiResponse;
use App\Support\PropertyScope;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    public function index(FilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $query = Booking::query()
            ->with(['bookingRooms.room', 'bookingRooms.roomType', 'customer']);

        $propertyId = PropertyScope::effectiveId($request->user(), $request->validated('property_id'));

        if ($propertyId !== null) {
            $query->byProperty($propertyId);
        }

        if ($request->validated('building_id')) {
            $query->byBuilding((int) $request->validated('building_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->where('check_in_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('check_out_date', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('booking_number', 'like', '%' . $request->input('search') . '%')
                    ->orWhereHas('customer', function ($cq) use ($request) {
                        $cq->where('name', 'like', '%' . $request->input('search') . '%')
                            ->orWhere('email', 'like', '%' . $request->input('search') . '%');
                    });
            });
        }

        $bookings = $query->orderByDesc('created_at')->paginate(15);

        return ApiResponse::paginated($bookings);
    }

    public function show(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return ApiResponse::success($booking->load('bookingRooms.room', 'bookingRooms.roomType', 'customer'));
    }

    public function confirm(Booking $booking, BookingService $service): JsonResponse
    {
        $this->authorize('confirm', $booking);

        $booking = $service->confirm($booking);

        return ApiResponse::success($booking, 'Booking confirmed.');
    }

    public function cancel(CancelRequest $request, Booking $booking, BookingService $service): JsonResponse
    {
        $this->authorize('cancel', $booking);

        $booking = $service->cancelByStaff($booking, $request->validated('reason') ?? 'Cancelled by staff');

        return ApiResponse::success($booking, 'Booking cancelled.');
    }

    public function checkIn(Booking $booking, BookingService $service): JsonResponse
    {
        $this->authorize('checkIn', $booking);

        $booking = $service->checkIn($booking);

        return ApiResponse::success($booking, 'Guest checked in.');
    }

    public function checkOut(Booking $booking, BookingService $service): JsonResponse
    {
        $this->authorize('checkOut', $booking);

        $booking = $service->checkOut($booking);

        return ApiResponse::success($booking, 'Guest checked out.');
    }
}