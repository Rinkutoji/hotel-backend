<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\IndexRequest;
use App\Policies\AnalyticsPolicy;
use App\Services\AnalyticsService;
use App\Support\ApiResponse;
use App\Support\Csv;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin Dashboard feed - high-level hotel overview. One GET returns every
 * KPI + chart payload the upgraded Dashboard mounts. role:admin,manager on
 * the route plus AnalyticsPolicy as the second layer (same two-layer
 * convention as /staff/analytics). The export route streams the same
 * filtered payload as CSV.
 */
class DashboardController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    public function index(IndexRequest $request): JsonResponse
    {
        $this->authorizeDashboard($request->user());

        $data = $this->analytics->dashboard(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('group_by', 'day'),
            $request->validated('property_id'),
        );

        return ApiResponse::success($data, 'Dashboard generated.');
    }

    public function export(IndexRequest $request): StreamedResponse
    {
        $this->authorizeDashboard($request->user());

        $data = $this->analytics->dashboard(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('group_by', 'day'),
            $request->validated('property_id'),
        );

        $csv = $this->flatten($data);

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            'dashboard-'.$request->validated('from').'_'.$request->validated('to').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function authorizeDashboard($user): void
    {
        if (! app(AnalyticsPolicy::class)->viewAny($user)) {
            throw new AuthorizationException('You do not have permission to view the dashboard.');
        }
    }

    /**
     * Flatten the dashboard payload into a single CSV table. Each JSON
     * section becomes a labeled block of rows with consistent columns so
     * the download stays legible in Excel.
     */
    private function flatten(array $data): string
    {
        $rows = [];

        $rows[] = ['section' => 'window', 'window_from' => $data['window']['from'], 'window_to' => $data['window']['to'], 'group_by' => $data['window']['group_by']];

        foreach ($data['inventory'] as $key => $value) {
            $rows[] = ['section' => 'inventory', 'metric' => $key, 'value' => $value];
        }

        foreach ($data['todays'] as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $sub => $subValue) {
                    $rows[] = ['section' => "todays.{$key}", 'metric' => $sub, 'value' => $subValue];
                }

                continue;
            }
            $rows[] = ['section' => 'todays', 'metric' => $key, 'value' => $value];
        }

        foreach ($data['guests'] as $key => $value) {
            $rows[] = ['section' => 'guests', 'metric' => $key, 'value' => $value];
        }

        foreach (['booking_source' => 'source', 'payment_methods' => 'method', 'booking_status' => 'status', 'payment_status' => 'status', 'room_occupancy' => 'status'] as $section => $keyName) {
            foreach ($data[$section] as $entry) {
                $label = $entry[$keyName];
                $sub = array_filter($entry, fn (string $k) => $k !== $keyName, ARRAY_FILTER_USE_KEY);
                $rows[] = ['section' => $section, 'metric' => $label, 'value' => $sub['count'] ?? $sub['percentage'] ?? ''];
            }
        }

        foreach ($data['room_types'] as $type) {
            $rows[] = ['section' => 'room_types', 'metric' => $type['name'], 'value' => "bookings={$type['bookings']}; revenue={$type['revenue']}; occupancy={$type['occupancy']}%"];
        }

        foreach ($data['booking_trend'] as $point) {
            $rows[] = ['section' => 'booking_trend', 'metric' => $point['period'], 'value' => $point['bookings']];
        }
        foreach ($data['revenue'] as $point) {
            $rows[] = ['section' => 'revenue', 'metric' => $point['period'], 'value' => "revenue={$point['revenue']}; refunds={$point['refunds']}; net={$point['net']}"];
        }
        foreach ($data['check_in_out'] as $point) {
            $rows[] = ['section' => 'check_in_out', 'metric' => $point['period'], 'value' => "check_ins={$point['check_ins']}; check_outs={$point['check_outs']}"];
        }
        foreach ($data['occupancy_trend'] as $point) {
            $rows[] = ['section' => 'occupancy_trend', 'metric' => $point['period'], 'value' => "occupied_nights={$point['occupied_room_nights']}; capacity_nights={$point['capacity_room_nights']}; pct={$point['occupancy_pct']}%"];
        }

        return Csv::fromAssocRows($rows);
    }
}
