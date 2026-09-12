<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * No auth, no $this->authorize() - public browsing must not require
 * staff authentication, same pattern as every other public controller
 * since STEP 06.
 */
class RoomImageController extends Controller
{
    /**
     * GET /api/v1/public/room-types/{roomType}/images
     *
     * Soft-deleted room types 404 automatically via implicit route-model
     * -binding + SoftDeletes' global scope - no extra code needed.
     * Images already come back sort_order asc, id asc via the
     * relationship's own ordering (RoomType::images()).
     */
    public function index(RoomType $roomType): JsonResponse
    {
        return ApiResponse::success($roomType->images);
    }
}
