<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomType\IndexRequest;
use App\Models\RoomType;
use App\Services\RoomTypeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Deliberately calls no auth:sanctum, no actor middleware, and no
 * $this->authorize() against RoomTypePolicy - public browsing must not
 * be blocked by staff management authorization (that Policy is
 * type-hinted against Employee and is never consulted here). See
 * docs/STEP-06-ROOM-TYPE-PLAN.md §4.
 */
class RoomTypeController extends Controller
{
    /**
     * GET /api/v1/public/room-types
     */
    public function index(IndexRequest $request, RoomTypeService $service): JsonResponse
    {
        return ApiResponse::paginated($service->paginate($request->validated()));
    }

    /**
     * GET /api/v1/public/room-types/{roomType}
     *
     * Implicit route-model-binding automatically excludes soft-deleted
     * rows (Eloquent's SoftDeletes global scope applies to the query
     * binding uses) - a deleted room type's ID resolves to a clean 404
     * with no extra code needed here.
     */
    public function show(RoomType $roomType): JsonResponse
    {
        return ApiResponse::success($roomType);
    }
}
