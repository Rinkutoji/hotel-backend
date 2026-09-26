<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Building;
use App\Models\Payment;
use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Property admin module - list/search/paginate + CRUD (soft delete) + the
 * property detail aggregation for GET /staff/properties/{id} (spec §7.1).
 *
 * Definitions (shared with the rest of the app):
 * - occupied = rooms.status = 'occupied' (same rule AnalyticsService uses);
 *   occupancy_pct = occupied / room_count * 100, 1 decimal, 0.0 when empty.
 * - active_bookings = bookings in the holding statuses
 *   (STATUSES_HOLDING below - the same set BookingService uses for
 *   availability), scoped through bookingRooms -> room -> building.
 * - verified_revenue_total = all-time SUM of payments.status = 'verified'
 *   amounts attributed through rooms. The room/building hops use
 *   withTrashed() so soft-deleting a building later never silently erases
 *   historical revenue (operational counts above stay on default scopes).
 *
 * No authorization here: controllers pass an already-authorized model
 * (PropertyPolicy / PropertyScope are separate layers).
 */
class PropertyService
{
    private const SORTABLE_FIELDS = ['name', 'code', 'city', 'country', 'status', 'created_at'];

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    private const STATUSES_HOLDING = ['pending_payment', 'confirmed', 'checked_in', 'checked_out'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Property::query()->withCount(['buildings', 'rooms']);

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search)
                    ->orWhere('city', 'like', $search)
                    ->orWhere('province_state', 'like', $search)
                    ->orWhere('country', 'like', $search);
            });
        }

        foreach (['code', 'city', 'province_state', 'country', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE_FIELDS, true)
            ? $filters['sort']
            : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $query->paginate($perPage);
    }

    public function create(array $data): Property
    {
        $property = new Property($data);
        $property->save();

        return $property->fresh();
    }

    public function update(Property $property, array $data): Property
    {
        $property->fill($data);
        $property->save();

        return $property->fresh();
    }

    public function delete(Property $property): void
    {
        // Soft delete only - buildings/rooms/bookings/payments/invoices stay
        // attached and property history remains intact (spec §7.1).
        $property->delete();
    }

    public function detail(Property $property): array
    {
        $buildings = $property->buildings()->orderBy('name')->get();

        $roomStats = DB::table('rooms')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.property_id', $property->id)
            ->whereNull('rooms.deleted_at')
            ->whereNull('buildings.deleted_at')
            ->selectRaw('rooms.building_id, COUNT(*) as room_count, SUM(CASE WHEN rooms.status = ? THEN 1 ELSE 0 END) as occupied', ['occupied'])
            ->groupBy('rooms.building_id')
            ->get()
            ->keyBy('building_id');

        $buildingList = $buildings->map(function (Building $building) use ($roomStats) {
            $row = $roomStats->get($building->id);
            $roomCount = (int) ($row->room_count ?? 0);
            $occupied = (int) ($row->occupied ?? 0);

            return [
                'id' => $building->id,
                'name' => $building->name,
                'code' => $building->code,
                'status' => $building->status,
                'floor_count' => $building->floor_count,
                'room_count' => $roomCount,
                'occupied' => $occupied,
                'occupancy_pct' => $roomCount > 0 ? round($occupied / $roomCount * 100, 1) : 0.0,
            ];
        });

        $roomCount = (int) $buildingList->sum('room_count');
        $occupied = (int) $buildingList->sum('occupied');

        $verifiedRevenue = (float) Payment::query()
            ->where('status', 'verified')
            ->whereHas('booking.bookingRooms.room', fn ($q) => $q->withTrashed()
                ->whereHas('building', fn ($b) => $b->withTrashed()->where('property_id', $property->id)))
            ->sum('amount');

        $activeBookings = (int) Booking::query()
            ->byProperty($property->id)
            ->whereIn('status', self::STATUSES_HOLDING)
            ->count();

        return [
            'id' => $property->id,
            'name' => $property->name,
            'code' => $property->code,
            'description' => $property->description,
            'status' => $property->status,
            'check_in_time' => $property->check_in_time,
            'check_out_time' => $property->check_out_time,
            'timezone' => $property->timezone,
            'currency' => $property->currency,
            'address' => $property->address,
            'city' => $property->city,
            'province_state' => $property->province_state,
            'country' => $property->country,
            'postal_code' => $property->postal_code,
            'phone' => $property->phone,
            'email' => $property->email,
            'website' => $property->website,
            'latitude' => $property->latitude,
            'longitude' => $property->longitude,
            'buildings' => $buildingList->values()->all(),
            'summary' => [
                'room_count' => $roomCount,
                'occupied' => $occupied,
                'occupancy_pct' => $roomCount > 0 ? round($occupied / $roomCount * 100, 1) : 0.0,
                'verified_revenue_total' => number_format($verifiedRevenue, 2, '.', ''),
                'active_bookings' => $activeBookings,
            ],
        ];
    }
}
