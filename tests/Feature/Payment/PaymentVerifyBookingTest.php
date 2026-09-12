<?php

use App\Models\RoomStatusLog;
use Illuminate\Support\Facades\Storage;

function employeeByEmail(string $email): \App\Models\Employee
{
    return \App\Models\Employee::where('email', $email)->firstOrFail();
}

test('verifying a receipted payment atomically confirms the booking, reserves the room and logs it', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('V1', $type->id);
    $customer = makeBookingCustomer('verify-atomic@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'verify-admin@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'verified');

    $employee = employeeByEmail('verify-admin@example.com');

    $payment->refresh();
    expect($payment->status)->toBe('verified');
    expect($payment->verified_at)->not->toBeNull();
    expect($payment->verified_by)->toBe($employee->id);

    expect($booking->fresh()->status)->toBe('confirmed');

    $room->refresh();
    expect($room->status)->toBe('reserved');

    $log = RoomStatusLog::where('room_id', $room->id)->first();
    expect($log)->not->toBeNull();
    expect($log->from_status)->toBe('available');
    expect($log->to_status)->toBe('reserved');
    expect($log->reason)->toBe('Booking confirmed');
});

test('verifying a payment for an already-confirmed booking is safe and does not double the room transition', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V2', $type->id);
    $customer = makeBookingCustomer('verify-already@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'confirmed');
    RoomStatusLog::create([
        'room_id' => $room->id, 'from_status' => 'available', 'to_status' => 'reserved', 'reason' => 'Booking confirmed',
    ]);
    $room->status = 'reserved';
    $room->save();
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'verify-manager@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200);

    expect($payment->fresh()->status)->toBe('verified');
    expect($booking->fresh()->status)->toBe('confirmed');
    expect($room->fresh()->status)->toBe('reserved');
    expect(RoomStatusLog::where('room_id', $room->id)->count())->toBe(1);
});

test('both a receptionist and an admin can verify a payment', function () {
    foreach (['receptionist', 'admin'] as $role) {
        Storage::fake('receipts');
        $type = makeRoomType();
        $room = makeBookingRoom('V3-' . $role, $type->id);
        $customer = makeBookingCustomer("verify-{$role}@example.com");
        $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
        $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

        $token = loginAsEmployee($this, "verify-actor-{$role}@example.com", $role);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
            ->assertStatus(200);
    }
});

test('verifying a payment for a cancelled booking is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V4', $type->id);
    $customer = makeBookingCustomer('verify-cancelled@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'cancelled');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'verify-cancel@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(409);
});

test('verifying a payment for an expired hold auto-cancels the booking then returns 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V5', $type->id);
    $customer = makeBookingCustomer('verify-expired@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment', expired: true);
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'verify-expiry@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify');

    $response->assertStatus(409);
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($room->fresh()->status)->toBe('available');
    expect($payment->fresh()->status)->toBe('receipt_uploaded');
    expect($payment->fresh()->verified_by)->toBeNull();
});

test('verifying the same payment twice is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V6', $type->id);
    $customer = makeBookingCustomer('verify-twice@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'verify-twice@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(409);
});

test('verifying a payment that has not received a receipt yet is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V7', $type->id);
    $customer = makeBookingCustomer('verify-pending@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'pending');

    $token = loginAsEmployee($this, 'verify-pending-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(409);
});

test('rejecting a payment for an already-confirmed booking is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V8', $type->id);
    $customer = makeBookingCustomer('reject-confirmed@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'confirmed');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'reject-confirmed-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/reject', ['reason' => 'x'])
        ->assertStatus(409);
});

test('a customer cannot verify or reject a payment - 403', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('V9', $type->id);
    $customer = makeBookingCustomer('verify-customer@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsCustomer($this, 'verify-customer-token@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/reject', ['reason' => 'x'])
        ->assertStatus(403);
});

test('a guest cannot verify or reject a payment - 401', function () {
    $this->postJson('/api/v1/staff/payments/1/verify')->assertStatus(401);
    $this->postJson('/api/v1/staff/payments/1/reject', ['reason' => 'x'])->assertStatus(401);
});