<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Room\IndexRequest;
use App\Models\Room;
use App\Services\RoomService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Shows all non-deleted rooms regardless of current status - no
 * status-based "availability" filtering. True booking-availability
 * (status + date range) is Booking-module territory (STEP 10+);
 * approximating it here would be exactly the "don't fake the workflow"
 * instruction applied to the wrong module. See
 * docs/STEP-07-ROOM-PLAN.md §1.
 */
class RoomController extends Controller
{
    /**
     * GET /api/v1/public/rooms
     */
    public function index(IndexRequest $request, RoomService $service): JsonResponse
    {
        return ApiResponse::paginated($service->paginate($request->validated()));
    }

    /**
     * GET /api/v1/public/rooms/{room}
     */
    public function show(Room $room): JsonResponse
    {
        return ApiResponse::success($room->load('roomType'));
    }
}
