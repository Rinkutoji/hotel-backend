<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analytics dashboard feed - the single GET endpoint that backs every
 * chart and KPI card on the frontend analytics page (auto-refreshing).
 *
 * Bargain-baseline rules, all computed in SQL (never paged client-side):
 * - Window is inclusive [from 00:00:00, to 23:59:59].
 * - booking_trend buckets bookings by created_at.
 * - revenue attributes VERIFIED payments by verified_at and refunds by
 *   refunded_at (D-2), so a refund lands in the period it was processed.
 * - booking_status / payment_status count by status within the window.
 * - room_occupancy is a CURRENT snapshot (rooms.status is a live state,
 *   not a dated event - a date window would be meaningless).
 * - room_types.{bookings,revenue,occupancy}: bookings is the count of
 *   distinct bookings in the window that included that type;
 *   revenue is the verified amount allocated to the type by each
 *   booking's price_per_night share (so multi-room bookings never
 *   double-count); occupancy is the current % of that type's rooms
 *   occupied.
 * - summary carries period KPIs plus their % change vs the equal-length
 *   window immediately before `from`.
 *
 * Period series are zero-filled (span day/week/month) so the spark areas
 * render continuous lines even on quiet days, while every non-zero value
 * still comes straight from the database.
 */
class AnalyticsService
{
    public function analytics(string $from, string $to, string $groupBy = 'day',
        ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $summary = $this->summary($start, $end, $propertyId, $buildingId, $roomTypeId);
        $previous = $this->summary(...[...$this->previousRange($from, $to), $propertyId, $buildingId, $roomTypeId]);
        $summary['changes'] = $this->changes($summary, $previous);

        return [
            'summary' => $summary,
            'booking_trend' => $this->bookingTrend($start, $end, $groupBy, $propertyId, $buildingId, $roomTypeId),
            'revenue' => $this->revenueSeries($start, $end, $groupBy, $propertyId, $buildingId),
            'booking_status' => $this->bookingStatusCounts($start, $end, $propertyId, $buildingId, $roomTypeId),
            'payment_status' => $this->paymentStatusCounts($start, $end, $propertyId, $buildingId),
            'room_occupancy' => $this->roomOccupancy($propertyId, $buildingId, $roomTypeId),
            'room_types' => $this->roomTypeStats($start, $end, $propertyId, $buildingId, $roomTypeId),
        ];
    }

    /**
     * Admin Dashboard feed - the "high-level hotel overview" endpoint the
     * Dashboards reacts to. Adds the date-windowed + today-snapshot KPIs,
     * guest metrics, reservation-source split, check-in/out flow, payment
     * method split and the realized occupancy trend on top of the shared
     * analytics series. Everything is one SQL aggregation - the frontend
     * never pages through rows.
     */
    public function dashboard(string $from, string $to, string $groupBy = 'day',
        ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        return [
            'window' => [
                'from' => $from,
                'to' => $to,
                'group_by' => $groupBy,
            ],
            'inventory' => $this->inventorySnapshot($propertyId, $buildingId, $roomTypeId),
            'todays' => $this->todaysSnapshot($propertyId, $buildingId, $roomTypeId),
            'guests' => $this->guestStats($start, $end, $propertyId, $buildingId, $roomTypeId),
            'booking_source' => $this->bookingSourceCounts($start, $end, $propertyId, $buildingId, $roomTypeId),
            'payment_methods' => $this->paymentMethodCounts($start, $end, $propertyId, $buildingId),
            'booking_trend' => $this->bookingTrend($start, $end, $groupBy, $propertyId, $buildingId, $roomTypeId),
            'revenue' => $this->revenueSeries($start, $end, $groupBy, $propertyId, $buildingId),
            'occupancy_trend' => $this->occupancyTrend($start, $end, $groupBy, $propertyId, $buildingId, $roomTypeId),
            'check_in_out' => $this->checkFlow($start, $end, $groupBy, $propertyId, $buildingId, $roomTypeId),
            'booking_status' => $this->bookingStatusCounts($start, $end, $propertyId, $buildingId, $roomTypeId),
            'payment_status' => $this->paymentStatusCounts($start, $end, $propertyId, $buildingId),
            'room_occupancy' => $this->roomOccupancy($propertyId, $buildingId, $roomTypeId),
            'room_types' => $this->roomTypeStats($start, $end, $propertyId, $buildingId, $roomTypeId),
        ];
    }

