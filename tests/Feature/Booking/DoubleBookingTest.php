<?php

use App\Models\Booking;
use Illuminate\Support\Carbon;

// --- Double-booking protection ---

test('a second booking for the same room on overlapping dates is rejected', function () {
    $type = makeRoomType('DoubleBlock');
    $room = makeBookingRoom('D1', $type->id);
    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    $checkOut = Carbon::today()->addDay(3)->format('Y-m-d');

    $tokenA = loginAsCustomer($this, 'double-a@example.com');
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'rooms' => [$room->id],
        ])->assertStatus(201);

    $tokenB = loginAsCustomer($this, 'double-b@example.com');
    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'rooms' => [$room->id],
        ])->assertStatus(409);
});

test('a confirmed booking also blocks a second booking on the same dates', function () {
    $customer = makeBookingCustomer('double-confirmed@example.com');
    $type = makeRoomType('DoubleConfirmed');
    $room = makeBookingRoom('D2', $type->id);
    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(3)->format('Y-m-d'), 'confirmed');

    $token = loginAsCustomer($this, 'double-confirmed-b@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'), 'rooms' => [$room->id],
        ])->assertStatus(409);
});

// --- Non-overlap allowed ---

test('non-overlapping consecutive stays on the same room are allowed', function () {
    $type = makeRoomType('DoubleOk');
    $room = makeBookingRoom('D3', $type->id);

    $tokenA = loginAsCustomer($this, 'double-ok-a@example.com');
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])->assertStatus(201);

    // d3..d5 does not overlap d1..d3 (half-open interval).
    $tokenB = loginAsCustomer($this, 'double-ok-b@example.com');
    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(5)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])->assertStatus(201);
});

// --- Cancel-then-rebook ---

test('after cancelling a hold, the room can be booked again', function () {
    $type = makeRoomType('DoubleRebook');
    $room = makeBookingRoom('D4', $type->id);
    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    $checkOut = Carbon::today()->addDay(3)->format('Y-m-d');

    $token = loginAsCustomer($this, 'double-rebook@example.com');
    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'rooms' => [$room->id],
        ])->assertStatus(201);

    $bookingId = $create->json('data.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/customer/bookings/{$bookingId}/cancel")
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'rooms' => [$room->id],
        ])->assertStatus(201);
});

// --- Availability tells the truth up front ---

test('availability and create agree: overlapping room is unavailable and create rejected', function () {
    $customer = makeBookingCustomer('double-agree@example.com');
    $type = makeRoomType('DoubleAgree');
    $room = makeBookingRoom('D5', $type->id);
    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');

    postAvailability([
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertJsonCount(0, 'data');

    $token = loginAsCustomer($this, 'double-agree-b@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => $checkIn, 'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'), 'rooms' => [$room->id],
        ])->assertStatus(409);
});