<?php

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Support\Carbon;

function bookingPayload(Room $room, int $nights = 2): array
{
    $checkIn = Carbon::today()->addDay();
    return [
        'check_in_date' => $checkIn->format('Y-m-d'),
        'check_out_date' => $checkIn->copy()->addDays($nights)->format('Y-m-d'),
        'rooms' => [$room->id],
    ];
}

test('a customer can create a booking - pending_payment, snapshot pricing, deadline', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('C1', $type->id);
    $token = loginAsCustomer($this, 'cust-create@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room, 2));

    $response->assertStatus(201);
    $response->assertJsonPath('data.status', 'pending_payment');
    $response->assertJsonPath('data.total_amount', '200.00');
    $response->assertJsonPath('data.booking_number', $response->json('data.booking_number'));

    // booking_number format: BK- + uppercase alphanumeric
    $billing = $response->json('data.booking_number');
    expect(str_starts_with($billing, 'BK-'))->toBeTrue();

    // payment_deadline_at snapshot = created_at + hold_hours
    $booking = Booking::where('booking_number', $billing)->first();
    $expectedDeadline = $booking->created_at->copy()->addHours(config('booking.hold_hours'));
    expect($booking->payment_deadline_at->toDateTimeString())->toBe($expectedDeadline->toDateTimeString());

    // booking_rooms snapshots
    $response->assertJsonCount(1, 'data.booking_rooms');
    $response->assertJsonPath('data.booking_rooms.0.room_id', $room->id);
    $response->assertJsonPath('data.booking_rooms.0.room_type_id', $type->id);
    $response->assertJsonPath('data.booking_rooms.0.price_per_night', '100.00');
});

test('room status is unchanged by creating a pending_payment booking', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('C2', $type->id);
    $token = loginAsCustomer($this, 'cust-status@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    expect($room->fresh()->status)->toBe('available');
});

// --- Create validation ---

test('create booking validates check-in/out rules', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('C3', $type->id);
    $token = loginAsCustomer($this, 'cust-validate@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->subDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'rooms' => [$room->id],
        ])->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'rooms' => [$room->id],
        ])->assertStatus(422);
});

test('create booking rejects a non-existent or soft-deleted room id', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('C4', $type->id);
    $deletedRoom = makeBookingRoom('C4d', $type->id);
    $deletedId = $deletedRoom->id;
    $deletedRoom->delete();
    $token = loginAsCustomer($this, 'cust-room@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => [999999],
        ])->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => [$deletedId],
        ])->assertStatus(422);
});

test('create booking rejects stays longer than the configured max', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('C5', $type->id);
    $token = loginAsCustomer($this, 'cust-long@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room, 31))
        ->assertStatus(422);
});

test('create booking rejects requests with too many rooms', function () {
    $type = makeRoomType();
    $roomIds = collect(range(1, 6))->map(fn ($i) => makeBookingRoom("C{$i}", $type->id)->id)->all();
    $token = loginAsCustomer($this, 'cust-many@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => $roomIds,
        ])->assertStatus(422);
});

// --- List / show / ownership ---

test('a customer can list their own bookings', function () {
    $token = loginAsCustomer($this, 'cust-list@example.com');
    $type = makeRoomType('List');
    $room = makeBookingRoom('C6', $type->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/bookings');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
});

test('a customer can filter their bookings by status', function () {
    $token = loginAsCustomer($this, 'cust-filter@example.com');
    $type = makeRoomType('Filter');
    $room = makeBookingRoom('C7', $type->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/bookings?status=confirmed')
        ->assertJsonCount(0, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/bookings?status=pending_payment')
        ->assertJsonCount(1, 'data');
});

test('a customer can view their own booking', function () {
    $token = loginAsCustomer($this, 'cust-view@example.com');
    $type = makeRoomType('View');
    $room = makeBookingRoom('C8', $type->id);

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $bookingId = $create->json('data.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/customer/bookings/{$bookingId}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $bookingId);
});

test('viewing another customer booking returns 404, never leaks data', function () {
    $customerA = makeBookingCustomer('cust-a@example.com');
    $customerB = makeBookingCustomer('cust-b@example.com');
    $type = makeRoomType('Ownership');
    $room = makeBookingRoom('C9', $type->id);

    \Laravel\Sanctum\Sanctum::actingAs($customerA);
    $create = $this->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $bookingId = $create->json('data.id');

    \Laravel\Sanctum\Sanctum::actingAs($customerB);
    $this->getJson("/api/v1/customer/bookings/{$bookingId}")
        ->assertStatus(404);

    $this->postJson("/api/v1/customer/bookings/{$bookingId}/cancel", ['reason' => 'x'])
        ->assertStatus(404);
});

test('a customer cannot action another customer booking through the cancel endpoint', function () {
    $customerA = makeBookingCustomer('cust-ca@example.com');
    $customerB = makeBookingCustomer('cust-cb@example.com');
    $type = makeRoomType('Cancel-Ownership');
    $room = makeBookingRoom('C10', $type->id);

    \Laravel\Sanctum\Sanctum::actingAs($customerA);
    $create = $this->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $bookingId = $create->json('data.id');

    \Laravel\Sanctum\Sanctum::actingAs($customerB);
    $this->postJson("/api/v1/customer/bookings/{$bookingId}/cancel", ['reason' => 'x'])
        ->assertStatus(404);
});

// --- Auth matrix ---

test('a staff token cannot use customer booking routes', function () {
    $type = makeRoomType('AuthMatrix');
    $room = makeBookingRoom('C11', $type->id);
    $token = loginAsEmployee($this, 'staff-custroute@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/bookings')
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(403);
});

test('a guest cannot use customer booking routes', function () {
    $type = makeRoomType('GuestBlocked');
    $room = makeBookingRoom('C12', $type->id);

    $this->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(401);

    $this->getJson('/api/v1/customer/bookings')
        ->assertStatus(401);
});

// --- Customer self-cancel ---

test('a customer can cancel their own pending_payment booking', function () {
    $token = loginAsCustomer($this, 'cust-selfcancel@example.com');
    $type = makeRoomType('SelfCancel');
    $room = makeBookingRoom('C13', $type->id);

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $bookingId = $create->json('data.id');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/customer/bookings/{$bookingId}/cancel", ['reason' => 'Plans changed']);

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'cancelled');
    $response->assertJsonPath('data.cancellation_reason', 'Plans changed');
    expect($response->json('data.cancelled_at'))->not->toBeNull();
});

test('a customer cannot cancel a confirmed booking', function () {
    $token = loginAsCustomer($this, 'cust-nocancel-confirmed@example.com');
    $type = makeRoomType('NoSelfCancel');
    $room = makeBookingRoom('C14', $type->id);

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload($room))
        ->assertStatus(201);

    $bookingId = $create->json('data.id');
    $booking = Booking::find($bookingId);
    $booking->status = 'confirmed';
    $booking->save();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/customer/bookings/{$bookingId}/cancel")
        ->assertStatus(409);
});