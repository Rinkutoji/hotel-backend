<?php

use App\Models\Booking;
use Illuminate\Support\Carbon;

// --- Expired holds are non-holding ---

test('an expired hold does not block availability or prevent a new booking', function () {
    $customer = makeBookingCustomer('hold-expired@example.com');
    $type = makeRoomType('HoldExpired');
    $room = makeBookingRoom('H1', $type->id);
    $checkIn = Carbon::today()->addDay()->format('Y-m-d');

    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(3)->format('Y-m-d'), 'pending_payment', expired: true);

    postAvailability([
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertJsonCount(1, 'data');

    $token = loginAsCustomer($this, 'hold-expired-b@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'), 'rooms' => [$room->id],
        ])->assertStatus(201);
});

// --- Release expired holds command ---

test('the release-expired-holds command cancels expired pending payments', function () {
    $customer = makeBookingCustomer('hold-sweep@example.com');
    $type = makeRoomType('HoldSweep');
    $room = makeBookingRoom('H2', $type->id);
    $checkIn = Carbon::today()->addDay()->format('Y-m-d');

    $expired = makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(3)->format('Y-m-d'), 'pending_payment', expired: true);

    $futureType = makeRoomType('HoldFuture');
    $futureRoom = makeBookingRoom('H3', $futureType->id);
    $notExpired = makeHoldingBooking($customer, $futureRoom, $checkIn, Carbon::today()->addDay(3)->format('Y-m-d'), 'pending_payment');

    $this->artisan('bookings:release-expired-holds')
        ->expectsOutputToContain('Released 1')
        ->assertExitCode(0);

    expect($expired->fresh()->status)->toBe('cancelled');
    expect($expired->fresh()->cancelled_at)->not->toBeNull();
    expect($expired->fresh()->cancellation_reason)->toBe('Payment deadline passed');
    expect($notExpired->fresh()->status)->toBe('pending_payment');
});

test('the release-expired-holds command is scheduled hourly', function () {
    $events = \Illuminate\Support\Facades\Schedule::events();

    $holdSweep = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'bookings:release-expired-holds'));

    expect($holdSweep)->not->toBeNull();
    expect($holdSweep->expression)->toBe('0 * * * *');
});

// --- Staff confirm on an expired hold ---

test('staff confirm on an expired booking auto-cancels and returns 409', function () {
    $customer = makeBookingCustomer('hold-confirm-expired@example.com');
    $type = makeRoomType('HoldConfirmExpired');
    $room = makeBookingRoom('H4', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment', expired: true);

    $token = loginAsEmployee($this, 'hold-confirm-expired-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/confirm")
        ->assertStatus(409);

    expect($booking->fresh()->status)->toBe('cancelled');
    expect($booking->fresh()->cancellation_reason)->toBe('Payment deadline passed');
});

// --- Customer cancel on an expired hold ---

test('a customer cannot cancel their own expired booking', function () {
    $customer = makeBookingCustomer('hold-selfcancel-expired@example.com');
    $type = makeRoomType('HoldSelfCancel');
    $room = makeBookingRoom('H5', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment', expired: true);

    $token = $customer->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
        ->assertStatus(409);

    expect($booking->fresh()->status)->toBe('pending_payment');
});