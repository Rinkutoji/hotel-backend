<?php

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatusLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

/**
 * STEP 14 - no-show automation sweep.
 *
 * Verified axes (docs/STEP-14-PLAN.md §12): only 'confirmed' bookings whose
 * check-in date has fully passed are marked; room release + audit; idempotency;
 * the check-in race guard; terminal 409s; inventory re-entry; listing; schedule.
 */

// Helper: a confirmed booking whose room is reserved (mirrors what
// confirmWithinTransaction produces).
function makeConfirmedBooking(string $email, string $roomNumber, int $roomTypeId, string $checkIn, string $checkOut): array
{
    $customer = makeBookingCustomer($email);
    $room = makeBookingRoom($roomNumber, $roomTypeId);
    $booking = makeHoldingBooking($customer, $room, $checkIn, $checkOut, 'confirmed');

    $room->status = 'reserved';
    $room->status_changed_at = now();
    $room->save();

    return [$customer, $room, $booking];
}

test('a confirmed booking whose check-in date passed is marked no-show with a timestamp', function () {
    [$customer, $room, $booking] = makeConfirmedBooking(
        'no-show-past@example.com', 'NS1', makeRoomType('NoShowPast')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d')
    );

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 1')
        ->assertExitCode(0);

    $booking = $booking->fresh();
    expect($booking->status)->toBe('no_show');
    expect($booking->no_show_at)->not->toBeNull();
    expect($room->fresh()->status)->toBe('available');
});

test('a booking on its own check-in day is never marked no-show', function () {
    $this->freezeTime();
    try {
        [$customer, $room, $booking] = makeConfirmedBooking(
            'no-show-today@example.com', 'NS2', makeRoomType('NoShowToday')->id,
            Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d')
        );

        $this->artisan('bookings:mark-no-show')
            ->expectsOutputToContain('Marked 0')
            ->assertExitCode(0);

        expect($booking->fresh()->status)->toBe('confirmed');
        expect($room->fresh()->status)->toBe('reserved');
    } finally {
        $this->travelBack();
    }
});

test('a future-dated confirmed booking is never marked no-show', function () {
    [$customer, $room, $booking] = makeConfirmedBooking(
        'no-show-future@example.com', 'NS3', makeRoomType('NoShowFuture')->id,
        Carbon::today()->addDay()->format('Y-m-d'), Carbon::today()->addDay(3)->format('Y-m-d')
    );

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 0')
        ->assertExitCode(0);

    expect($booking->fresh()->status)->toBe('confirmed');
});

test('only confirmed bookings are targeted - every other status is untouched', function () {
    $customer = makeBookingCustomer('no-show-matrix@example.com');
    $today = Carbon::today()->format('Y-m-d');
    $yesterday = Carbon::today()->subDay()->format('Y-m-d');

    $type = makeRoomType('NoShowMatrix');

    $confirmed = makeConfirmedBooking('ns-matrix-confirmed@example.com', 'NS4a', $type->id, $yesterday, Carbon::today()->addDay(2)->format('Y-m-d'))[2];
    $pending = makeHoldingBooking($customer, makeBookingRoom('NS4b', $type->id), $today, Carbon::today()->addDay(2)->format('Y-m-d'), 'pending_payment');
    $pendingExpired = makeHoldingBooking($customer, makeBookingRoom('NS4c', $type->id), $today, Carbon::today()->addDay(2)->format('Y-m-d'), 'pending_payment', expired: true);
    $checkedIn = makeHoldingBooking($customer, makeBookingRoom('NS4d', $type->id), $yesterday, Carbon::today()->addDay(2)->format('Y-m-d'), 'checked_in');
    $checkedOut = makeHoldingBooking($customer, makeBookingRoom('NS4e', $type->id), $yesterday, Carbon::today()->subDay(1)->format('Y-m-d'), 'checked_out');
    $cancelled = makeHoldingBooking($customer, makeBookingRoom('NS4f', $type->id), $yesterday, Carbon::today()->addDay(2)->format('Y-m-d'), 'cancelled');
    $alreadyNoShow = makeHoldingBooking($customer, makeBookingRoom('NS4g', $type->id), $yesterday, Carbon::today()->addDay(2)->format('Y-m-d'), 'no_show');

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 1')
        ->assertExitCode(0);

    expect($confirmed->fresh()->status)->toBe('no_show');
    expect($pending->fresh()->status)->toBe('pending_payment');
    expect($pendingExpired->fresh()->status)->toBe('pending_payment');
    expect($checkedIn->fresh()->status)->toBe('checked_in');
    expect($checkedOut->fresh()->status)->toBe('checked_out');
    expect($cancelled->fresh()->status)->toBe('cancelled');
    expect($alreadyNoShow->fresh()->status)->toBe('no_show');
});

