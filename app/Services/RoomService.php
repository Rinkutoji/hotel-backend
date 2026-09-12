<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomStatusLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoomService
{
    private const SORTABLE_FIELDS = ['room_number', 'floor', 'status', 'created_at'];

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    /**
     * STEP 07 maintenance transitions + STEP 09 booking-driven lifecycle +
     * STEP 13 turnover automation. cleaning -> maintenance (STEP 07) and
     * cleaning -> available (STEP 13) are the only cleaning edges; both go
     * through this matrix (the ONE source of truth). See
     * docs/STEP-13-IMPLEMENTATION-PLAN.md §9.
     */
    private const VALID_TRANSITIONS = [
        'available' => ['maintenance', 'reserved'],
        'reserved' => ['available', 'occupied'],
        'occupied' => ['cleaning'],
        'cleaning' => ['maintenance', 'available'],
        'maintenance' => ['available'],
    ];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Room::query()->with('roomType');

        if (! empty($filters['search'])) {
            $query->where('room_number', 'like', '%'.$filters['search'].'%');
        }

        if (isset($filters['room_type_id'])) {
            $query->where('room_type_id', $filters['room_type_id']);
        }

        if (isset($filters['floor'])) {
            $query->where('floor', $filters['floor']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE_FIELDS, true)
            ? $filters['sort']
            : 'room_number';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $query->paginate($perPage);
    }

    public function create(array $data): Room
    {
        // room_type_id existence + active-only, and room_number
        // uniqueness-among-active, are enforced by validation before
        // this ever runs (see app/Http/Requests/Room/StoreRequest.php).
        // status is deliberately not mass-assignable (not in
        // Room::$fillable) - set explicitly here, never from client
        // input.
        $room = new Room($data);
        $room->status = 'available';
        $room->status_changed_at = now();
        $room->save();

        return $room->fresh();
    }

    public function update(Room $room, array $data): Room
    {
        // $data comes from UpdateRequest::validated(), which never
        // includes a status key - a PUT can never change status through
        // this method, only through transitionStatus() below.
        $room->fill($data);
        $room->save();

        return $room->fresh();
    }

    public function delete(Room $room): void
    {
        $room->delete();
    }

    public function transitionStatus(Room $room, string $toStatus, ?Employee $employee, ?string $reason): Room
    {
        $fromStatus = $room->status;
        $allowed = self::VALID_TRANSITIONS[$fromStatus] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new BusinessRuleException(
                "Cannot transition a room from '{$fromStatus}' to '{$toStatus}'."
            );
        }

        $room->status = $toStatus;
        $room->status_changed_at = now();
        $room->save();

        RoomStatusLog::create([
            'room_id' => $room->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            // Nullable - a future scheduled sweep (STEP 13) would pass
            // null here for system-triggered transitions. Always a real
            // employee for the manual actions STEP 07 implements.
            'changed_by' => $employee?->id,
            'reason' => $reason,
        ]);

        return $room->fresh();
    }

    /**
     * STEP 13 - turnover sweep. Transitions every room that (a) is currently
     * 'cleaning' and (b) has been cleaning for at least the configured buffer
     * (config('booking.cleaning_buffer_minutes')) to 'available', writing a
     * system-triggered room_status_logs row (changed_by = null).
     *
     * Eligibility uses COALESCE(cleaning_started_at, status_changed_at): rooms
     * whose cleaning_started_at predates the STEP 13 checkout stamp fall back
     * to status_changed_at (always set on entering cleaning); a row where BOTH
     * are null never matches (COALESCE is null) and is safely skipped - it can
     * still be late-completed manually via the normal endpoint.
     *
     * Idempotent: only status='cleaning' rooms are selected, so a completed
     * room is never re-selected; each room is locked (lockForUpdate) and
     * re-checked inside its own transaction, so two overlapping runs serialize
     * on the row lock and the loser skips. Rooms in any other status
     * (reserved/occupied/maintenance/available) are never touched. See
     * docs/STEP-13-IMPLEMENTATION-PLAN.md §6/§13/§14/§16/§17/§18.
     */
    public function completeReadyCleaning(): int
    {
        $cutoff = now()->subMinutes((int) config('booking.cleaning_buffer_minutes', 15));

        $candidateIds = Room::query()
            ->where('status', 'cleaning')
            ->whereRaw('COALESCE(cleaning_started_at, status_changed_at) <= ?', [$cutoff])
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($candidateIds as $roomId) {
            DB::transaction(function () use ($roomId, $cutoff, &$count) {
                // Authoritative read under lock: the candidate select above is
                // a snapshot; if a concurrent staff action (early-complete or
                // maintenance interrupt) already moved the room, we skip.
                $room = Room::query()
                    ->whereKey($roomId)
                    ->lockForUpdate()
                    ->first();

                if ($room === null || $room->status !== 'cleaning') {
                    return;
                }

                // Re-check eligibility against the freshly locked row. The
                // buffer is monotonic (only more time passes), so this is
                // belt-and-braces. COALESCE(cleaning_started_at,
                // status_changed_at): a row with BOTH null is safely skipped
                // (the ?? chain yields null) - it can still be late-completed
                // manually via the normal endpoint.
                $timer = $room->cleaning_started_at ?? $room->status_changed_at;

                if ($timer === null || $timer->gt($cutoff)) {
                    return;
                }

                $this->transitionStatus($room, 'available', null, 'Cleaning period complete');
                $count++;
            });
        }

        return $count;
    }

    public static function isTransitionValid(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, self::VALID_TRANSITIONS[$fromStatus] ?? [], true);
    }
}
