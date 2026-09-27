<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Room\IndexRequest;
use App\Http\Requests\Room\StoreRequest;
use App\Http\Requests\Room\UpdateRequest;
use App\Models\Room;
use App\Services\RoomService;
use App\Support\ApiResponse;
use App\Support\PropertyScope;
use Illuminate\Http\JsonResponse;

/**
 * CRUD only - status transitions live in RoomStatusController. Same
 * two-layer authorization as STEP 06's RoomTypeController:
 * role:admin,manager middleware blocks the route before this class runs
 * at all, $this->authorize() re-checks the same rule via RoomPolicy.
 */
class RoomController extends Controller
{
    public function index(IndexRequest $request, RoomService $service): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        $filters = $request->validated();
        $propertyId = PropertyScope::effectiveId($request->user(), $filters['property_id'] ?? null);

        if ($propertyId !== null) {
            $filters['property_id'] = $propertyId;
        }

        return ApiResponse::paginated($service->paginate($filters));
    }

    public function show(Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        return ApiResponse::success($room->load('roomType'));
    }

    public function store(StoreRequest $request, RoomService $service): JsonResponse
    {
        $this->authorize('create', Room::class);

        $room = $service->create($request->validated());

        return ApiResponse::success($room, 'Room created.', 201);
    }

    public function update(UpdateRequest $request, Room $room, RoomService $service): JsonResponse
    {
        $this->authorize('update', $room);

        $room = $service->update($room, $request->validated());

        return ApiResponse::success($room, 'Room updated.');
    }

    public function destroy(Room $room, RoomService $service): JsonResponse
    {
        $this->authorize('delete', $room);

        $service->delete($room);

        return ApiResponse::success(null, 'Room deleted.');
    }
}