test('a no-showed multi-room booking releases every reserved room with audit rows', function () {
    $customer = makeBookingCustomer('no-show-multiroom@example.com');
    $type = makeRoomType('NoShowMultiRoom');
    $yesterday = Carbon::today()->subDay()->format('Y-m-d');
    $out = Carbon::today()->addDay(2)->format('Y-m-d');

    $roomA = makeBookingRoom('NS5a', $type->id);
    $roomB = makeBookingRoom('NS5b', $type->id);
    $booking = makeHoldingBooking($customer, $roomA, $yesterday, $out, 'confirmed');
    BookingRoom::create([
        'booking_id' => $booking->id,
        'room_id' => $roomB->id,
        'room_type_id' => $type->id,
        'price_per_night' => 100,
    ]);

    foreach ([$roomA, $roomB] as $room) {
        $room->status = 'reserved';
        $room->status_changed_at = now();
        $room->save();
    }

    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    expect($roomA->fresh()->status)->toBe('available');
    expect($roomB->fresh()->status)->toBe('available');

    $rows = RoomStatusLog::whereIn('room_id', [$roomA->id, $roomB->id])
        ->where('to_status', 'available')->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->from_status)->toBe('reserved');
        expect($row->changed_by)->toBeNull();
        expect($row->reason)->toBe('Guest did not check in');
    }
});

test('a no-showed booking frees its room for rebooking future dates', function () {
    [$customer, $room, $booking] = makeConfirmedBooking(
        'no-show-rebook@example.com', 'NS6', makeRoomType('NoShowRebook')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d')
    );

    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    $checkIn = Carbon::today()->addDay(5)->format('Y-m-d');
    $checkOut = Carbon::today()->addDay(7)->format('Y-m-d');

    postAvailability(['check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'rooms' => [$room->id]])
        ->assertJsonCount(1, 'data');

    $token = loginAsCustomer($this, 'no-show-rebook-b@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'rooms' => [$room->id],
        ])->assertStatus(201);
});

test('repeated no-show sweeps are idempotent', function () {
    [$customer, $room, $booking] = makeConfirmedBooking(
        'no-show-idem@example.com', 'NS7', makeRoomType('NoShowIdem')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d')
    );

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 1')
        ->assertExitCode(0);
    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 0')
        ->assertExitCode(0);

    expect($booking->fresh()->status)->toBe('no_show');
    expect(RoomStatusLog::where('room_id', $room->id)->where('to_status', 'available')->count())->toBe(1);
});

test('the sweep never clobbers a booking that was checked in mid-run', function () {
    [$customerA, $roomA, $bookingA] = makeConfirmedBooking(
        'no-show-race-a@example.com', 'NS8a', makeRoomType('NoShowRace')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d')
    );
    [$customerB, $roomB, $bookingB] = makeConfirmedBooking(
        'no-show-race-b@example.com', 'NS8b', makeRoomType('NoShowRace2')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d')
    );

    // Simulate the race: staff checked in booking B between the sweep's
    // candidate select and its transaction.
    $bookingB->status = 'checked_in';
    $bookingB->save();
    $roomB->status = 'occupied';
    $roomB->status_changed_at = now();
    $roomB->save();

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 1')
        ->assertExitCode(0);

    expect($bookingA->fresh()->status)->toBe('no_show');
    expect($bookingB->fresh()->status)->toBe('checked_in');
    expect($roomB->fresh()->status)->toBe('occupied');
});

test('a no-show booking is terminal on every staff lifecycle endpoint (409)', function () {
    [$customer, $room, $booking] = makeConfirmedBooking(
        'no-show-terminal@example.com', 'NS9', makeRoomType('NoShowTerminal')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d')
    );
    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    $token = loginAsEmployee($this, 'no-show-terminal-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/confirm")
        ->assertStatus(409);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(409);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-in")
        ->assertStatus(409);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-out")
        ->assertStatus(409);
});

test('a no-show booking rejects the owning customer cancel (409)', function () {
    $customer = makeBookingCustomer('no-show-selfcancel@example.com');
    $type = makeRoomType('NoShowSelfCancel');
    $room = makeBookingRoom('NS9', $type->id);
    $room->status = 'reserved';
    $room->status_changed_at = now();
    $room->save();
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');
    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    $token = $customer->createToken('test')->plainTextToken;
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
        ->assertStatus(409);

    expect($booking->fresh()->status)->toBe('no_show');
});

test('staff can list no-show bookings', function () {
    [$customer, $room, $booking] = makeConfirmedBooking(
        'no-show-list@example.com', 'NS10', makeRoomType('NoShowList')->id,
        Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d')
    );
    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    $token = loginAsEmployee($this, 'no-show-list-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/bookings?status=no_show')
        ->assertStatus(200)
        ->assertJsonFragment(['booking_number' => $booking->booking_number]);
});

test('the owning customer sees the no-show booking in their list', function () {
    $customer = makeBookingCustomer('no-show-list-c@example.com');
    $type = makeRoomType('NoShowListC');
    $room = makeBookingRoom('NS11', $type->id);
    $room->status = 'reserved';
    $room->status_changed_at = now();
    $room->save();
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');
    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    $token = $customer->createToken('test')->plainTextToken;
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/bookings')
        ->assertStatus(200)
        ->assertJsonFragment(['booking_number' => $booking->booking_number]);
});

test('the mark-no-show command is scheduled hourly', function () {
    $events = Schedule::events();

    $noShowSweep = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'bookings:mark-no-show'));

    expect($noShowSweep)->not->toBeNull();
    expect($noShowSweep->expression)->toBe('0 * * * *');
});