    // ---------------------------------------------------------------- KPIs

    private function summary(Carbon $start, Carbon $end, ?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $bookings = $this->bookingsIn($start, $end, $propertyId, $buildingId, $roomTypeId);

        $totalBookings = (int) $bookings->count();
        $verifiedRevenue = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$start, $end])
            ->sum('amount');
        $refunds = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$start, $end])
            ->sum('amount');

        $cancelled = (int) (clone $bookings)
            ->where('status', 'cancelled')
            ->count();

        $occupancy = $this->occupancySnapshot($propertyId, $buildingId, $roomTypeId);

        return [
            'total_bookings' => $totalBookings,
            'verified_revenue' => $this->money($verifiedRevenue),
            'refunds' => $this->money($refunds),
            'net_revenue' => $this->money($verifiedRevenue - $refunds),
            'occupancy_rate' => $occupancy['occupied_pct'],
            'rooms_occupied' => $occupancy['occupied'],
            'rooms_total' => $occupancy['total'],
            'average_booking_value' => $totalBookings > 0 ? $this->money($verifiedRevenue / $totalBookings) : '0.00',
            'cancellation_rate' => $totalBookings > 0 ? round($cancelled / $totalBookings * 100, 1) : 0.0,
        ];
    }

    /**
     * % change (1 decimal) of each period KPI against the previous
     * equal-length window. 0/0 -> 0.0; 5/0 -> 100.0 (a real increase).
     */
    private function changes(array $current, array $previous): array
    {
        $rate = fn (float $cur, float $prev): float => $prev != 0
                ? round(($cur - $prev) / $prev * 100, 1)
                : ($cur > 0 ? 100.0 : 0.0);

        return [
            'total_bookings' => $rate((float) $current['total_bookings'], (float) $previous['total_bookings']),
            'verified_revenue' => $rate((float) $current['verified_revenue'], (float) $previous['verified_revenue']),
            'refunds' => $rate((float) $current['refunds'], (float) $previous['refunds']),
            'net_revenue' => $rate((float) $current['net_revenue'], (float) $previous['net_revenue']),
            'average_booking_value' => $rate((float) $current['average_booking_value'], (float) $previous['average_booking_value']),
            'cancellation_rate' => $rate((float) $current['cancellation_rate'], (float) $previous['cancellation_rate']),
        ];
    }

    // ------------------------------------------------------------- trends

    private function bookingTrend(Carbon $start, Carbon $end, string $groupBy,
        ?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $rows = (clone $this->bookingsIn($start, $end, $propertyId, $buildingId, $roomTypeId))
            ->selectRaw($this->periodExpression('created_at', $groupBy).' as period, COUNT(*) as bookings')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        return $this->fillPeriods($start, $end, $groupBy, $rows, ['bookings' => 0]);
    }

    public function revenueSeries(Carbon $start, Carbon $end, string $groupBy,
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        $verified = $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$start, $end])
            ->selectRaw($this->periodExpression('verified_at', $groupBy).' as period, COALESCE(SUM(amount), 0) as revenue')
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $refunded = $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$start, $end])
            ->selectRaw($this->periodExpression('refunded_at', $groupBy).' as period, COALESCE(SUM(amount), 0) as refunds')
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $defaults = ['revenue' => 0, 'refunds' => 0];
        $periods = [];
        foreach ($this->periodKeys($start, $end, $groupBy) as $key) {
            $periods[$key] = ['period' => $key] + $defaults;
        }

        foreach ($periods as $key => $point) {
            $rev = (float) ($verified[$key]->revenue ?? 0);
            $ref = (float) ($refunded[$key]->refunds ?? 0);
            $periods[$key] = [
                'period' => $key,
                'revenue' => $this->money($rev),
                'refunds' => $this->money($ref),
                'net' => $this->money($rev - $ref),
            ];
        }

        return array_values($periods);
    }

    // ------------------------------------------------------------- counts

    private function bookingStatusCounts(Carbon $start, Carbon $end, ?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $counts = (clone $this->bookingsIn($start, $end, $propertyId, $buildingId, $roomTypeId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return $this->statusList(ReportService::BOOKING_STATUSES, $counts);
    }

    private function paymentStatusCounts(Carbon $start, Carbon $end, ?int $propertyId, ?int $buildingId): array
    {
        $counts = $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return $this->statusList(ReportService::PAYMENT_STATUSES, $counts);
    }

    // ------------------------------------------------------------ rooms

    private function roomOccupancy(?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $counts = $this->occupancySnapshot($propertyId, $buildingId, $roomTypeId);

        $list = [];
        foreach (ReportService::ROOM_STATUSES as $status) {
            $list[] = [
                'status' => $status,
                'count' => $counts['by_status'][$status],
                'percentage' => $counts['total'] > 0
                    ? round($counts['by_status'][$status] / $counts['total'] * 100, 1)
                    : 0.0,
            ];
        }

        return $list;
    }

    private function occupancySnapshot(?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $byStatus = array_fill_keys(ReportService::ROOM_STATUSES, 0);
        foreach ($this->scopedRooms(Room::query(), $propertyId, $buildingId)
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status') as $s => $n) {
            if (isset($byStatus[$s])) {
                $byStatus[$s] = (int) $n;
            }
        }

        $total = array_sum($byStatus);

        return [
            'by_status' => $byStatus,
            'total' => $total,
            'occupied' => $byStatus['occupied'],
            'occupied_pct' => $total > 0 ? round($byStatus['occupied'] / $total * 100, 1) : 0.0,
        ];
    }

    // -------------------------------------------------------- room types

    private function roomTypeStats(Carbon $start, Carbon $end, ?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $bookingsByType = DB::table('booking_rooms as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereBetween('b.created_at', [$start, $end])
            ->when($propertyId || $buildingId, fn ($q) => $q
                ->join('rooms as r', 'r.id', '=', 'br.room_id')
                ->join('buildings as bd', 'bd.id', '=', 'r.building_id')
                ->when($propertyId, fn ($jq) => $jq->where('bd.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('r.building_id', $buildingId)))
            ->when($roomTypeId, fn ($q) => $q->where('br.room_type_id', $roomTypeId))
            ->selectRaw('br.room_type_id, COUNT(DISTINCT b.id) as bookings')
            ->groupBy('br.room_type_id')
            ->pluck('bookings', 'room_type_id');

        $revenueByType = $this->roomTypeRevenue($start, $end, $propertyId, $buildingId, $roomTypeId);

        $roomStatusByType = DB::table('rooms')
            ->whereNull('rooms.deleted_at')
            ->when($propertyId || $buildingId, fn ($q) => $q
                ->join('buildings as bd4', 'bd4.id', '=', 'rooms.building_id')
                ->when($propertyId, fn ($jq) => $jq->where('bd4.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('rooms.building_id', $buildingId)))
            ->when($roomTypeId, fn ($q) => $q->where('rooms.room_type_id', $roomTypeId))
            ->selectRaw('rooms.room_type_id, rooms.status, COUNT(*) as n')
            ->groupBy('rooms.room_type_id', 'rooms.status')
            ->get();
        $roomTotal = [];
        $roomOccupied = [];
        foreach ($roomStatusByType as $row) {
            $roomTotal[$row->room_type_id] = ($roomTotal[$row->room_type_id] ?? 0) + (int) $row->n;
            if ($row->status === 'occupied') {
                $roomOccupied[$row->room_type_id] = (int) $row->n;
            }
        }

        return RoomType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (RoomType $type) => [
                'name' => $type->name,
                'bookings' => (int) ($bookingsByType[$type->id] ?? 0),
                'revenue' => $this->money((float) ($revenueByType[$type->id] ?? 0)),
                'occupancy' => ! empty($roomTotal[$type->id])
                    ? round(($roomOccupied[$type->id] ?? 0) / $roomTotal[$type->id] * 100, 1)
                    : 0.0,
            ])
            ->all();
    }

    /**
     * Verified payments within the window, allocating each payment to the
     * room types of its booking by price_per_night share. A booking with
     * one type gets 100% of the payment; a two-type booking splits by
     * each type's total price. Sums back to exactly the verified total.
     */
    private function roomTypeRevenue(Carbon $start, Carbon $end, ?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $rows = DB::table('payments as p')
            ->join('bookings as b', 'b.id', '=', 'p.booking_id')
            ->join('booking_rooms as br', 'br.booking_id', '=', 'b.id')
            ->where('p.status', 'verified')
            ->whereBetween('p.verified_at', [$start, $end])
            ->when($propertyId || $buildingId, fn ($q) => $q
                ->join('rooms as r3', 'r3.id', '=', 'br.room_id')
                ->join('buildings as bd3', 'bd3.id', '=', 'r3.building_id')
                ->when($propertyId, fn ($jq) => $jq->where('bd3.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('r3.building_id', $buildingId)))
            ->when($roomTypeId, fn ($q) => $q->where('br.room_type_id', $roomTypeId))
            ->selectRaw('p.id as payment_id, p.amount, br.room_type_id, br.price_per_night')
            ->get();

        $amountByPayment = [];
        $shareByPayment = [];
        foreach ($rows as $row) {
            $amountByPayment[$row->payment_id] = (float) $row->amount;
            $shareByPayment[$row->payment_id][$row->room_type_id] =
                ($shareByPayment[$row->payment_id][$row->room_type_id] ?? 0) + (float) $row->price_per_night;
        }

        $revenueByType = [];
        foreach ($shareByPayment as $paymentId => $shares) {
            $total = array_sum($shares);
            if ($total <= 0) {
                continue;
            }
            foreach ($shares as $typeId => $share) {
                $revenueByType[$typeId] = ($revenueByType[$typeId] ?? 0)
                    + $amountByPayment[$paymentId] * $share / $total;
            }
        }

        return $revenueByType;
    }

    // -------------------------------------------------------- dashboard

    /**
     * Current room inventory snapshot (same engine as room_occupancy).
     */
    private function inventorySnapshot(?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $snapshot = $this->occupancySnapshot($propertyId, $buildingId, $roomTypeId);
        $byStatus = $snapshot['by_status'];

        return [
            'total' => $snapshot['total'],
            'available' => $byStatus['available'],
            'reserved' => $byStatus['reserved'],
            'occupied' => $byStatus['occupied'],
            'cleaning' => $byStatus['cleaning'],
            'maintenance' => $byStatus['maintenance'],
            'occupied_pct' => $snapshot['occupied_pct'],
        ];
    }

    /**
     * "Today" snapshot KPIs. Date-column lookups use equality against
     * today's date string (engine-safe for DATE columns and SQLite's
     * midnight datetimes) while timestamp columns keep Carbon ranges.
     * Outstanding = every active hold (pending_payment/confirmed) whose
     * deadline has passed without a verified payment - the unpaid balance.
     */
    public function todaysSnapshot(?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $dayStart = $now->copy()->startOfDay();

        $todayVerified = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$dayStart, $now->copy()->endOfDay()])
            ->sum('amount');
        $todayRefunded = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$dayStart, $now->copy()->endOfDay()])
            ->sum('amount');

        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $monthVerified = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$monthStart, $monthEnd])
            ->sum('amount');
        $monthRefunded = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$monthStart, $monthEnd])
            ->sum('amount');

        $outstanding = $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
            ->whereIn('status', ['pending_payment', 'confirmed'])
            ->where('payment_deadline_at', '<', $now)
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'verified'))
            ->selectRaw('COUNT(*) as booking_count, COALESCE(SUM(total_amount), 0) as amount')
            ->first();

        return [
            'check_ins' => $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
                ->whereDate('check_in_date', $today)
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->count(),
            'check_outs' => $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
                ->whereDate('check_out_date', $today)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->count(),
            'reservations' => $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
                ->whereBetween('created_at', [$dayStart, $now->copy()->endOfDay()])
                ->count(),
            'cancelled' => $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
                ->whereBetween('cancelled_at', [$dayStart, $now->copy()->endOfDay()])
                ->count(),
            'revenue' => $this->money($todayVerified),
            'refunds' => $this->money($todayRefunded),
            'net_revenue' => $this->money($todayVerified - $todayRefunded),
            'month_revenue' => $this->money($monthVerified),
            'month_refunds' => $this->money($monthRefunded),
            'month_net_revenue' => $this->money($monthVerified - $monthRefunded),
            'payments_to_review' => $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
                ->whereIn('status', ['pending', 'receipt_uploaded'])
                ->count(),
            'outstanding' => [
                'count' => (int) $outstanding->booking_count,
                'amount' => $this->money((float) $outstanding->amount),
            ],
        ];
    }

    /**
     * Guest analytics for the window. new = a customer whose very first
     * booking was created inside the window; returning = a customer who
     * book(ed) in the window and had at least one booking before it
     * (never double-counted with new); currently_staying = distinct
     * customers on a checked-in booking; total = distinct customers who
     * booked in the window.
     */
    public function guestStats(Carbon $start, Carbon $end,
        ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        $new = DB::table('bookings as b')
            ->join(
                DB::raw('(SELECT customer_id, MIN(created_at) AS first_at FROM bookings GROUP BY customer_id) AS f'),
                'f.customer_id',
                '=',
                'b.customer_id'
            )
            ->whereBetween('f.first_at', [$start, $end])
            ->when($propertyId || $buildingId || $roomTypeId, fn ($q) => $q
                ->join('booking_rooms as br2', 'br2.booking_id', '=', 'b.id')
                ->join('rooms as r2', 'r2.id', '=', 'br2.room_id')
                ->join('buildings as bd2', 'bd2.id', '=', 'r2.building_id')
                ->when($propertyId, fn ($jq) => $jq->where('bd2.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('r2.building_id', $buildingId))
                ->when($roomTypeId, fn ($jq) => $jq->where('br2.room_type_id', $roomTypeId))
                ->distinct())
            ->distinct()
            ->count('b.customer_id');

        $returning = DB::table('bookings as b')
            ->join('bookings as prior', function ($join) use ($start) {
                $join->on('prior.customer_id', '=', 'b.customer_id')
                    ->where('prior.created_at', '<', $start);
            })
            ->whereBetween('b.created_at', [$start, $end])
            ->when($propertyId || $buildingId || $roomTypeId, fn ($q) => $q
                ->join('booking_rooms as br2', 'br2.booking_id', '=', 'b.id')
                ->join('rooms as r2', 'r2.id', '=', 'br2.room_id')
                ->join('buildings as bd2', 'bd2.id', '=', 'r2.building_id')
                ->when($propertyId, fn ($jq) => $jq->where('bd2.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('r2.building_id', $buildingId))
                ->when($roomTypeId, fn ($jq) => $jq->where('br2.room_type_id', $roomTypeId))
                ->distinct())
            ->distinct()
            ->count('b.customer_id');

        $total = $this->bookingsIn($start, $end, $propertyId, $buildingId, $roomTypeId)
            ->distinct()
            ->count('customer_id');

        $currentlyStaying = $this->scopedBookings(
            Booking::where('status', 'checked_in'), $propertyId, $buildingId, $roomTypeId
        )
            ->distinct()
            ->count('customer_id');

        return [
            'new' => (int) $new,
            'returning' => (int) $returning,
            'currently_staying' => (int) $currentlyStaying,
            'total' => (int) $total,
        ];
    }

    /**
     * Reservation-source split for the window, zero-filled against the
     * known source allowlist.
     */
    public function bookingSourceCounts(Carbon $start, Carbon $end,
        ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        $counts = $this->bookingsIn($start, $end, $propertyId, $buildingId, $roomTypeId)
            ->selectRaw('booking_source, COUNT(*) as count')
            ->groupBy('booking_source')
            ->pluck('count', 'booking_source');

        $total = (int) array_sum($counts->all());
        $list = [];
        foreach (ReportService::BOOKING_SOURCES as $source) {
            $count = (int) ($counts[$source] ?? 0);
            $list[] = [
                'source' => $source,
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ];
        }

        return $list;
    }

    /**
     * Payment method volume + amount split for the window.
     */
    public function paymentMethodCounts(Carbon $start, Carbon $end,
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        $rows = $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('method, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        $list = [];
        foreach (ReportService::PAYMENT_METHODS as $method) {
            $count = (int) ($rows[$method]->count ?? 0);
            $amount = (float) ($rows[$method]->amount ?? 0);
            $list[] = [
                'method' => $method,
                'count' => $count,
                'amount' => $this->money($amount),
            ];
        }

        return $list;
    }

    /**
     * Scheduled check-in vs check-out flow per period (planned dates, so
     * future arrivals/departures inside the window still show). Zero-filled
     * continuous axis from the same two group-bys.
     */
    public function checkFlow(Carbon $start, Carbon $end, string $groupBy,
        ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        $checkInKeys = $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
            ->whereDate('check_in_date', '>=', $start->toDateString())
            ->whereDate('check_in_date', '<=', $end->toDateString())
            ->selectRaw($this->periodExpression('check_in_date', $groupBy).' as period, COUNT(*) as count')
            ->groupBy('period')
            ->pluck('count', 'period');

        $checkOutKeys = $this->scopedBookings(Booking::query(), $propertyId, $buildingId, $roomTypeId)
            ->whereDate('check_out_date', '>=', $start->toDateString())
            ->whereDate('check_out_date', '<=', $end->toDateString())
            ->selectRaw($this->periodExpression('check_out_date', $groupBy).' as period, COUNT(*) as count')
            ->groupBy('period')
            ->pluck('count', 'period');

        $periods = [];
        foreach ($this->periodKeys($start, $end, $groupBy) as $key) {
            $periods[$key] = [
                'period' => $key,
                'check_ins' => (int) ($checkInKeys[$key] ?? 0),
                'check_outs' => (int) ($checkOutKeys[$key] ?? 0),
            ];
        }

        return array_values($periods);
    }

    /**
     * Realized occupancy trend: occupied ROOM-NIGHTS (from actual stays
     * only - checked_in/checked_out bookings, true overlap) per period,
     * against the period's capacity (rooms total x days). Long windows
     * fall back to coarser buckets so the per-period overlap scan never
     * blows past ~a couple hundred iterations.
     */
    private function occupancyTrend(Carbon $start, Carbon $end, string $groupBy,
        ?int $propertyId, ?int $buildingId, ?int $roomTypeId): array
    {
        $spanDays = (int) $start->diffInDays($end) + 1;
        if ($groupBy === 'day' && $spanDays > 120) {
            $groupBy = 'week';
        }
        if ($groupBy === 'week' && $spanDays > 800) {
            $groupBy = 'month';
        }

        $roomsTotal = $this->occupancySnapshot($propertyId, $buildingId, $roomTypeId)['total'];
        $trend = [];

        foreach ($this->periodKeys($start, $end, $groupBy) as $key) {
            [$periodStart, $periodEnd] = $this->periodWindow($key, $groupBy, $start, $end);
            $days = (int) $periodStart->diffInDays($periodEnd) + 1;
            if ($days < 1) {
                continue;
            }
            $occupiedNights = $this->occupiedRoomNights($periodStart, $periodEnd, $propertyId, $buildingId, $roomTypeId);
            $capacity = $roomsTotal * $days;
            $trend[] = [
                'period' => $key,
                'occupied_room_nights' => $occupiedNights,
                'capacity_room_nights' => $capacity,
                'occupancy_pct' => $capacity > 0 ? round($occupiedNights / $capacity * 100, 1) : 0.0,
            ];
        }

        return $trend;
    }

    /**
     * The calendar window for a period key, clamped to [start, end].
     */
    private function periodWindow(string $key, string $groupBy, Carbon $start, Carbon $end): array
    {
        $rawStart = $groupBy === 'month' ? Carbon::parse($key.'-01') : Carbon::parse($key);
        $rawEnd = $groupBy === 'month'
            ? $rawStart->copy()->endOfMonth()
            : $rawStart->copy()->addDays($groupBy === 'week' ? 6 : 0)->endOfDay();

        return [
            $rawStart->startOfDay()->lt($start) ? $start->copy()->startOfDay() : $rawStart->copy()->startOfDay(),
            $rawEnd->gt($end) ? $end->copy()->endOfDay() : $rawEnd->copy()->endOfDay(),
        ];
    }

    /**
     * Occupied room-nights in [from, to] for realized stays only - mirrors
     * ReportService::rooms' true-overlap math so both modules agree.
     */
    private function occupiedRoomNights(Carbon $from, Carbon $to, ?int $propertyId, ?int $buildingId, ?int $roomTypeId): int
    {
        $startKey = $from->toDateString();
        $endKey = $to->toDateString();
        $overlapStart = Carbon::parse($startKey)->subDay()->startOfDay();
        $overlapEnd = Carbon::parse($endKey)->addDay()->startOfDay();

        $rows = DB::table('bookings')
            ->join('booking_rooms', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->when($propertyId || $buildingId || $roomTypeId, fn ($q) => $q
                ->join('rooms', 'rooms.id', '=', 'booking_rooms.room_id')
                ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
                ->when($propertyId, fn ($jq) => $jq->where('buildings.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('rooms.building_id', $buildingId))
                ->when($roomTypeId, fn ($jq) => $jq->where('booking_rooms.room_type_id', $roomTypeId)))
            ->select('bookings.id', 'bookings.check_in_date', 'bookings.check_out_date')
            ->selectRaw('COUNT(booking_rooms.id) as room_count')
            ->whereIn('bookings.status', ['checked_in', 'checked_out'])
            ->where('bookings.check_in_date', '<', $overlapEnd)
            ->where('bookings.check_out_date', '>', $overlapStart)
            ->groupBy('bookings.id', 'bookings.check_in_date', 'bookings.check_out_date')
            ->get();

        $nights = 0;
        foreach ($rows as $booking) {
            $days = (int) Carbon::parse($booking->check_in_date)->max(Carbon::parse($startKey))
                ->diffInDays(Carbon::parse($booking->check_out_date)->min(Carbon::parse($endKey)->addDay()));
            $nights += $days * (int) $booking->room_count;
        }

        return $nights;
    }

    // ----------------------------------------------------------- helpers

    private function scopedBookings($query, ?int $propertyId, ?int $buildingId, ?int $roomTypeId)
    {
        return $query
            ->when($propertyId, fn ($q) => $q->byProperty($propertyId))
            ->when($buildingId, fn ($q) => $q->byBuilding($buildingId))
            ->when($roomTypeId, fn ($q) => $q->byRoomType($roomTypeId));
    }

    private function scopedPayments($query, ?int $propertyId, ?int $buildingId)
    {
        return $query
            ->when($propertyId, fn ($q) => $q->byProperty($propertyId))
            ->when($buildingId, fn ($q) => $q
                ->whereHas('booking.bookingRooms.room', fn ($rq) => $rq->where('building_id', $buildingId)));
    }

    private function scopedRooms($query, ?int $propertyId, ?int $buildingId)
    {
        return $query
            ->when($propertyId, fn ($q) => $q->whereHas('building', fn ($bq) => $bq->where('property_id', $propertyId)))
            ->when($buildingId, fn ($q) => $q->where('building_id', $buildingId));
    }

    private function bookingsIn(Carbon $start, Carbon $end, ?int $propertyId, ?int $buildingId, ?int $roomTypeId)
    {
        return $this->scopedBookings(
            Booking::query()->whereBetween('created_at', [$start, $end]),
            $propertyId, $buildingId, $roomTypeId
        );
    }

    private function statusList(array $statuses, $counts): array
    {
        $list = [];
        foreach ($statuses as $status) {
            $list[] = [
                'status' => $status,
                'count' => (int) (isset($counts[$status]) ? $counts[$status] : 0),
            ];
        }

        return $list;
    }

    /**
     * Continuous sorted period axis for the window, with the DB rows
     * merged in (all keys present; missing buckets keep $defaults).
     */
    private function fillPeriods(Carbon $start, Carbon $end, string $groupBy, $rows, array $defaults): array
    {
        $series = [];
        foreach ($this->periodKeys($start, $end, $groupBy) as $key) {
            $row = $rows[$key] ?? null;
            $series[$key] = ['period' => $key] + $defaults;
            foreach ($defaults as $field => $_default) {
                if ($row !== null) {
                    $series[$key][$field] = (int) $row->{$field};
                }
            }
        }

        return array_values($series);
    }

    private function periodKeys(Carbon $start, Carbon $end, string $groupBy): array
    {
        $keys = [];
        $cursor = $start->copy();

        if ($groupBy === 'week') {
            $cursor = $cursor->startOfWeek(Carbon::MONDAY);
        } elseif ($groupBy === 'month') {
            $cursor = $cursor->startOfMonth();
        }

        while ($cursor->lte($end)) {
            $keys[] = match ($groupBy) {
                'week' => $cursor->toDateString(),
                'month' => $cursor->format('Y-m'),
                default => $cursor->toDateString(),
            };
            match ($groupBy) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    /**
     * Inclusive [startOfDay, endOfDay] range for the between() filters.
     */
    private function range(string $from, string $to): array
    {
        return [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ];
    }

    private function previousRange(string $from, string $to): array
    {
        $end = Carbon::parse($from)->subDay();
        $spanDays = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        return [
            $end->copy()->subDays($spanDays - 1)->startOfDay(),
            $end->copy()->endOfDay(),
        ];
    }

    /**
     * Cross-engine period bucketing, identical to ReportService: the test
     * suite runs on SQLite while the app runs on MySQL, so no raw date SQL
     * can assume one engine. day = calendar date, week = the Monday of the
     * week, month = YYYY-MM.
     */
    private function periodExpression(string $column, string $groupBy): string
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        return match ($groupBy) {
            'week' => $isSqlite
                ? "date($column, '-' || ((CAST(strftime('%w', $column) AS INTEGER) + 6) % 7) || ' days')"
                : "DATE_SUB(DATE($column), INTERVAL WEEKDAY(DATE($column)) DAY)",
            'month' => $isSqlite
                ? "strftime('%Y-%m', $column)"
                : "DATE_FORMAT(DATE($column), '%Y-%m')",
            default => $isSqlite ? "date($column)" : "DATE($column)",
        };
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
