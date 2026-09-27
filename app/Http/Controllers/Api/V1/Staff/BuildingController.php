<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Building\StoreRequest;
use App\Http\Requests\Building\UpdateRequest;
use App\Models\Building;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Location feature (spec §7.1): building management under a property.
 * Nested create/list routes (properties/{property}/buildings) plus a
 * top-level identified-by-own-id surface for show/update/destroy
 * (room-images convention, plan Task 26 routes). Two-layer auth as
 * everywhere: role middleware on the routes + BuildingPolicy /
 * PropertyPolicy re-checks inside. Receptionists are scoped via the
 * property check.
 */
class BuildingController extends Controller
{
    public function index(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return ApiResponse::paginated($property->buildings()->orderBy('name')->paginate());
    }

    public function store(StoreRequest $request, Property $property): JsonResponse
    {
        $this->authorize('create', Building::class);

        $building = Building::create([
            ...$request->validated(),
            'property_id' => $property->id,
        ]);

        return ApiResponse::success($building, 'Building created.', 201);
    }

    public function show(Building $building): JsonResponse
    {
        $this->authorize('view', $building);

        return ApiResponse::success($building);
    }

    public function update(UpdateRequest $request, Building $building): JsonResponse
    {
        $this->authorize('update', $building);

        $building->update($request->validated());

        return ApiResponse::success($building, 'Building updated.');
    }

    public function destroy(Building $building): JsonResponse
    {
        $this->authorize('delete', $building);

        $building->delete();

        return ApiResponse::success(null, 'Building deleted.');
    }
}
