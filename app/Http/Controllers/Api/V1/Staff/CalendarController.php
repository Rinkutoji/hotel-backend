<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\BookingsRequest;
use App\Models\Booking;
use App\Support\ApiResponse;
use App\Support\PropertyScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * Staff booking calendar. Visible to every staff role (admin, manager,
 * receptionist - D-1): any staff member can see bookings, matching
 * BookingPolicy::viewAny. Returns the whole overlapping window
 * non-paginated so the calendar can render it directly.
 *
 * The window uses the approved strict stay-overlap predicate
 * (check_in_date < range_end AND check_out_date > range_start, the same
 * shape as BookingService's holding-check) so stays that cross the month
 * edges are kept. Comparisons are made against midnight-or-next-midnight
 * datetime bounds, not bare date strings, so the DATE/TEXT storage of
 * the check_in/check_out columns compares identically on both engines
 * (SQLite test DB and MySQL runtime).
 */
class CalendarController extends Controller
{
    /**
     * GET /api/v1/staff/calendar/bookings
     */
    public function bookings(BookingsRequest $request): JsonResponse
    {
        // BookingPolicy::viewAny - any Employee is allowed (the route
        // already restricts the actor to staff).
        $this->authorize('viewAny', Booking::class);

        $from = Carbon::parse($request->validated('from'))->startOfDay();
        $to = Carbon::parse($request->validated('to'))->startOfDay();

        $propertyId = PropertyScope::effectiveId($request->user(), $request->validated('property_id'));

        $bookings = Booking::query()
            ->with(['bookingRooms.room', 'bookingRooms.roomType', 'customer'])
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($propertyId, fn ($q) => $q->byProperty($propertyId))
            ->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from)
            ->orderBy('check_in_date')
            ->orderBy('check_out_date')
            ->get();

        return ApiResponse::success($bookings, 'Calendar bookings retrieved.');
    }
}