test('mixed candidates are counted and only eligible booked ones are marked', function () {
    $customer = makeBookingCustomer('no-show-mixed@example.com');
    $type = makeRoomType('NoShowMixed');
    $today = Carbon::today()->format('Y-m-d');
    $yesterday = Carbon::today()->subDay()->format('Y-m-d');

    $pastA = makeConfirmedBooking('ns-mixed-a@example.com', 'NS12a', $type->id, $yesterday, Carbon::today()->addDay(2)->format('Y-m-d'))[2];
    $pastB = makeConfirmedBooking('ns-mixed-b@example.com', 'NS12b', $type->id, $yesterday, Carbon::today()->addDay(2)->format('Y-m-d'))[2];
    $todayBooking = makeConfirmedBooking('ns-mixed-c@example.com', 'NS12c', $type->id, $today, Carbon::today()->addDay(2)->format('Y-m-d'))[2];
    $futureBooking = makeConfirmedBooking('ns-mixed-d@example.com', 'NS12d', $type->id, Carbon::today()->addDay(2)->format('Y-m-d'), Carbon::today()->addDay(4)->format('Y-m-d'))[2];
    $pending = makeHoldingBooking($customer, makeBookingRoom('NS12e', $type->id), Carbon::today()->addDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d'), 'pending_payment');

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 2')
        ->assertExitCode(0);

    expect($pastA->fresh()->status)->toBe('no_show');
    expect($pastB->fresh()->status)->toBe('no_show');
    expect($todayBooking->fresh()->status)->toBe('confirmed');
    expect($futureBooking->fresh()->status)->toBe('confirmed');
    expect($pending->fresh()->status)->toBe('pending_payment');
});

test('a confirmed booking whose room somehow is not reserved is still marked, with no room touch', function () {
    $customer = makeBookingCustomer('no-show-no-reserve@example.com');
    $type = makeRoomType('NoShowNoReserve');
    $room = makeBookingRoom('NS13', $type->id);
    $room->status = 'available';
    $room->status_changed_at = now();
    $room->save();
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 1')
        ->assertExitCode(0);

    expect($booking->fresh()->status)->toBe('no_show');
    expect($room->fresh()->status)->toBe('available');
    expect(RoomStatusLog::where('room_id', $room->id)->count())->toBe(0);
});

test('a multi-room booking releases only the reserved rooms', function () {
    $customer = makeBookingCustomer('no-show-partial@example.com');
    $type = makeRoomType('NoShowPartial');
    $yesterday = Carbon::today()->subDay()->format('Y-m-d');
    $out = Carbon::today()->addDay(2)->format('Y-m-d');

    $reservedRoom = makeBookingRoom('NS14a', $type->id);
    $freeRoom = makeBookingRoom('NS14b', $type->id);
    $booking = makeHoldingBooking($customer, $reservedRoom, $yesterday, $out, 'confirmed');
    BookingRoom::create([
        'booking_id' => $booking->id,
        'room_id' => $freeRoom->id,
        'room_type_id' => $type->id,
        'price_per_night' => 100,
    ]);

    $reservedRoom->status = 'reserved';
    $reservedRoom->status_changed_at = now();
    $reservedRoom->save();
    $freeRoom->status = 'available';
    $freeRoom->status_changed_at = now();
    $freeRoom->save();

    $this->artisan('bookings:mark-no-show')->assertExitCode(0);

    expect($booking->fresh()->status)->toBe('no_show');
    expect($reservedRoom->fresh()->status)->toBe('available');
    expect($freeRoom->fresh()->status)->toBe('available');
    $rows = RoomStatusLog::whereIn('room_id', [$reservedRoom->id, $freeRoom->id])
        ->where('to_status', 'available')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->room_id)->toBe($reservedRoom->id);
});