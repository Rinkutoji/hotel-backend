<?php

use App\Models\Booking;
use App\Models\RoomStatusLog;
use Illuminate\Support\Carbon;

// --- Staff list / show ---

test('staff can list all bookings with pagination metadata', function () {
    $customer = makeBookingCustomer('staff-list-guest@example.com');
    $type = makeRoomType('StaffList');
    $room = makeBookingRoom('S1', $type->id);
    makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    $token = loginAsEmployee($this, 'staff-list@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/bookings');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('meta.total', 1);
});

test('staff can filter bookings by status and search', function () {
    $customerA = makeBookingCustomer('staff-fga@example.com');
    $customerB = makeBookingCustomer('staff-fgb@example.com');
    $type = makeRoomType('StaffFilter');

    $roomA = makeBookingRoom('S2a', $type->id);
    $roomB = makeBookingRoom('S2b', $type->id);

    $bA = makeHoldingBooking($customerA, $roomA, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'confirmed');
    makeHoldingBooking($customerB, $roomB, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    $token = loginAsEmployee($this, 'staff-filter@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/bookings?status=confirmed')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $bA->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/bookings?search=' . $bA->booking_number)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $bA->id);
});

test('staff can view any booking', function () {
    $customer = makeBookingCustomer('staff-view-guest@example.com');
    $type = makeRoomType('StaffView');
    $room = makeBookingRoom('S3', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'confirmed');

    $token = loginAsEmployee($this, 'staff-view@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/bookings/{$booking->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $booking->id);
});

// --- Auth matrix ---

test('admin, manager and receptionist can all confirm bookings', function () {
    foreach (['admin', 'manager', 'receptionist'] as $role) {
        $customer = makeBookingCustomer("matrix-{$role}@example.com");
        $type = makeRoomType("Matrix-{$role}");
        $room = makeBookingRoom("SM-{$role}", $type->id);
        $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

        $token = loginAsEmployee($this, "staff-matrix-{$role}@example.com", $role);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/bookings/{$booking->id}/confirm")
            ->assertStatus(200);
    }
});

test('a customer cannot use staff booking routes', function () {
    $customer = makeBookingCustomer('staff-blocked-guest@example.com');
    $type = makeRoomType('StaffBlocked');
    $room = makeBookingRoom('S4', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    $token = loginAsCustomer($this, 'cust-staffroute@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/bookings')
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/confirm")
        ->assertStatus(403);
});

test('a guest cannot use staff booking routes', function () {
    $this->getJson('/api/v1/staff/bookings')->assertStatus(401);
    $this->postJson('/api/v1/staff/bookings/1/confirm')->assertStatus(401);
});

// --- Staff confirm lifecycle ---

test('staff confirm moves a booking to confirmed and reserves its rooms with audit log', function () {
    $customer = makeBookingCustomer('staff-confirm-guest@example.com');
    $type = makeRoomType('StaffConfirm');
    $room = makeBookingRoom('S5', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');
    $employee = \App\Models\Employee::where('email', 'staff-confirm@example.com')->first();

    $token = loginAsEmployee($this, 'staff-confirm@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/confirm");

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'confirmed');

    expect($room->fresh()->status)->toBe('reserved');

    $log = RoomStatusLog::where('room_id', $room->id)->first();
    expect($log->from_status)->toBe('available');
    expect($log->to_status)->toBe('reserved');
    expect($log->reason)->toBe('Booking confirmed');
});

// --- Staff cancel ---

test('staff can cancel a booking with a required reason flow', function () {
    $customer = makeBookingCustomer('staff-cancel-guest@example.com');
    $type = makeRoomType('StaffCancel');
    $room = makeBookingRoom('S6', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    $token = loginAsEmployee($this, 'staff-cancel@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Overbooked']);

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'cancelled');
    $response->assertJsonPath('data.cancellation_reason', 'Overbooked');
});

test('staff cancel of a confirmed booking returns its rooms to available', function () {
    $customer = makeBookingCustomer('staff-cancel-confirmed@example.com');
    $type = makeRoomType('StaffCancelConfirmed');
    $room = makeBookingRoom('S7', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'confirmed');
    $room->status = 'reserved';
    $room->status_changed_at = now();
    $room->save();

    $token = loginAsEmployee($this, 'staff-cancel-confirmed@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'No show prevention'])
        ->assertStatus(200);

    expect($room->fresh()->status)->toBe('available');

    $log = RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')
        ->first();
    expect($log)->not->toBeNull();
});

test('staff cannot cancel a checked_out booking', function () {
    $customer = makeBookingCustomer('staff-cancel-terminal@example.com');
    $type = makeRoomType('StaffCancelTerminal');
    $room = makeBookingRoom('S8', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'checked_out');

    $token = loginAsEmployee($this, 'staff-cancel-terminal@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Late'])
        ->assertStatus(409);
});

test('staff cannot cancel a checked_in booking', function () {
    $customer = makeBookingCustomer('staff-cancel-checked-in@example.com');
    $type = makeRoomType('StaffCancelCheckedIn');
    $room = makeBookingRoom('S11', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'checked_in');
    $room->status = 'occupied';
    $room->save();

    $token = loginAsEmployee($this, 'staff-cancel-checked-in@example.com', 'admin');

    // Plan §6 has no checked_in -> cancelled edge - a checked-in booking is
    // only ended by check-out, otherwise the room stays 'occupied' with no
    // active booking to release it.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(409);

    expect($booking->fresh()->status)->toBe('checked_in');
    expect($room->fresh()->status)->toBe('occupied');
});

// --- Staff check-in guard ---

test('staff cannot check in a booking before its check-in date', function () {
    $customer = makeBookingCustomer('staff-early-guest@example.com');
    $type = makeRoomType('StaffEarly');
    $room = makeBookingRoom('S9', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->addDay()->format('Y-m-d'), Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');

    $token = loginAsEmployee($this, 'staff-early@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-in")
        ->assertStatus(409);
});

test('staff cannot check in a booking that is not confirmed', function () {
    $customer = makeBookingCustomer('staff-cin-pending@example.com');
    $type = makeRoomType('StaffCiPending');
    $room = makeBookingRoom('S10', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    $token = loginAsEmployee($this, 'staff-cin-pending@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-in")
        ->assertStatus(409);
});