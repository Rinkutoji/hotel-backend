<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomType\IndexRequest;
use App\Http\Requests\RoomType\StoreRequest;
use App\Http\Requests\RoomType\UpdateRequest;
use App\Models\RoomType;
use App\Services\RoomTypeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * View/list: any authenticated employee (role:* not applied at the
 * route level for these two). Create/update/delete: role:admin,manager
 * middleware blocks the route before this class runs at all; the
 * $this->authorize() calls below re-check the same rule via
 * RoomTypePolicy as a second, resource-level layer - see
 * docs/STEP-06-ROOM-TYPE-PLAN.md §4 for why both layers are deliberate.
 */
class RoomTypeController extends Controller
{
    public function index(IndexRequest $request, RoomTypeService $service): JsonResponse
    {
        $this->authorize('viewAny', RoomType::class);

        return ApiResponse::paginated($service->paginate($request->validated()));
    }

    public function show(RoomType $roomType): JsonResponse
    {
        $this->authorize('view', $roomType);

        return ApiResponse::success($roomType);
    }

    public function store(StoreRequest $request, RoomTypeService $service): JsonResponse
    {
        $this->authorize('create', RoomType::class);

        $roomType = $service->create($request->validated());

        return ApiResponse::success($roomType, 'Room type created.', 201);
    }

    public function update(UpdateRequest $request, RoomType $roomType, RoomTypeService $service): JsonResponse
    {
        $this->authorize('update', $roomType);

        $roomType = $service->update($roomType, $request->validated());

        return ApiResponse::success($roomType, 'Room type updated.');
    }

    public function destroy(RoomType $roomType, RoomTypeService $service): JsonResponse
    {
        $this->authorize('delete', $roomType);

        $service->delete($roomType);

        return ApiResponse::success(null, 'Room type deleted.');
    }
}
