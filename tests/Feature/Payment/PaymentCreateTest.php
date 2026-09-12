<?php

use App\Models\Payment;

test('a customer can create a payment for their own booking', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P1', $type->id);
    $customer = makeBookingCustomer('pay-create@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');

    $token = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'pay-create@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/payments', [
            'booking_id' => $booking->id,
            'method' => 'bank_transfer',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', '200.00')
        ->assertJsonPath('data.method', 'bank_transfer')
        ->assertJsonPath('data.booking_id', $booking->id);

    expect(Payment::count())->toBe(1);
    expect($booking->fresh()->status)->toBe('pending_payment');
});

test('the cash method is stored on the payment', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P2', $type->id);
    $customer = makeBookingCustomer('pay-cash@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $booking->id,
        'method' => 'cash',
    ])->assertStatus(201)->assertJsonPath('data.method', 'cash');
});

test('client-sent amount and status are silently ignored - money comes from the server', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P3', $type->id);
    $customer = makeBookingCustomer('pay-inject@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $booking->id,
        'method' => 'bank_transfer',
        // Everything below is NOT fillable / NOT accepted - must be ignored.
        'amount' => 1,
        'status' => 'verified',
        'verified_at' => now()->toDateTimeString(),
        'verified_by' => 1,
        'customer_id' => 9999,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', '200.00');

    $payment = Payment::first();
    expect($payment->amount)->toBe('200.00');
    expect($payment->status)->toBe('pending');
    expect($payment->verified_at)->toBeNull();
    expect($payment->verified_by)->toBeNull();
});

test('a duplicate payment for the same booking is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P4', $type->id);
    $customer = makeBookingCustomer('pay-duplicate@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    makePaymentFor($booking);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $booking->id,
        'method' => 'bank_transfer',
    ])->assertStatus(409);

    expect(Payment::count())->toBe(1);
});

test('a customer cannot create a payment for another customer booking - 404', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P5', $type->id);
    $owner = makeBookingCustomer('pay-owner@example.com');
    $booking = makeHoldingBooking($owner, $room, '2026-09-10', '2026-09-12', 'pending_payment');

    $intruder = makeBookingCustomer('pay-intruder@example.com');
    \Laravel\Sanctum\Sanctum::actingAs($intruder);

    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $booking->id,
        'method' => 'bank_transfer',
    ])->assertStatus(404);

    expect(Payment::count())->toBe(0);
});

test('creating a payment for a non-existent booking returns 404', function () {
    $customer = makeBookingCustomer('pay-missing@example.com');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => 999999,
        'method' => 'bank_transfer',
    ])->assertStatus(404);
});

test('creating a payment for a cancelled booking is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P6', $type->id);
    $customer = makeBookingCustomer('pay-cancelled@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'cancelled');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $booking->id,
        'method' => 'bank_transfer',
    ])->assertStatus(409);
});

test('creating a payment for a checked-out booking is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('P7', $type->id);
    $customer = makeBookingCustomer('pay-checkedout@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'checked_out');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $booking->id,
        'method' => 'bank_transfer',
    ])->assertStatus(409);
});

test('creating a payment validates booking_id and method - 422', function () {
    $customer = makeBookingCustomer('pay-validation@example.com');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments', ['method' => 'cash'])->assertStatus(422);
    $this->postJson('/api/v1/customer/payments', ['booking_id' => 'abc'])->assertStatus(422);
    $this->postJson('/api/v1/customer/payments', ['booking_id' => 1, 'method' => 'paypal'])->assertStatus(422);
    $this->postJson('/api/v1/customer/payments', [])->assertStatus(422);
});

test('a guest cannot create a payment - 401', function () {
    $this->postJson('/api/v1/customer/payments', [
        'booking_id' => 1, 'method' => 'bank_transfer',
    ])->assertStatus(401);
});

test('a staff token cannot use the customer payment route - 403', function () {
    $token = loginAsEmployee($this, 'staff-paycreate@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/payments', [
            'booking_id' => 1, 'method' => 'bank_transfer',
        ])->assertStatus(403);
});