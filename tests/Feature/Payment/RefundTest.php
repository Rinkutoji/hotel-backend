<?php

use App\Models\Employee;
use App\Models\RoomStatusLog;
use Illuminate\Support\Facades\Gate;

/**
 * STEP 11 - Refund Processing. Covers: golden path (confirmed +
 * pending_payment bookings), room release + booking cancellation + audit
 * logging, authorization (admin/manager yes, receptionist/customer no),
 * invalid states (pending/receipt_uploaded/rejected/refunded/terminal
 * bookings), reason validation, double-refund prevention, verification
 * audit preservation, and payment-level policy checks.
 */

// A verified payment on a confirmed booking with a reserved room. Mirrors
// the PaymentVerifyBookingTest setup: the booking is confirmed and the
// room is reserved with a matching booking-confirm audit log row. A real
// Employee is created first so verified_by satisfies the FK.
function makeVerifiedConfirmedPayment(string $suffix): array
{
    $type = makeRoomType();
    $room = makeBookingRoom('R-' . $suffix, $type->id);
    $customer = makeBookingCustomer('refund-' . $suffix . '@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'confirmed');

    $room->status = 'reserved';
    $room->save();
    RoomStatusLog::create([
        'room_id' => $room->id, 'from_status' => 'available', 'to_status' => 'reserved', 'reason' => 'Booking confirmed',
    ]);

    $verifier = Employee::create([
        'name' => 'Verifier', 'email' => 'refund-verifier-' . $suffix . '@example.com',
        'password' => 'Password123!', 'role' => 'manager',
    ]);

    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'refund/receipt.pdf');
    $payment->verified_at = now();
    $payment->verified_by = $verifier->id;
    $payment->save();

    return [$booking, $room, $payment, $customer, $verifier];
}

test('admin refunds a verified payment on a confirmed booking: payment refunded, booking cancelled, room released, audited', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('golden');
    $token = loginAsEmployee($this, 'refund-admin@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'Guest cancelled on arrival'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');

    expect($payment->fresh()->status)->toBe('refunded');
    expect($payment->fresh()->notes)->toBe('Guest cancelled on arrival');
    expect($payment->fresh()->booking)->not->toBeNull();

    expect($booking->fresh()->status)->toBe('cancelled');
    expect($booking->fresh()->cancelled_at)->not->toBeNull();
    expect($booking->fresh()->cancellation_reason)->toBe('Refunded by staff: Guest cancelled on arrival');

    $room->refresh();
    expect($room->status)->toBe('available');

    $releaseLog = RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')->first();
    expect($releaseLog)->not->toBeNull();
    expect($releaseLog->from_status)->toBe('reserved');
    expect($releaseLog->reason)->toBe('Refunded by staff: Guest cancelled on arrival');
});

test('manager can refund a verified payment', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('manager');
    $token = loginAsEmployee($this, 'refund-manager@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'Duplicate booking'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');
});

test('a receptionist cannot refund a payment - 403', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('recep');
    $token = loginAsEmployee($this, 'refund-receptionist@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(403);

    // Nothing changed.
    expect($payment->fresh()->status)->toBe('verified');
    expect($booking->fresh()->status)->toBe('confirmed');
    expect($room->fresh()->status)->toBe('reserved');
});

test('a customer cannot refund - 403', function () {
    [$booking, $room, $payment, $customer] = makeVerifiedConfirmedPayment('cust');
    $token = loginAsCustomer($this, 'refund-customer-token@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(403);
});

test('a guest cannot refund - 401', function () {
    $this->postJson('/api/v1/staff/payments/1/refund', ['reason' => 'x'])->assertStatus(401);
});

test('a non-existent payment cannot be refunded - 404', function () {
    $token = loginAsEmployee($this, 'refund-404@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/9999/refund', ['reason' => 'x'])
        ->assertStatus(404);
});

test('a verified payment on a still-pending booking is refunded and the booking cancelled with no room change', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-pending', $type->id);
    $customer = makeBookingCustomer('refund-pending@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $verifier = Employee::create([
        'name' => 'Verifier', 'email' => 'refund-pending-verifier@example.com',
        'password' => 'Password123!', 'role' => 'manager',
    ]);
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'refund/receipt.pdf');
    $payment->verified_at = now();
    $payment->verified_by = $verifier->id;
    $payment->save();
    $token = loginAsEmployee($this, 'refund-pending-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'Booking cancelled before payment cleared'])
        ->assertStatus(200);

    expect($payment->fresh()->status)->toBe('refunded');
    expect($booking->fresh()->status)->toBe('cancelled');
    // A pending_payment booking never reserved any room - keep it available.
    expect($room->fresh()->status)->toBe('available');
});

