<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\IndexRequest;
use App\Http\Requests\Property\StoreRequest;
use App\Http\Requests\Property\UpdateRequest;
use App\Models\Property;
use App\Services\PropertyService;
use App\Support\ApiResponse;
use App\Support\PropertyScope;
use Illuminate\Http\JsonResponse;

/**
 * Location feature (spec §7.1): property CRUD. Two-layer auth like the
 * rest of the staff surface - route middleware gates the role, and the
 * PropertyPolicy re-check runs first inside every method. Receptionists
 * are hard-scoped by PropertyScope to their assigned property (spec
 * §8.1); the list returns exactly their row, shaped like the service's
 * list rows so the frontend renders it the same way.
 */
class PropertyController extends Controller
{
    public function index(IndexRequest $request, PropertyService $service): JsonResponse
    {
        $this->authorize('viewAny', Property::class);

        // Only receptionists get a non-null effective id here: IndexRequest
        // has no property_id key, so admin/manager requests always pass
        // null through PropertyScope.
        $propertyId = PropertyScope::effectiveId($request->user(), $request->validated('property_id'));

        if ($propertyId !== null) {
            return ApiResponse::paginated(
                Property::whereKey($propertyId)->withCount(['buildings', 'rooms'])->paginate(15)
            );
        }

        return ApiResponse::paginated($service->paginate($request->validated()));
    }

    public function show(Property $property, PropertyService $service): JsonResponse
    {
        $this->authorize('view', $property);

        return ApiResponse::success($service->detail($property));
    }

    public function store(StoreRequest $request, PropertyService $service): JsonResponse
    {
        $this->authorize('create', Property::class);

        return ApiResponse::success($service->create($request->validated()), 'Property created.', 201);
    }

    public function update(UpdateRequest $request, Property $property, PropertyService $service): JsonResponse
    {
        $this->authorize('update', $property);

        return ApiResponse::success($service->update($property, $request->validated()), 'Property updated.');
    }

    public function destroy(Property $property, PropertyService $service): JsonResponse
    {
        $this->authorize('delete', $property);

        $service->delete($property);

        return ApiResponse::success(null, 'Property deleted.');
    }
}
