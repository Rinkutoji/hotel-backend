<?php

use App\Models\BookingRoom;
use Illuminate\Support\Carbon;

// --- Server-side pricing ---

test('total amount is nights times base price, checkout day not billed', function () {
    $type = makeRoomType('Priced');
    $room = makeBookingRoom('P1', $type->id);
    $token = loginAsCustomer($this, 'price-calc@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    // 2 nights (d1..d3) x 100
    $response->assertJsonPath('data.total_amount', '200.00');
});

test('pricing uses room type base price snapshot stored per booking room', function () {
    $type = makeRoomType('Snapshot');
    $room = makeBookingRoom('P2', $type->id);
    $token = loginAsCustomer($this, 'price-snapshot@example.com');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    $bookingId = $create->json('data.id');
    $bookingRoom = BookingRoom::where('booking_id', $bookingId)->first();

    expect($bookingRoom->price_per_night)->toBe('100.00');
    expect($bookingRoom->room_type_id)->toBe($type->id);
});

test('a client-sent price is silently ignored', function () {
    $type = makeRoomType('TrustNobody');
    $room = makeBookingRoom('P3', $type->id);
    $token = loginAsCustomer($this, 'price-sneaky@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => [$room->id],
            'price_per_night' => 1,
            'total_amount' => 1,
        ])
        ->assertStatus(201);

    $response->assertJsonPath('data.total_amount', '100.00');
    expect(BookingRoom::where('booking_id', $response->json('data.id'))->first()->price_per_night)->toBe('100.00');
});

test('a later base_price increase does not change an existing booking snapshot', function () {
    $type = makeRoomType('PriceRise');
    $room = makeBookingRoom('P4', $type->id);
    $token = loginAsCustomer($this, 'price-rise@example.com');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    $type->update(['base_price' => 500]);

    $bookingId = $create->json('data.id');
    expect(BookingRoom::where('booking_id', $bookingId)->first()->price_per_night)->toBe('100.00');
    expect(\App\Models\Booking::find($bookingId)->total_amount)->toBe('200.00');
});

test('a multi-room booking sums price across rooms', function () {
    $type = makeRoomType('MultiRoom');
    $roomA = makeBookingRoom('P5a', $type->id);
    $roomB = makeBookingRoom('P5b', $type->id);
    $token = loginAsCustomer($this, 'price-multi@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => [$roomA->id, $roomB->id],
        ])
        ->assertStatus(201);

    // 1 night x (100 + 100)
    $response->assertJsonPath('data.total_amount', '200.00');
    $response->assertJsonCount(2, 'data.booking_rooms');
});

test('different base prices across types are honored independently', function () {
    $basic = makeRoomType('Basic');
    $luxury = makeRoomType('Luxury');
    $luxury->update(['base_price' => 400]);

    $roomA = makeBookingRoom('P6a', $basic->id);
    $roomB = makeBookingRoom('P6b', $luxury->id);
    $token = loginAsCustomer($this, 'price-mixed@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => [$roomA->id, $roomB->id],
        ])
        ->assertStatus(201);

    $response->assertJsonPath('data.total_amount', '500.00');
});

test('fractional pricing accumulates in integer cents without float drift', function () {
    $type = makeRoomType('Fractional');
    $type->update(['base_price' => 99.99]);
    $room = makeBookingRoom('P7', $type->id);
    $token = loginAsCustomer($this, 'price-fractional@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(4)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    // 3 nights x 99.99 = 299.97 - must be exact (integer cents, plan §5).
    $response->assertJsonPath('data.total_amount', '299.97');
    expect(BookingRoom::where('booking_id', $response->json('data.id'))->first()->price_per_night)->toBe('99.99');
});