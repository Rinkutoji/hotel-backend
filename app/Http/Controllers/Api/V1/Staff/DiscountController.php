<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discount\FilterRequest;
use App\Http\Requests\Discount\StoreRequest;
use App\Http\Requests\Discount\UpdateRequest;
use App\Models\Discount;
use App\Services\DiscountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * STEP 17 - discount tier definitions. Admin+manager (D-5 approved value):
 * role:admin,manager route middleware blocks this group before the class
 * runs, and $this->authorize() re-checks the same rule via DiscountPolicy as
 * the second, resource-level layer - the project's two-layer convention.
 *
 * These rows are master data consulted at booking time only; editing or
 * deleting them never mutates historical bookings (the discounted nightly
 * rate is snapshotted into booking_rooms.price_per_night).
 */
class DiscountController extends Controller
{
    public function index(FilterRequest $request, DiscountService $service): JsonResponse
    {
        $this->authorize('viewAny', Discount::class);

        return ApiResponse::paginated($service->paginate($request->validated()));
    }

    public function show(Discount $discount): JsonResponse
    {
        $this->authorize('view', $discount);

        return ApiResponse::success($discount);
    }

    public function store(StoreRequest $request, DiscountService $service): JsonResponse
    {
        $this->authorize('create', Discount::class);

        return ApiResponse::success($service->create($request->validated()), 'Discount created.', 201);
    }

    public function update(UpdateRequest $request, Discount $discount, DiscountService $service): JsonResponse
    {
        $this->authorize('update', $discount);

        return ApiResponse::success($service->update($discount, $request->validated()), 'Discount updated.');
    }

    public function destroy(Discount $discount, DiscountService $service): JsonResponse
    {
        $this->authorize('delete', $discount);

        $service->delete($discount);

        return ApiResponse::success(null, 'Discount deleted.');
    }
}