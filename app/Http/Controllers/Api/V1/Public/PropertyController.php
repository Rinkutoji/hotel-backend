<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public booking-site selector (spec §7.2). Deliberately no auth and no
 * $this->authorize() - public browsing must not be blocked by staff
 * management authorization (same convention as Public\RoomTypeController).
 * Active properties only: the status filter excludes inactive rows and
 * Property's SoftDeletes global scope excludes soft-deleted ones, so
 * nothing but live, active properties ever surfaces publicly.
 */
class PropertyController extends Controller
{
    /**
     * GET /api/v1/public/properties
     */
    public function index(): JsonResponse
    {
        $properties = Property::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'country']);

        return ApiResponse::success($properties, 'OK');
    }
}
