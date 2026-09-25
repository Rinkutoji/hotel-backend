<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Room\ChangeStatusRequest;
use App\Models\Room;
use App\Services\RoomService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Only the two STEP 07-approved transitions exist here. Booking-driven
 * destinations (reserved, occupied, cleaning) are deliberately not
 * implemented - see docs/STEP-07-ROOM-PLAN.md §2. An invalid transition
 * (e.g. calling toAvailable() on a room that's 'occupied') is rejected
 * by RoomService::transitionStatus() with a 409, not checked here.
 */
class RoomStatusController extends Controller
{
    /**
     * POST /api/v1/staff/rooms/{room}/status/maintenance
     */
    public function toMaintenance(ChangeStatusRequest $request, Room $room, RoomService $service): JsonResponse
    {
        $this->authorize('changeStatus', $room);

        $room = $service->transitionStatus($room, 'maintenance', $request->user(), $request->validated('reason'));

        return ApiResponse::success($room, 'Room marked for maintenance.');
    }

    /**
     * POST /api/v1/staff/rooms/{room}/status/available
     */
    public function toAvailable(ChangeStatusRequest $request, Room $room, RoomService $service): JsonResponse
    {
        $this->authorize('changeStatus', $room);

        $room = $service->transitionStatus($room, 'available', $request->user(), $request->validated('reason'));

        return ApiResponse::success($room, 'Room marked available.');
    }
}
