<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomImage\UpdateRequest;
use App\Http\Requests\RoomImage\UploadRequest;
use App\Models\RoomImage;
use App\Models\RoomType;
use App\Services\RoomImageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * index/store are nested under room-types (matches
 * GET/POST .../room-types/{roomType}/images). update/destroy are not -
 * they identify the image by its own id (matches
 * PUT/DELETE .../room-images/{image}), so route parameter and method
 * parameter are both named $image, not $roomImage.
 */
class RoomImageController extends Controller
{
    public function index(RoomType $roomType): JsonResponse
    {
        $this->authorize('viewAny', RoomImage::class);

        return ApiResponse::success($roomType->images);
    }

    public function store(UploadRequest $request, RoomType $roomType, RoomImageService $service): JsonResponse
    {
        $this->authorize('create', RoomImage::class);

        $image = $service->upload($roomType, $request->file('image'));

        return ApiResponse::success($image, 'Image uploaded.', 201);
    }

    public function update(UpdateRequest $request, RoomImage $image, RoomImageService $service): JsonResponse
    {
        $this->authorize('update', $image);

        $image = $service->updateSortOrder($image, $request->validated('sort_order'));

        return ApiResponse::success($image, 'Image updated.');
    }

    public function destroy(RoomImage $image, RoomImageService $service): JsonResponse
    {
        $this->authorize('delete', $image);

        $service->delete($image);

        return ApiResponse::success(null, 'Image deleted.');
    }
}
