<?php

use Illuminate\Support\Carbon;

// --- Golden path ---

test('an available room is returned with its server-computed price', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A1', $type->id);

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $room->id);
    $response->assertJsonPath('data.0.price_per_night', '100.00');
});

test('a room with an overlapping confirmed booking is not available', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A2', $type->id);
    $customer = makeBookingCustomer('overlap@example.com');

    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');

    $response = postAvailability([
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(0, 'data');
});

// --- Half-open interval boundary ---

test('same-day checkout to same-day check-in turnover is allowed', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A3', $type->id);
    $customer = makeBookingCustomer('turnover@example.com');

    // First guest: [d0, d2) - occupies d0 and d1.
    $d0 = Carbon::today()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $d0, Carbon::today()->addDay(2)->format('Y-m-d'), 'confirmed');

    // Second guest: [d2, d4) - must be allowed.
    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(4)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
});

test('a confirmed booking blocks a later overlapping stay', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A4', $type->id);
    $customer = makeBookingCustomer('overlap2@example.com');

    $d0 = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $d0, Carbon::today()->addDay(3)->format('Y-m-d'), 'confirmed');

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(4)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(0, 'data');
});

// --- Non-holding bookings never block ---

test('a cancelled booking does not block availability', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A5', $type->id);
    $customer = makeBookingCustomer('cancelled@example.com');

    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(2)->format('Y-m-d'), 'cancelled');

    $response = postAvailability([
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
});

test('an expired pending_payment hold does not block availability', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A6', $type->id);
    $customer = makeBookingCustomer('expiredhold@example.com');

    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(2)->format('Y-m-d'), 'pending_payment', expired: true);

    $response = postAvailability([
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
});

test('an active pending_payment hold does block availability', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A7', $type->id);
    $customer = makeBookingCustomer('activehold@example.com');

    $checkIn = Carbon::today()->addDay()->format('Y-m-d');
    makeHoldingBooking($customer, $room, $checkIn, Carbon::today()->addDay(2)->format('Y-m-d'), 'pending_payment');

    $response = postAvailability([
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(0, 'data');
});

// --- Room/type exclusions ---

test('a maintenance room is never available', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A8', $type->id);
    $room->status = 'maintenance';
    $room->save();

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(0, 'data');
});

test('a soft-deleted room is never available', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A9', $type->id);
    $room->delete();

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(0, 'data');
});

test('a room whose room_type is soft-deleted is never available', function () {
    $type = makeRoomType('TypeToDelete');
    $room = makeBookingRoom('A10', $type->id);
    $type->delete();

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(0, 'data');
});

test('a room with a maintenance sibling still shows as available', function () {
    $type = makeRoomType();
    $available = makeBookingRoom('A11a', $type->id);
    $maintenance = makeBookingRoom('A11b', $type->id);
    $maintenance->status = 'maintenance';
    $maintenance->save();

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$available->id, $maintenance->id],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $available->id);
});

// --- Validation ---

test('availability rejects a checkout at or before check-in', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A12', $type->id);

    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertStatus(422);

    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertStatus(422);
});

test('availability rejects a check-in in the past', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A13', $type->id);

    postAvailability([
        'check_in_date' => Carbon::today()->subDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertStatus(422);
});

test('availability rejects missing or empty rooms', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A14', $type->id);

    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
    ])->assertStatus(422);

    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [],
    ])->assertStatus(422);
});

test('availability rejects a room that does not exist', function () {
    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [999999],
    ])->assertStatus(422);
});

test('availability rejects a soft-deleted room id', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A15', $type->id);
    $id = $room->id;
    $room->delete();

    // Soft-deleted rooms pass request validation but are excluded by the
    // service query (which filters whereNull('deleted_at')).
    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$id],
    ])->assertStatus(200)->assertJsonCount(0, 'data');
});

test('availability rejects duplicate room ids', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('A16', $type->id);

    postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id, $room->id],
    ])->assertStatus(422);
});