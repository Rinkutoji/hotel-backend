<?php

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\RoomStatusLog;
use Illuminate\Support\Carbon;

// --- Full lifecycle: create -> confirm -> check-in -> check-out ---

test('a booking completes the full lifecycle with room transitions and audit logs', function () {
    $customer = makeBookingCustomer('lifecycle-guest@example.com');
    $employee = \App\Models\Employee::create([
        'name' => 'Admin', 'email' => 'lifecycle-staff@example.com', 'password' => 'Password123!', 'role' => 'admin',
    ]);
    $type = makeRoomType('Lifecycle');
    $room = makeBookingRoom('L1', $type->id);

    // customer creates booking
    $bookingData = [
        'check_in_date' => Carbon::today()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ];

    \Laravel\Sanctum\Sanctum::actingAs($customer);
    $create = $this->postJson('/api/v1/customer/bookings', $bookingData)
        ->assertStatus(201);

    $bookingId = $create->json('data.id');

    // room still available while pending_payment
    expect($room->fresh()->status)->toBe('available');

    // staff confirm
    \Laravel\Sanctum\Sanctum::actingAs($employee);
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/confirm")
        ->assertStatus(200);
    expect($room->fresh()->status)->toBe('reserved');

    // staff check-in
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/check-in")
        ->assertStatus(200);
    expect($room->fresh()->status)->toBe('occupied');

    // staff check-out
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/check-out")
        ->assertStatus(200);
    expect($room->fresh()->status)->toBe('cleaning');

    // booking statuses along the way
    expect(Booking::find($bookingId)->status)->toBe('checked_out');

    // actual check-in/out timestamps snapshot on booking_rooms
    $bookingRoom = BookingRoom::where('booking_id', $bookingId)->first();
    expect($bookingRoom->actual_check_in_at)->not->toBeNull();
    expect($bookingRoom->actual_check_out_at)->not->toBeNull();

    // audit log rows: available->reserved, reserved->occupied, occupied->cleaning
    expect(RoomStatusLog::where('room_id', $room->id)->count())->toBe(3);
    $toStatuses = RoomStatusLog::where('room_id', $room->id)->pluck('to_status')->all();
    expect($toStatuses)->toBe(['reserved', 'occupied', 'cleaning']);
});

// --- Confirmed booking can be cancelled before check-in ---

test('a confirmed booking can be cancelled by staff before check-in, returning rooms', function () {
    $customer = makeBookingCustomer('lifecycle-cancel@example.com');
    $type = makeRoomType('LifecycleCancel');
    $room = makeBookingRoom('L2', $type->id);
    $token = loginAsEmployee($this, 'lifecycle-cancel-staff@example.com', 'admin');

    $booking = Booking::create([
        'booking_number' => 'BK-' . strtoupper(uniqid()),
        'customer_id' => $customer->id,
        'check_in_date' => Carbon::today()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'total_amount' => 200,
        'payment_deadline_at' => now()->addHours(2),
    ]);

    $booking->status = 'confirmed';
    $booking->save();

    BookingRoom::create([
        'booking_id' => $booking->id,
        'room_id' => $room->id,
        'room_type_id' => $type->id,
        'price_per_night' => 100,
    ]);

    $room->status = 'reserved';
    $room->save();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Guest requested'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');

    expect($room->fresh()->status)->toBe('available');
});

// --- Invalid transitions stay invalid -> 409 ---

test('checking out a booking that is not checked in returns 409', function () {
    $customer = makeBookingCustomer('lifecycle-cout@example.com');
    $type = makeRoomType('LifecycleCout');
    $room = makeBookingRoom('L3', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'confirmed');
    $token = loginAsEmployee($this, 'lifecycle-cout-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-out")
        ->assertStatus(409);
});

test('re-check-in of an already checked-in booking returns 409', function () {
    $customer = makeBookingCustomer('lifecycle-recin@example.com');
    $type = makeRoomType('LifecycleRecin');
    $room = makeBookingRoom('L4', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'checked_in');
    $room->status = 'occupied';
    $room->save();
    $token = loginAsEmployee($this, 'lifecycle-recin-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-in")
        ->assertStatus(409);
});