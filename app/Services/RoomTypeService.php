<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\RoomType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RoomTypeService
{
    /**
     * Sortable-field allowlist. Checked here AND in
     * App\Http\Requests\RoomType\IndexRequest - the FormRequest is the
     * primary gate (rejects bad input with a 422 before this class ever
     * runs), this is a second, defensive check in case this method is
     * ever called from somewhere that skips the FormRequest (a future
     * console command, for instance).
     */
    private const SORTABLE_FIELDS = ['name', 'base_price', 'created_at'];

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = RoomType::query();

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        if (isset($filters['min_price'])) {
            $query->where('base_price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('base_price', '<=', $filters['max_price']);
        }

        if (isset($filters['min_adults'])) {
            $query->where('capacity_adults', '>=', $filters['min_adults']);
        }

        if (isset($filters['min_children'])) {
            $query->where('capacity_children', '>=', $filters['min_children']);
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

    public function create(array $data): RoomType
    {
        return RoomType::create($data);
    }

    public function update(RoomType $roomType, array $data): RoomType
    {
        $roomType->update($data);

        return $roomType->fresh();
    }

    public function delete(RoomType $roomType): void
    {
        // STEP 07 headline rule: a room type with active physical rooms
        // cannot be soft-deleted. The default rooms() relationship
        // already excludes soft-deleted rooms (Room's own SoftDeletes
        // global scope), so a type whose rooms have all been
        // individually removed is still deletable.
        //
        // This check exists because the DB-level restrictOnDelete FK on
        // rooms.room_type_id does NOT protect against this - soft-delete
        // is an UPDATE, not a real DELETE, at the database level, so the
        // constraint never fires. See docs/STEP-06-ROOM-TYPE-PLAN.md §5
        // and docs/STEP-07-ROOM-PLAN.md §4.
        if ($roomType->rooms()->exists()) {
            throw new BusinessRuleException(
                'Cannot delete a room type that has physical rooms. Remove or reassign its rooms first.'
            );
        }

        // Soft delete only (SoftDeletes trait) - never force-delete, per
        // "do NOT hard-delete historical room types".
        $roomType->delete();
    }
}
