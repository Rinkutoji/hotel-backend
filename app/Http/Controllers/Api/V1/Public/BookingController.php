<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\AvailabilityRequest;
use App\Services\BookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/public/availability - read-only, no auth. Returns the
 * requested rooms that are available for the dates, plus their
 * snapshot base price (server-side pricing preview, same price the
 * create endpoint would charge).
 */
class BookingController extends Controller
{
    public function availability(AvailabilityRequest $request, BookingService $service): JsonResponse
    {
        $data = $request->validated();

        $rooms = $service->searchAvailable(
            $data['check_in_date'],
            $data['check_out_date'],
            $data['rooms']
        );

        $available = $rooms->map(function ($room) {
            $item = [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'floor' => $room->floor,
                'status' => $room->status,
                'room_type_id' => $room->room_type_id,
                'price_per_night' => $room->roomType->base_price,
            ];

            // STEP 17 (D-4): when the stay qualifies for a pricing tier,
            // disclose the applied discount and the discounted totals the
            // search would present. Absent a qualifying tier there is no
            // "discount" key at all (historical / plain stays unchanged).
            if ($room->discount !== null) {
                $item['discount'] = $room->discount;
            }

            return $item;
        });

        return ApiResponse::success($available, 'OK');
    }
}