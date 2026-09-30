<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dynamic Reports (D-1) - server-side SQL aggregation for the three
 * staff report endpoints. Every count/sum is computed in the database
 * (never by paging through rows client-side), and every monetary value
 * comes out as a 2-decimal string in the same shape as the decimal:2
 * model casts. Intervals are inclusive: from 00:00:00 of from through
 * 23:59:59 of to (UTC, matching config/app.php).
 */
class ReportService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public const BOOKING_STATUSES = ['pending_payment', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'];

    public const PAYMENT_STATUSES = ['pending', 'receipt_uploaded', 'verified', 'rejected', 'refunded'];

    public const ROOM_STATUSES = ['available', 'reserved', 'occupied', 'cleaning', 'maintenance'];

    public const BOOKING_SOURCES = ['website', 'walk_in', 'phone', 'other'];

    public const PAYMENT_METHODS = ['bank_transfer', 'cash'];

    /**
     * Bookings summary: total, per-status counts, reservation value, late
     * cancellation fees, and a time series grouped by day/week/month.
     * Time is bookings.created_at (when the hold was raised).
     */
    public function bookings(string $from, string $to, string $groupBy = 'day', ?string $status = null,
        ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $base = fn ($q) => $this->scopedBookings(
            $q->whereBetween('created_at', [$start, $end])
                ->when($status, fn ($query) => $query->where('status', $status)),
            $propertyId,
            $buildingId,
            $roomTypeId
        );

        $summary = $base(Booking::query())
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as reservation_total')
            ->selectRaw('COALESCE(SUM(late_fee_amount), 0) as late_fees_total')
            ->first();

        $byStatus = array_fill_keys(self::BOOKING_STATUSES, 0);
        foreach ($base(Booking::query())->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status') as $s => $n) {
            if (isset($byStatus[$s])) {
                $byStatus[$s] = (int) $n;
            }
        }

        $series = [];
        foreach ($base(Booking::query())
            ->selectRaw($this->periodExpression('created_at', $groupBy).' as period, status')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(total_amount), 0) as reservation_total, COALESCE(SUM(late_fee_amount), 0) as late_fees_total')
            ->groupBy('period', 'status')
            ->orderBy('period')
            ->get() as $row) {
            $series[$row->period] ??= [
                'period' => $row->period,
                'total' => 0,
                'reservation_total' => '0.00',
                'late_fees_total' => '0.00',
                'by_status' => array_fill_keys(self::BOOKING_STATUSES, 0),
            ];
            $series[$row->period]['total'] += (int) $row->total;
            $series[$row->period]['reservation_total'] = $this->money((float) $series[$row->period]['reservation_total'] + (float) $row->reservation_total);
            $series[$row->period]['late_fees_total'] = $this->money((float) $series[$row->period]['late_fees_total'] + (float) $row->late_fees_total);
            $series[$row->period]['by_status'][$row->status] = (int) $row->total;
        }

        return [
            'total' => (int) $summary->total,
            'reservation_total' => $this->money((float) $summary->reservation_total),
            'late_fees_total' => $this->money((float) $summary->late_fees_total),
            'by_status' => $byStatus,
            'series' => array_values($series),
        ];
    }

    /**
     * Revenue summary: verified vs refunded vs net, per-status payment
     * volume, and a merged time series. verified_total attributes money
     * to payments.verified_at; refunded_total to payments.refunded_at
     * (D-2). net_total = verified - refunded.
     */
    public function revenue(string $from, string $to, string $groupBy = 'day',
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $verified = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$start, $end])
            ->sum('amount');

        $refunded = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$start, $end])
            ->sum('amount');

        $byStatus = array_fill_keys(self::PAYMENT_STATUSES, 0);
        foreach ($this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status') as $s => $n) {
            if (isset($byStatus[$s])) {
                $byStatus[$s] = (int) $n;
            }
        }

        $verifiedRows = $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$start, $end])
            ->selectRaw($this->periodExpression('verified_at', $groupBy).' as period, COALESCE(SUM(amount), 0) as verified_total')
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $refundedRows = $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$start, $end])
            ->selectRaw($this->periodExpression('refunded_at', $groupBy).' as period, COALESCE(SUM(amount), 0) as refunded_total')
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $series = [];
        foreach ($verifiedRows as $row) {
            $series[$row->period] = ['period' => $row->period, 'verified_total' => (float) $row->verified_total, 'refunded_total' => 0.0];
        }
        foreach ($refundedRows as $row) {
            $series[$row->period] ??= ['period' => $row->period, 'verified_total' => 0.0, 'refunded_total' => (float) $row->refunded_total];
            $series[$row->period]['refunded_total'] = (float) $row->refunded_total;
        }

        foreach ($series as &$row) {
            $row['verified_total'] = $this->money($row['verified_total']);
            $row['refunded_total'] = $this->money($row['refunded_total']);
            $row['net_total'] = $this->money((float) $row['verified_total'] - (float) $row['refunded_total']);
        }
        unset($row);

        return [
            'verified_total' => $this->money($verified),
            'refunded_total' => $this->money($refunded),
            'net_total' => $this->money($verified - $refunded),
            'by_status' => $byStatus,
            'series' => array_values($series),
        ];
    }

    /**
     * Room inventory: current status distribution (+ occupied_pct). When
     * a range is given, also the occupied_room_nights over that window -
     * realized stays only (checked_in/checked_out bookings), counted via
     * true stay-overlap: a booking contributes min(check_out, to) -
     * max(check_in, from) nights per room assigned.
     */
    public function rooms(?string $from = null, ?string $to = null,
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        $distribution = array_fill_keys(self::ROOM_STATUSES, 0);
        foreach ($this->scopedRooms(Room::query(), $propertyId, $buildingId)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $count) {
            if (isset($distribution[$status])) {
                $distribution[$status] = (int) $count;
            }
        }

        $total = array_sum($distribution);
        $result = [
            'distribution' => $distribution,
            'total' => $total,
            'occupied_pct' => $total > 0 ? round($distribution['occupied'] / $total, 2) : 0.0,
        ];

        if ($from !== null && $to !== null) {
            $start = Carbon::parse($from)->toDateString();
            $end = Carbon::parse($to)->toDateString();

            // Engine-safe inclusive bounds: the check_in/check_out columns
            // store midnight datetimes (Y-m-d 00:00:00) in the SQLite test
            // DB but DATE in MySQL, so comparing against bare date strings
            // silently drops a stay that checks in exactly on `to`. Using
            // next/previous-midnight datetimes keeps "<= end" / ">= start"
            // identical on both engines.
            $overlapStart = Carbon::parse($from)->subDay()->startOfDay();
            $overlapEnd = Carbon::parse($to)->addDay()->startOfDay();

            $overlaps = DB::table('bookings')
                ->join('booking_rooms', 'booking_rooms.booking_id', '=', 'bookings.id')
                ->when($propertyId || $buildingId, fn ($q) => $q
                    ->join('rooms', 'rooms.id', '=', 'booking_rooms.room_id')
                    ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
                    ->when($propertyId, fn ($jq) => $jq->where('buildings.property_id', $propertyId))
                    ->when($buildingId, fn ($jq) => $jq->where('rooms.building_id', $buildingId)))
                ->select('bookings.id', 'bookings.check_in_date', 'bookings.check_out_date')
                ->selectRaw('COUNT(booking_rooms.id) as rooms')
                ->whereIn('bookings.status', ['checked_in', 'checked_out'])
                ->where('bookings.check_in_date', '<', $overlapEnd)
                ->where('bookings.check_out_date', '>', $overlapStart)
                ->groupBy('bookings.id', 'bookings.check_in_date', 'bookings.check_out_date')
                ->get();

            $occupiedRoomNights = 0;
            foreach ($overlaps as $booking) {
                $days = (int) Carbon::parse($booking->check_in_date)->max(Carbon::parse($start))
                    ->diffInDays(Carbon::parse($booking->check_out_date)->min(Carbon::parse($end)->addDay()));
                $occupiedRoomNights += $days * (int) $booking->rooms;
            }

            $result['occupied_room_nights'] = $occupiedRoomNights;
        }

        return $result;
    }

    /**
     * Guest report: new / returning / currently staying / total for the
     * window. Delegates to AnalyticsService so the dashboard KPIs and the
     * report can never disagree about what a "new" or "returning" guest is.
     */
    public function guests(string $from, string $to, ?int $propertyId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        return ['from' => $from, 'to' => $to] + $this->analytics->guestStats($start, $end, $propertyId);
    }

    /**
     * Reservation-source report: totals + per-source counts/percentage.
     */
    public function sources(string $from, string $to, ?int $propertyId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $list = $this->analytics->bookingSourceCounts($start, $end, $propertyId);

        return [
            'from' => $from,
            'to' => $to,
            'total' => array_sum(array_column($list, 'count')),
            'list' => $list,
        ];
    }

    /**
     * Check-ins report: total + a check_ins series bucketed from the
     * planned check-in dates inside the window.
     */
    public function checkIns(string $from, string $to, string $groupBy = 'day',
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $series = $this->analytics->checkFlow($start, $end, $groupBy, $propertyId, $buildingId);

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => $groupBy,
            'total' => array_sum(array_column($series, 'check_ins')),
            'series' => $series,
        ];
    }

    /**
     * Check-outs report: the mirror of checkIns.
     */
    public function checkOuts(string $from, string $to, string $groupBy = 'day',
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $series = $this->analytics->checkFlow($start, $end, $groupBy, $propertyId, $buildingId);

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => $groupBy,
            'total' => array_sum(array_column($series, 'check_outs')),
            'series' => $series,
        ];
    }

    /**
     * Payments report: verified/refunded/net totals attributed by the
     * transaction timestamps (D-2), per-status volume, per-method split and
     * the merged verified/refunded time series.
     */
    public function payments(string $from, string $to, string $groupBy = 'day',
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $verified = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'verified')
            ->whereBetween('verified_at', [$start, $end])
            ->sum('amount');
        $refunded = (float) $this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$start, $end])
            ->sum('amount');

        $byStatus = array_fill_keys(self::PAYMENT_STATUSES, 0);
        foreach ($this->scopedPayments(Payment::query(), $propertyId, $buildingId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status') as $status => $count) {
            if (isset($byStatus[$status])) {
                $byStatus[$status] = (int) $count;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => $groupBy,
            'verified_total' => $this->money($verified),
            'refunded_total' => $this->money($refunded),
            'net_total' => $this->money($verified - $refunded),
            'by_status' => $byStatus,
            'by_method' => $this->analytics->paymentMethodCounts($start, $end, $propertyId, $buildingId),
            'series' => $this->analytics->revenueSeries($start, $end, $groupBy, $propertyId, $buildingId),
        ];
    }

    /**
     * Outstanding payments report: a current snapshot (no window) of every
     * active hold past its deadline without a verified payment, with the
     * most overdue entries listed.
     */
    public function outstanding(?int $propertyId = null, ?int $buildingId = null): array
    {
        $snapshot = $this->analytics->todaysSnapshot($propertyId, $buildingId)['outstanding'];

        $rows = Booking::with('customer:id,name,email')
            ->when($propertyId, fn ($q) => $q->byProperty($propertyId))
            ->when($buildingId, fn ($q) => $q->byBuilding($buildingId))
            ->whereIn('status', ['pending_payment', 'confirmed'])
            ->where('payment_deadline_at', '<', Carbon::now())
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'verified'))
            ->orderBy('payment_deadline_at')
            ->limit(10)
            ->get(['id', 'booking_number', 'customer_id', 'check_in_date', 'check_out_date', 'total_amount', 'payment_deadline_at']);

        return [
            'count' => $snapshot['count'],
            'amount' => $snapshot['amount'],
            'list' => $rows->map(fn (Booking $booking) => [
                'booking_number' => $booking->booking_number,
                'customer_name' => $booking->customer?->name,
                'customer_email' => $booking->customer?->email,
                'check_in_date' => $booking->check_in_date->toDateString(),
                'check_out_date' => $booking->check_out_date->toDateString(),
                'total_amount' => $booking->total_amount,
                'payment_deadline_at' => $booking->payment_deadline_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    /**
     * Housekeeping report: cleaning events from the append-only
     * room_status_logs audit trail within the window.
     */
    public function housekeeping(string $from, string $to,
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        return $this->statusLogReport($from, $to, 'cleaning', $propertyId, $buildingId);
    }

    /**
     * Maintenance report: maintenance events from the audit trail, plus the
     * current count of rooms sitting in maintenance.
     */
    public function maintenance(string $from, string $to,
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $report = $this->statusLogReport($from, $to, 'maintenance', $propertyId, $buildingId);
        $report['currently_in_maintenance'] = (int) $this->scopedRooms(Room::query(), $propertyId, $buildingId)
            ->where('status', 'maintenance')
            ->count();

        return $report;
    }

    private function statusLogReport(string $from, string $to, string $status,
        ?int $propertyId = null, ?int $buildingId = null): array
    {
        [$start, $end] = $this->range($from, $to);

        $total = (int) DB::table('room_status_logs')
            ->when($propertyId || $buildingId, fn ($q) => $q
                ->join('rooms as r', 'r.id', '=', 'room_status_logs.room_id')
                ->when($propertyId, fn ($jq) => $jq
                    ->join('buildings as bd', 'bd.id', '=', 'r.building_id')
                    ->where('bd.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('r.building_id', $buildingId)))
            ->where('to_status', $status)
            ->whereBetween('room_status_logs.created_at', [$start, $end])
            ->count();

        $byReason = DB::table('room_status_logs as l')
            ->when($propertyId || $buildingId, fn ($q) => $q
                ->join('rooms as r', 'r.id', '=', 'l.room_id')
                ->when($propertyId, fn ($jq) => $jq
                    ->join('buildings as bd', 'bd.id', '=', 'r.building_id')
                    ->where('bd.property_id', $propertyId))
                ->when($buildingId, fn ($jq) => $jq->where('r.building_id', $buildingId)))
            ->where('l.to_status', $status)
            ->whereBetween('l.created_at', [$start, $end])
            ->selectRaw('COALESCE(l.reason, \'—\') as reason, COUNT(*) as events')
            ->groupBy('l.reason')
            ->orderByDesc('events')
            ->limit(10)
            ->get();

        $byRoom = DB::table('room_status_logs as l')
            ->join('rooms as r', 'r.id', '=', 'l.room_id')
            ->when($propertyId, fn ($q) => $q
                ->join('buildings as bd', 'bd.id', '=', 'r.building_id')
                ->where('bd.property_id', $propertyId))
            ->when($buildingId, fn ($q) => $q->where('r.building_id', $buildingId))
            ->where('l.to_status', $status)
            ->whereBetween('l.created_at', [$start, $end])
            ->whereNull('r.deleted_at')
            ->selectRaw('r.room_number, COUNT(*) as events')
            ->groupBy('r.room_number')
            ->orderByDesc('events')
            ->limit(10)
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'type' => $status,
            'events' => $total,
            'distinct_rooms' => (int) DB::table('room_status_logs')
                ->when($propertyId || $buildingId, fn ($q) => $q
                    ->join('rooms as r', 'r.id', '=', 'room_status_logs.room_id')
                    ->when($propertyId, fn ($jq) => $jq
                        ->join('buildings as bd', 'bd.id', '=', 'r.building_id')
                        ->where('bd.property_id', $propertyId))
                    ->when($buildingId, fn ($jq) => $jq->where('r.building_id', $buildingId)))
                ->where('to_status', $status)
                ->whereBetween('room_status_logs.created_at', [$start, $end])
                ->distinct()
                ->count('room_id'),
            'by_reason' => $byReason->map(fn ($row) => ['reason' => $row->reason, 'events' => (int) $row->events])->all(),
            'by_room' => $byRoom->map(fn ($row) => ['room_number' => $row->room_number, 'events' => (int) $row->events])->all(),
        ];
    }

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

    /**
     * Period bucketing that works on BOTH supported databases. The test
     * suite runs on in-memory SQLite (phpunit.xml override) while the
     * app itself runs on MySQL (XAMPP), so no raw date-function SQL can
     * assume one engine: day = calendar date, week = the Monday of the
     * week (MySQL WEEKDAY() and SQLite %w both fold to it via the +6 %7
     * shift), month = YYYY-MM.
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
