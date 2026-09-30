<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\BookingsRequest;
use App\Http\Requests\Report\ReportExportRequest;
use App\Http\Requests\Report\ReportWindowRequest;
use App\Http\Requests\Report\RevenueRequest;
use App\Http\Requests\Report\RoomsRequest;
use App\Policies\ReportPolicy;
use App\Services\ReportService;
use App\Support\ApiResponse;
use App\Support\Csv;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dynamic Reports (D-1): admin + manager only. The route carries
 * role:admin,manager, then ReportPolicy::viewAny re-checks the same
 * rule here - the approved two-layer authorization pattern. Report
 * aggregation lives entirely in ReportService (server-side SQL).
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    /**
     * GET /api/v1/staff/reports/bookings
     */
    public function bookings(BookingsRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        $data = $this->reports->bookings(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('group_by', 'day'),
            $request->validated('status'),
            $request->validated('property_id'),
            $request->validated('building_id'),
            $request->validated('room_type_id'),
        );

        return ApiResponse::success($data, 'Bookings report generated.');
    }

    /**
     * GET /api/v1/staff/reports/revenue
     */
    public function revenue(RevenueRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        $data = $this->reports->revenue(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('group_by', 'day'),
            $request->validated('property_id'),
            $request->validated('building_id'),
        );

        return ApiResponse::success($data, 'Revenue report generated.');
    }

    /**
     * GET /api/v1/staff/reports/rooms
     */
    public function rooms(RoomsRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        $data = $this->reports->rooms(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('property_id'),
            $request->validated('building_id'),
        );

        return ApiResponse::success($data, 'Room inventory report generated.');
    }

    /**
     * Second authorization layer for a capability with no bound model:
     * directly enforce ReportPolicy (the same role:admin,manager rule
     * already applied by the route middleware).
     */
    private function authorizeReports($user): void
    {
        if (! app(ReportPolicy::class)->viewAny($user)) {
            throw new AuthorizationException('You do not have permission to view reports.');
        }
    }

    public function guests(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->guests($request->validated('from'), $request->validated('to'), $request->validated('property_id')),
            'Guest report generated.'
        );
    }

    public function sources(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->sources($request->validated('from'), $request->validated('to'), $request->validated('property_id')),
            'Reservation source report generated.'
        );
    }

    public function checkIns(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->checkIns(
                $request->validated('from'),
                $request->validated('to'),
                $request->validated('group_by', 'day'),
                $request->validated('property_id'),
                $request->validated('building_id'),
            ),
            'Check-in report generated.'
        );
    }

    public function checkOuts(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->checkOuts(
                $request->validated('from'),
                $request->validated('to'),
                $request->validated('group_by', 'day'),
                $request->validated('property_id'),
                $request->validated('building_id'),
            ),
            'Check-out report generated.'
        );
    }

    public function payments(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->payments(
                $request->validated('from'),
                $request->validated('to'),
                $request->validated('group_by', 'day'),
                $request->validated('property_id'),
                $request->validated('building_id'),
            ),
            'Payments report generated.'
        );
    }

    public function outstanding(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->outstanding(
                $request->validated('property_id'),
                $request->validated('building_id')
            ),
            'Outstanding payments report generated.'
        );
    }

    public function housekeeping(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->housekeeping($request->validated('from'), $request->validated('to'), $request->validated('property_id'), $request->validated('building_id')),
            'Housekeeping report generated.'
        );
    }

    public function maintenance(ReportWindowRequest $request): JsonResponse
    {
        $this->authorizeReports($request->user());

        return ApiResponse::success(
            $this->reports->maintenance($request->validated('from'), $request->validated('to'), $request->validated('property_id'), $request->validated('building_id')),
            'Maintenance report generated.'
        );
    }

    /**
     * GET /api/v1/staff/reports/{report}/export - stream any report type
     * as CSV, rebuilt from the exact same filters as its JSON endpoint.
     */
    public function export(ReportExportRequest $request, string $report): StreamedResponse
    {
        $this->authorizeReports($request->user());

        $data = $this->dataFor($report, $request);

        return response()->streamDownload(
            function () use ($data): void {
                echo Csv::fromJsonFlat($data);
            },
            "report-{$report}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function dataFor(string $report, ReportExportRequest $request): array
    {
        $from = $request->validated('from');
        $to = $request->validated('to');
        $groupBy = $request->validated('group_by', 'day');
        $status = $request->validated('status');
        $propertyId = $request->validated('property_id');
        $buildingId = $request->validated('building_id');
        $roomTypeId = $request->validated('room_type_id');

        return match ($report) {
            'bookings' => $this->reports->bookings($from, $to, $groupBy, $status, $propertyId, $buildingId, $roomTypeId),
            'revenue' => $this->reports->revenue($from, $to, $groupBy, $propertyId, $buildingId),
            'rooms' => $this->reports->rooms($from, $to, $propertyId, $buildingId),
            'guests' => $this->reports->guests($from, $to, $propertyId),
            'sources' => $this->reports->sources($from, $to, $propertyId),
            'check-ins' => $this->reports->checkIns($from, $to, $groupBy, $propertyId, $buildingId),
            'check-outs' => $this->reports->checkOuts($from, $to, $groupBy, $propertyId, $buildingId),
            'payments' => $this->reports->payments($from, $to, $groupBy, $propertyId, $buildingId),
            'outstanding' => $this->reports->outstanding($propertyId, $buildingId),
            'housekeeping' => $this->reports->housekeeping($from, $to, $propertyId, $buildingId),
            'maintenance' => $this->reports->maintenance($from, $to, $propertyId, $buildingId),
            default => throw new \InvalidArgumentException('Unknown report type.'),
        };
    }
}
