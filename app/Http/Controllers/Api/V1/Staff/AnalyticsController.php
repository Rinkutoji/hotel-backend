<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\IndexRequest;
use App\Policies\AnalyticsPolicy;
use App\Services\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * Analytics dashboard feed - single GET returning every chart + KPI
 * payload the React frontend needs. role:admin,manager on the route
 * plus AnalyticsPolicy second layer (two-layer convention).
 */
class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    public function index(IndexRequest $request): JsonResponse
    {
        $this->authorizeAnalytics($request->user());

        $data = $this->analytics->analytics(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('group_by', 'day'),
            $request->validated('property_id'),
            $request->validated('building_id'),
            $request->validated('room_type_id'),
        );

        return ApiResponse::success($data, 'Analytics generated.');
    }

    private function authorizeAnalytics($user): void
    {
        if (! app(AnalyticsPolicy::class)->viewAny($user)) {
            throw new AuthorizationException('You do not have permission to view analytics.');
        }
    }
}
