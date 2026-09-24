<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatusLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * STEP 17 - the discount that was applied by the most recent create(),
     * or null. Read back by the booking-create controllers so the response
     * can disclose the applied tier (D-4). This service is resolved per
     * request by the container, so the value never leaks between requests,
     * and it is not persisted - later show/list reads have no such key, so
     * historical bookings never re-derive pricing from the live table.
     */
    private ?array $lastAppliedDiscount = null;

    private const BOOKING_NUMBER_PREFIX = 'BK-';

    public function __construct(private readonly DiscountService $discounts)
    {
    }

    public function discountSummary(): ?array
    {
        return $this->lastAppliedDiscount;
    }

    private const BOOKING_NUMBER_MAX_RETRIES = 3;

    private const STATUSES_HOLDING = ['pending_payment', 'confirmed', 'checked_in', 'checked_out'];

    public function searchAvailable(string $checkIn, string $checkOut, array $roomIds, ?int $propertyId = null): Collection
    {
        $checkInDate = $this->startOfDay($checkIn);
        $checkOutDate = $this->startOfDay($checkOut);

        // Room and room_type validation excludes soft-deleted records
        // (both use SoftDeletes; explicit whereNull is belt-and-braces).
        $rooms = Room::query()
            ->with('roomType')
            ->whereIn('id', $roomIds)
            ->whereNull('deleted_at')
            ->whereHas('roomType', function ($q) {
                $q->whereNull('deleted_at');
            })
            ->where('status', '!=', 'maintenance')
            ->when($propertyId, fn ($q) => $q->whereHas('building', fn ($bq) => $bq->where('property_id', $propertyId)))
            ->whereHas('building', fn ($bq) => $bq
                ->whereNull('buildings.deleted_at')
                ->where('buildings.status', 'active')
                ->whereHas('property', fn ($pq) => $pq
                    ->whereNull('properties.deleted_at')
                    ->where('properties.status', 'active')))
            ->whereDoesntHave('bookingRooms', function ($q) use ($checkInDate, $checkOutDate) {
                $q->whereHas('booking', function ($bq) use ($checkInDate, $checkOutDate) {
                    $bq->whereIn('status', self::STATUSES_HOLDING)
                        ->where(function ($o) use ($checkInDate, $checkOutDate) {
                            $o->where('check_in_date', '<', $checkOutDate)
                                ->where('check_out_date', '>', $checkInDate);
                        })
                        ->where(function ($o) {
                            $o->where('status', '!=', 'pending_payment')
                                ->orWhere('payment_deadline_at', '>', now());
                        });
                });
            })
            ->get();

        // STEP 17 (D-4): search presents the same discounted nightly rate the
        // create() snapshot would charge. The tier is resolved once for the
        // whole stay; when one applies, each returned room carries the
        // transient "discount" attribute so the controller can disclose the
        // original vs. discounted nightly and stay totals. This mirrors the
        // booking-create disclosure (discountSummary) and never persists.
        // docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-3/D-4.
        $discount = $this->discounts->resolveForNights((int) $checkInDate->diffInDays($checkOutDate));

        if ($discount === null) {
            return $rooms;
        }

        return $rooms->map(function (Room $room) use ($discount, $checkInDate, $checkOutDate) {
            $nights = (int) $checkInDate->diffInDays($checkOutDate);
            $baseCents = (int) round((float) $room->roomType->base_price * 100);
            $chargedCents = $this->discounts->discountedNightlyCents($discount, $baseCents);

            $room->setAttribute('discount', [
                'discount_id' => $discount->id,
                'name' => $discount->name,
                'min_nights' => $discount->min_nights,
                'percent_off' => $discount->percent_off,
                'nights' => $nights,
                'price_per_night' => $this->formatCents($baseCents),
                'discounted_price_per_night' => $this->formatCents($chargedCents),
                'total_for_stay' => $this->formatCents($baseCents * $nights),
                'total_after_discount' => $this->formatCents($chargedCents * $nights),
                'saved_total' => $this->formatCents(($baseCents - $chargedCents) * $nights),
            ]);

            return $room;
        });
    }

    public function create(array $data, int $customerId): Booking
    {
        $roomIds = $data['rooms'];
        $checkInDate = $this->startOfDay($data['check_in_date']);
        $checkOutDate = $this->startOfDay($data['check_out_date']);
        $nights = (int) $checkInDate->diffInDays($checkOutDate);

        $maxNights = (int) config('booking.max_stay_nights', 30);
        if ($nights > $maxNights) {
            throw new \InvalidArgumentException("Stay cannot exceed {$maxNights} nights.");
        }

        $maxRooms = (int) config('booking.max_rooms_per_booking', 5);
        if (count($roomIds) > $maxRooms) {
            throw new \InvalidArgumentException("Cannot book more than {$maxRooms} rooms.");
        }

        // Deterministic ascending lock order - every concurrent transaction
        // touching the same set of rooms acquires the row locks in the same
        // sequence, preventing deadlocks.
        $sortedRoomIds = collect($roomIds)->sort()->values()->all();

        $this->lastAppliedDiscount = null;

        return DB::transaction(function () use ($customerId, $data, $checkInDate, $checkOutDate, $sortedRoomIds, $nights) {
            // 1. Pessimistic row lock on the requested rooms, in ascending
            //    id order. This serializes every booking attempt touching
            //    the same room(s).
            $rooms = Room::whereIn('id', $sortedRoomIds)
                ->with('building.property')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $roomModels = $rooms->keyBy('id');

            if (count($roomModels) !== count($sortedRoomIds)) {
                throw new BusinessRuleException('One or more requested rooms do not exist.');
            }

            // Property integrity (spec §7.4): every room must share one property,
            // and any claimed property/building must match. Never trusted from
            // the frontend - derived from the locked rows.
            $propertyIds = $rooms->pluck('building.property_id')->unique();
            if ($propertyIds->count() !== 1) {
                throw ValidationException::withMessages(['rooms' => 'All rooms in a booking must belong to the same property.']);
            }
            $actualPropertyId = (int) $propertyIds->first();

            if (isset($data['property_id']) && (int) $data['property_id'] !== $actualPropertyId) {
                throw ValidationException::withMessages(['property_id' => 'The selected rooms do not belong to the selected property.']);
            }
            if (isset($data['building_id'])) {
                $buildingIds = $rooms->pluck('building_id')->unique();
                if ($buildingIds->count() !== 1 || (int) $buildingIds->first() !== (int) $data['building_id']) {
                    throw ValidationException::withMessages(['building_id' => 'The selected rooms do not belong to the selected building.']);
                }
            }

            foreach ($rooms as $room) {
                if ($room->building === null || $room->building->trashed() || $room->building->status !== 'active'
                    || $room->building->property === null || $room->building->property->trashed() || $room->building->property->status !== 'active') {
                    throw new BusinessRuleException('One or more requested rooms are not available for the selected dates.');
                }
            }

            foreach ($sortedRoomIds as $roomId) {
                $room = $roomModels[$roomId];

                if ($room->roomType === null || $room->roomType->trashed()) {
                    throw new BusinessRuleException('One or more requested rooms are not available for the selected dates.');
                }

                if ($room->status === 'maintenance') {
                    throw new BusinessRuleException('One or more requested rooms are not available for the selected dates.');
                }

                // 2. Overlap check as a locking read, not a plain select -
                //    under REPEATABLE READ a plain select would use a stale
                //    snapshot. Locking reads always see the latest
                //    committed state, so a concurrent booking that already
                //    committed its hold is seen here.
                if ($this->hasOverlappingHoldingBooking($roomId, $checkInDate, $checkOutDate)) {
                    throw new BusinessRuleException('One or more requested rooms are not available for the selected dates.');
                }
            }

            // 3. Server-side pricing: snapshot room.roomType.base_price at
            //    booking time, accumulated in integer cents then formatted
            //    decimal:2 only on persistence - no float drift. Money is
            //    never read from request input. docs/STEP-09-BOOKING-PLAN.md §5.
            //    STEP 17: if a pricing tier qualifies for the stay length,
            //    its percentage comes off each night BEFORE the snapshot, so
            //    booking_rooms.price_per_night stores the final charged rate.
            //    docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-3/D-3b/D-3c.
            $discount = $this->discounts->resolveForNights($nights);
            $savedCents = 0;
            $totalCents = 0;
            $roomSnapshots = [];

            foreach ($sortedRoomIds as $roomId) {
                $room = $roomModels[$roomId];
                $pricePerNightCents = (int) round((float) $room->roomType->base_price * 100);

                $chargedCents = $pricePerNightCents;
                if ($discount !== null) {
                    $chargedCents = $this->discounts->discountedNightlyCents($discount, $pricePerNightCents);
                    $savedCents += $nights * ($pricePerNightCents - $chargedCents);
                }

                $totalCents += $nights * $chargedCents;
                $roomSnapshots[$roomId] = [
                    'room_id' => $roomId,
                    'room_type_id' => $room->room_type_id,
                    'price_per_night' => $this->formatCents($chargedCents),
                ];
            }

            $booking = Booking::create([
                'booking_number' => $this->generateBookingNumber(),
                'customer_id' => $customerId,
                'check_in_date' => $checkInDate->toDateString(),
                'check_out_date' => $checkOutDate->toDateString(),
                'total_amount' => $this->formatCents($totalCents),
                'payment_deadline_at' => now()->addHours(config('booking.hold_hours', 2)),
            ]);

            foreach ($sortedRoomIds as $roomId) {
                $snapshot = $roomSnapshots[$roomId];
                BookingRoom::create([
                    'booking_id' => $booking->id,
                    'room_id' => $roomId,
                    'room_type_id' => $snapshot['room_type_id'],
                    'price_per_night' => $snapshot['price_per_night'],
                ]);
            }

            // STEP 17 (D-4): capture the applied tier for the create
            // response only - a transient, per-request disclosure. It is
            // never persisted, so later show/list responses (and any
            // re-read) stay discount-free and historical.
            $this->lastAppliedDiscount = $discount === null ? null : [
                'discount_id' => $discount->id,
                'name' => $discount->name,
                'min_nights' => $discount->min_nights,
                'percent_off' => $discount->percent_off,
                'nights' => $nights,
                'saved_total' => $this->formatCents($savedCents),
                'total_after_discount' => $this->formatCents($totalCents),
            ];

            return $booking->fresh(['bookingRooms.room', 'bookingRooms.roomType', 'customer']);
        });
    }

    public function cancelByCustomer(Booking $booking, ?string $reason): Booking
    {
        return DB::transaction(function () use ($booking, $reason) {
            if ($booking->status !== 'pending_payment') {
                throw new BusinessRuleException('Only pending payment bookings can be cancelled.');
            }

            if ($booking->isExpired()) {
                throw new BusinessRuleException('This booking has already expired and cannot be cancelled.');
            }

            $this->applyCancellation($booking, $reason ?? 'Cancelled by customer');

            return $booking->fresh(['bookingRooms.room', 'customer']);
        });
    }

    /**
     * An expired pending_payment hold is auto-cancelled and the cancellation
     * is committed before the caller throws its 409. Shared by staff confirm
     * (BookingService::confirm) and payment verification
     * (PaymentService::verify) - the caller must NOT be inside an open
     * transaction when calling this, or the throw after it would roll back
     * the cancellation.
     */
    public function cancelExpiredHold(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $this->applyCancellation($booking, 'Payment deadline passed');
        });
    }

    public function confirm(Booking $booking): Booking
    {
        if ($booking->status !== 'pending_payment') {
            throw new BusinessRuleException('Only pending payment bookings can be confirmed.');
        }

        if ($booking->isExpired()) {
            // Auto-cancel must persist even though the caller gets a 409.
            // If applyCancellation ran inside the same transaction as the
            // thrown exception, DB::transaction would roll the cancellation
            // back - commit it separately first.
            $this->cancelExpiredHold($booking);

            throw new BusinessRuleException('This booking has expired and has been automatically cancelled.');
        }

        return DB::transaction(function () use ($booking) {
            $this->confirmWithinTransaction($booking);

            return $booking->fresh(['bookingRooms.room', 'customer']);
        });
    }

    /**
     * The pending_payment -> confirmed transition: booking status, room
     * reservation and the status-log audit row. No transaction wrapper and
     * no pre-checks - callers own the transaction. Shared by staff confirm
     * (BookingService::confirm) and payment verification
     * (PaymentService::verify) so both confirm atomically within their own
     * DB transaction. See docs/STEP-10-PAYMENT-PLAN.md §7.
     */
    public function confirmWithinTransaction(Booking $booking): void
    {
        $booking->status = 'confirmed';
        $booking->save();

        foreach ($booking->bookingRooms as $bookingRoom) {
            $this->transitionRoom($bookingRoom->room, 'reserved', 'Booking confirmed');
        }
    }

    public function cancelByStaff(Booking $booking, string $reason): Booking
    {
        return DB::transaction(function () use ($booking, $reason) {
            // Only pre-occupancy statuses can be cancelled by staff - the
            // plan's transition table has no checked_in -> cancelled edge
            // (a checked-in booking is ended via check-out). Allowing it
            // would cancel the booking while its room stays 'occupied'.
            // docs/STEP-09-BOOKING-PLAN.md §6.
            $cancellable = ['pending_payment', 'confirmed'];

            if (! in_array($booking->status, $cancellable, true)) {
                throw new BusinessRuleException("Cannot cancel a booking with status '{$booking->status}'.");
            }

            // STEP 15 - pessimistic re-read of the authoritative committed
            // row before deciding the fee, mirroring PaymentService::refund
            // and the STEP 13/14 sweeps' re-check philosophy. Two concurrent
            // cancels serialize here; the loser's re-read sees 'cancelled'
            // and 409s (§18). sqlite ignores FOR UPDATE (STEP 09 caveat) -
            // the functional behavior is covered by sequential tests.
            $booking = Booking::with('bookingRooms.room')
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if ($booking === null || ! in_array($booking->status, $cancellable, true)) {
                $status = $booking === null ? null : $booking->status;
                throw new BusinessRuleException("Cannot cancel a booking with status '{$status}'.");
            }

            // STEP 15 - only a booking that was 'confirmed' inside the late
            // window can carry a fee. pending_payment staff-cancellations,
            // hold-expiry cancellations and the refund path never touch the
            // fee fields (§10/§14).
            $wasConfirmed = $booking->status === 'confirmed';

            $this->applyCancellation($booking, $reason);

            if ($wasConfirmed && $this->isLateCancellation($booking)) {
                $this->applyLateCancellationFee($booking);
            }

            return $booking->fresh(['bookingRooms.room', 'customer']);
        });
    }

    public function checkIn(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            if ($booking->status !== 'confirmed') {
                throw new BusinessRuleException('Only confirmed bookings can be checked in.');
            }

            $today = now()->startOfDay();
            $checkInDate = $this->startOfDay($booking->check_in_date);

            if ($today->lt($checkInDate)) {
                throw new BusinessRuleException('Check-in is only allowed on or after the check-in date.');
            }

            $booking->status = 'checked_in';
            $booking->save();

            foreach ($booking->bookingRooms as $bookingRoom) {
                $this->transitionRoom($bookingRoom->room, 'occupied', 'Guest checked in');
                $bookingRoom->update(['actual_check_in_at' => now()]);
            }

            return $booking->fresh(['bookingRooms.room', 'customer']);
        });
    }

    public function checkOut(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            if ($booking->status !== 'checked_in') {
                throw new BusinessRuleException('Only checked-in bookings can be checked out.');
            }

            $booking->status = 'checked_out';
            $booking->save();

            foreach ($booking->bookingRooms as $bookingRoom) {
                $this->transitionRoom($bookingRoom->room, 'cleaning', 'Guest checked out');
                $bookingRoom->update(['actual_check_out_at' => now()]);
            }

            return $booking->fresh(['bookingRooms.room', 'customer']);
        });
    }

    public function releaseExpiredHolds(): int
    {
        $expiredBookings = Booking::where('status', 'pending_payment')
            ->where('payment_deadline_at', '<', now())
            ->get();

        $count = 0;

        foreach ($expiredBookings as $booking) {
            DB::transaction(function () use ($booking, &$count) {
                $this->applyCancellation($booking, 'Payment deadline passed');
                $count++;
            });
        }

        return $count;
    }

    /**
     * STEP 14 - no-show sweep. Marks every confirmed booking whose check-in
     * date has FULLY passed (strictly before today - the entire check-in day
     * stays open for a late check-in) as 'no_show', and releases its reserved
     * rooms back to 'available'. See docs/STEP-14-PLAN.md §2/§4.
     *
     * Only 'confirmed' bookings are candidates: pending_payment holds are the
     * hold sweep's job (they expire to 'cancelled'), and any checked-in/
     * checked-out/cancelled/no_show booking is out of scope. 'no_show' is
     * terminal - the existing confirm/cancel/check-in/check-out guards already
     * reject it, so a no-showed booking can never be re-transitioned.
     *
     * Idempotent: a marked booking is no longer 'confirmed', so a second run
     * processes 0. The per-booking transaction re-checks status against the
     * authoritative re-read (no soft-deletes on bookings, so status is the
     * race guard): if staff checked the guest in between the candidate select
     * and this transaction, the booking is skipped - the sweep never clobbers
     * a live 'checked_in' booking.
     */
    public function markNoShowBookings(): int
    {
        $today = now()->startOfDay();

        $candidateIds = Booking::where('status', 'confirmed')
            ->where('check_in_date', '<', $today->toDateString())
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($candidateIds as $bookingId) {
            DB::transaction(function () use ($bookingId, &$count) {
                $booking = Booking::find($bookingId);

                if ($booking === null || $booking->status !== 'confirmed') {
                    return;
                }

                $this->applyNoShow($booking);
                $count++;
            });
        }

        return $count;
    }

    private function applyNoShow(Booking $booking): void
    {
        $booking->status = 'no_show';
        $booking->no_show_at = now();
        $booking->save();

        $this->releaseReservedRooms($booking, 'Guest did not check in');
    }

    private function hasOverlappingHoldingBooking(int $roomId, Carbon $checkInDate, Carbon $checkOutDate): bool
    {
        return Booking::query()
            ->whereHas('bookingRooms', function ($q) use ($roomId) {
                $q->where('room_id', $roomId);
            })
            ->whereIn('status', self::STATUSES_HOLDING)
            ->where(function ($q) use ($checkInDate, $checkOutDate) {
                $q->where('check_in_date', '<', $checkOutDate)
                    ->where('check_out_date', '>', $checkInDate);
            })
            ->where(function ($q) {
                $q->where('status', '!=', 'pending_payment')
                    ->orWhere('payment_deadline_at', '>', now());
            })
            ->lockForUpdate()
            ->exists();
    }

    private function transitionRoom(?Room $room, string $toStatus, string $reason): void
    {
        if ($room === null || $room->trashed()) {
            throw new BusinessRuleException('A room for this booking no longer exists.');
        }

        if (! RoomService::isTransitionValid($room->status, $toStatus)) {
            throw new BusinessRuleException(
                "Cannot transition a room from '{$room->status}' to '{$toStatus}'."
            );
        }

        $fromStatus = $room->status;
        $room->status = $toStatus;
        $room->status_changed_at = now();

        // STEP 13 - a room entering 'cleaning' gets its turnover timer stamped
        // exactly here (only reachable via check-out today). cleaning_started_at
        // was schema-ready since STEP 03 but never written; the STEP 13 sweep
        // reads COALESCE(cleaning_started_at, status_changed_at) as eligibility,
        // so this stamp is the primary timer and status_changed_at is the
        // fallback for legacy rows. It is NOT in Room::$fillable - set
        // explicitly, same pattern as status. See
        // docs/STEP-13-IMPLEMENTATION-PLAN.md §4/§16.
        if ($toStatus === 'cleaning') {
            $room->cleaning_started_at = now();
        }

        $room->save();

        RoomStatusLog::create([
            'room_id' => $room->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
        ]);
    }

    private function applyCancellation(Booking $booking, string $reason): void
    {
        $booking->status = 'cancelled';
        $booking->cancelled_at = now();
        $booking->cancellation_reason = $reason;
        $booking->save();

        $this->releaseReservedRooms($booking, 'Booking cancelled');
    }

    /**
     * STEP 15 - a cancellation of a confirmed booking is "late" when it
     * happens at or after (window-hours before the start of the check-in
     * day). The boundary is inclusive (>=), one second before the cutoff is
     * NOT late - pinned by tests (docs/STEP-15-IMPLEMENTATION-PLAN.md §3/§4).
     * Only ever called on a confirmed booking already re-read with
     * lockForUpdate inside the cancelByStaff transaction.
     */
    private function isLateCancellation(Booking $booking): bool
    {
        $windowHours = (int) config('booking.late_cancel_window_hours', 24);
        $cutoff = $this->startOfDay($booking->check_in_date)->subHours($windowHours);

        return now()->gte($cutoff);
    }

    /**
     * STEP 15 - the fee is one fee_night(s) of every room's price_per_night
     * SNAPSHOT (the value recorded at booking time on booking_rooms, not the
     * current room-type price), accumulated in integer cents then formatted
     * decimal:2 - the exact STEP 09 §5 pricing convention. Never derived from
     * client input.
     */
    private function computeLateCancellationFee(Booking $booking): int
    {
        $nights = (int) config('booking.late_cancel_fee_nights', 1);
        $feeCents = 0;

        foreach ($booking->bookingRooms as $bookingRoom) {
            $pricePerNightCents = (int) round((float) $bookingRoom->price_per_night * 100);
            $feeCents += $nights * $pricePerNightCents;
        }

        return $feeCents;
    }

    /**
     * STEP 15 - writes the fee record. Called inside cancelByStaff's
     * transaction AFTER applyCancellation, so the fee and the cancellation
     * commit or roll back as one (§17).
     */
    private function applyLateCancellationFee(Booking $booking): void
    {
        $booking->late_fee_amount = $this->formatCents($this->computeLateCancellationFee($booking));
        $booking->late_fee_applied_at = now();
        $booking->save();
    }

    /**
     * Releases every 'reserved' room of a booking back to 'available',
     * writing a room_status_logs audit row with the given reason per room.
     * Only rooms whose status is still 'reserved' are touched (a room that
     * already moved - e.g. is occupied after an in-flight check-in - is left
     * alone). Shared by cancellation, refund and the STEP 14 no-show sweep;
     * the reason string carries which event ended the booking so the audit
     * trail stays exact. changed_by stays null (booking-driven transition,
     * same helper as every other booking-driven room move).
     */
    private function releaseReservedRooms(Booking $booking, string $reason): void
    {
        foreach ($booking->bookingRooms as $bookingRoom) {
            $room = $bookingRoom->room;

            if ($room === null) {
                continue;
            }

            if ($room->status === 'reserved') {
                $this->transitionRoom($room, 'available', $reason);
            }
        }
    }

    /**
     * STEP 11 - refund-triggered booking cancellation. Parallels
     * applyCancellation() but is tailored for refunds so the
     * cancellation_reason and the room_status_logs audit rows carry the
     * refund reason (a generic "Booking cancelled" would erase the audit
     * trail of why the booking ended). Callers (PaymentService::refund)
     * own the transaction - no wrapper here, matching the
     * confirmWithinTransaction pattern. The room audit rows keep
     * changed_by null, consistent with the system-level transition pattern
     * used by releaseReservedRooms (docs/STEP-11-IMPLEMENTATION-PLAN.md §2).
     */
    public function refundBooking(Booking $booking, string $reason): void
    {
        $booking->status = 'cancelled';
        $booking->cancelled_at = now();
        $booking->cancellation_reason = 'Refunded by staff: ' . $reason;
        $booking->save();

        $this->releaseReservedRooms($booking, 'Refunded by staff: ' . $reason);
    }

    private function formatCents(int $cents): string
    {
        // Integer-only formatting: sprintf avoids reintroducing floats at
        // render time, so the cents value stays drift-free end to end.
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function generateBookingNumber(): string
    {
        $attempts = 0;

        do {
            $number = self::BOOKING_NUMBER_PREFIX . strtoupper(Str::random(8));
            $exists = Booking::where('booking_number', $number)->exists();
            $attempts++;

            if (! $exists) {
                return $number;
            }
        } while ($attempts < self::BOOKING_NUMBER_MAX_RETRIES);

        throw new \RuntimeException('Failed to generate a unique booking number.');
    }

    private function startOfDay(string|Carbon $value): Carbon
    {
        return Carbon::parse($value)->startOfDay();
    }
}