test('refund requires a reason - missing, empty and too-long all 422', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('reason');
    $token = loginAsEmployee($this, 'refund-reason@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', [])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => ''])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => str_repeat('a', 256)])
        ->assertStatus(422);

    expect($payment->fresh()->status)->toBe('verified');
});

test('a pending (un-receipted) payment cannot be refunded - 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-pend-pay', $type->id);
    $customer = makeBookingCustomer('refund-pendpay@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'pending');
    $token = loginAsEmployee($this, 'refund-pendpay-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);
});

test('a receipt-uploaded (un-verified) payment cannot be refunded - 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-receipted', $type->id);
    $customer = makeBookingCustomer('refund-receipted@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'confirmed');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'refund-receipted-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);
});

test('a rejected payment cannot be refunded - 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-rejected', $type->id);
    $customer = makeBookingCustomer('refund-rejected@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'rejected', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'refund-rejected-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);
});

test('an already-refunded payment cannot be refunded twice - 409', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('twice');
    $token = loginAsEmployee($this, 'refund-twice@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'First refund'])
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'Second refund'])
        ->assertStatus(409);

    // The booking stays cancelled and the room stays available - no
    // double release, no re-cancellation side effects.
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($room->fresh()->status)->toBe('available');
    expect(RoomStatusLog::where('room_id', $room->id)->where('to_status', 'available')->count())->toBe(1);
});

test('a verified payment on a checked-in booking cannot be refunded - 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-checkedin', $type->id);
    $customer = makeBookingCustomer('refund-checkedin@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'checked_in');
    $room->status = 'occupied';
    $room->save();
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'refund-checkedin-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);

    expect($payment->fresh()->status)->toBe('verified');
    expect($booking->fresh()->status)->toBe('checked_in');
    expect($room->fresh()->status)->toBe('occupied');
});

test('a verified payment on a checked-out booking cannot be refunded - 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-checkedout', $type->id);
    $customer = makeBookingCustomer('refund-checkedout@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'checked_out');
    $room->status = 'cleaning';
    $room->save();
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'refund-checkedout-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);
});

test('a verified payment on an already-cancelled booking cannot be refunded - 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R-already-cancelled', $type->id);
    $customer = makeBookingCustomer('refund-already-cancelled@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'cancelled');
    $room->status = 'available';
    $room->save();
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'refund-already-cancelled-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);
});

test('a refund preserves the original verified_at and verified_by audit trail', function () {
    [$booking, $room, $payment, $customer] = makeVerifiedConfirmedPayment('audit');
    $originalVerifiedAt = $payment->verified_at;
    $originalVerifiedBy = $payment->verified_by;
    $token = loginAsEmployee($this, 'refund-audit@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(200);

    $refreshed = $payment->fresh();
    expect($refreshed->verified_at->eq($originalVerifiedAt))->toBeTrue();
    expect($refreshed->verified_by)->toBe($originalVerifiedBy);
});

test('a refund keeps the receipt file on disk for audit', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('files');
    $originalPath = $payment->receipt_path;
    $token = loginAsEmployee($this, 'refund-files@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(200);

    expect($payment->fresh()->receipt_path)->toBe($originalPath);
});

test('a client-sent status on a refund request is silently ignored', function () {
    [$booking, $room, $payment] = makeVerifiedConfirmedPayment('inject');
    $token = loginAsEmployee($this, 'refund-inject@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x', 'status' => 'pending', 'amount' => 999])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');
});

test('the refund policy allows only admin and manager, mirroring the process-refund gate', function () {
    $policy = new \App\Policies\PaymentPolicy();
    $customer = makeBookingCustomer('refund-policy-cust@example.com');

    foreach (['admin', 'manager'] as $role) {
        $staff = Employee::create([
            'name' => 'R', 'email' => 'refund-policy-' . $role . '@example.com',
            'password' => 'Password123!', 'role' => $role,
        ]);
        expect($policy->refund($staff))->toBeTrue("role {$role} should be allowed to refund");
        // cross-check against the shared gate - single source of truth
        expect(Gate::forUser($staff)->allows('process-refund'))->toBeTrue("role {$role} should pass the gate");
    }

    foreach (['receptionist'] as $role) {
        $staff = Employee::create([
            'name' => 'R', 'email' => 'refund-policy-' . $role . '@example.com',
            'password' => 'Password123!', 'role' => $role,
        ]);
        expect($policy->refund($staff))->toBeFalse("role {$role} should be denied");
        expect(Gate::forUser($staff)->allows('process-refund'))->toBeFalse("role {$role} should be denied by the gate");
    }

    expect($policy->refund($customer))->toBeFalse();
});
