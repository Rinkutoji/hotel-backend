<?php

use App\Models\RoomStatusLog;
use App\Services\BookingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 15 - Late-Cancellation Fees.
 *
 * Approved rules (docs/STEP-15-IMPLEMENTATION-PLAN.md §3-§5/§27 D-1..D-6):
 * - Window: staff cancellation of a 'confirmed' booking within
 *   config('booking.late_cancel_window_hours') (24) of the START of the
 *   check-in day is "late"; the boundary is inclusive (>=).
 * - Fee: late_cancel_fee_nights (1) x SUM(booking_rooms.price_per_night
 *   snapshot) computed in integer cents server-side, decimal:2 on storage.
 * - Refund neither triggers nor clears the fee; customer surface unchanged.
 */

test('a staff cancellation far outside the 24h window records no late fee and releases the room', function () {
    $customer = makeBookingCustomer('fee-early@example.com');
    $type = makeRoomType('FeeEarly');
    $room = makeBookingRoom('LF1', $type->id);
    $booking = makeConfirmedBookingWithRooms(
        $customer,
        Carbon::today()->addDays(2)->format('Y-m-d'),
        Carbon::today()->addDays(4)->format('Y-m-d'),
        [[$room, '100.00']]
    );
    $token = loginAsEmployee($this, 'fee-early-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Guest changed plans'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Guest changed plans')
        ->assertJsonPath('data.late_fee_amount', null)
        ->assertJsonPath('data.late_fee_applied_at', null);

    $booking = $booking->fresh();
    expect($booking->late_fee_amount)->toBeNull();
    expect($booking->late_fee_applied_at)->toBeNull();

    expect($room->fresh()->status)->toBe('available');

    $log = RoomStatusLog::where('room_id', $room->id)->first();
    expect($log->from_status)->toBe('reserved');
    expect($log->to_status)->toBe('available');
    expect($log->reason)->toBe('Booking cancelled');
    expect($log->changed_by)->toBeNull();
});

test('a staff cancellation inside the 24h window records a one-night fee from the price snapshot', function () {
    $customer = makeBookingCustomer('fee-late@example.com');
    $type = makeRoomType('FeeLate');
    $room = makeBookingRoom('LF2', $type->id);
    // Snapshot price deliberately differs from the room type's current
    // base_price (100.00) so the test proves the SNAPSHOT is used, never
    // the current room price.
    $booking = makeConfirmedBookingWithRooms(
        $customer,
        Carbon::today()->format('Y-m-d'),
        Carbon::today()->addDay()->format('Y-m-d'),
        [[$room, '88.88']]
    );
    $token = loginAsEmployee($this, 'fee-late-staff@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Late change'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.late_fee_amount', '88.88');

    expect($response->json('data.late_fee_applied_at'))->not->toBeNull();

    $booking = $booking->fresh();
    expect($booking->late_fee_amount)->toBe('88.88');
    expect($booking->late_fee_applied_at)->not->toBeNull();
    expect($room->fresh()->status)->toBe('available');
});

test('a cancellation at exactly the 24h cutoff is late', function () {
    $this->travelTo(Carbon::create(2026, 9, 9, 0, 0, 0));
    try {
        $customer = makeBookingCustomer('fee-cutoff@example.com');
        $type = makeRoomType('FeeCutoff');
        $room = makeBookingRoom('LF3', $type->id);
        // check-in 2026-09-10 -> cutoff = 2026-09-09 00:00 == frozen now().
        $booking = makeConfirmedBookingWithRooms($customer, '2026-09-10', '2026-09-12', [[$room, '100.00']]);
        $token = loginAsEmployee($this, 'fee-cutoff-staff@example.com', 'admin');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
            ->assertStatus(200);

        expect($booking->fresh()->late_fee_amount)->toBe('100.00');
    } finally {
        $this->travelBack();
    }
});

test('a cancellation one second before the 24h cutoff is not late', function () {
    $this->travelTo(Carbon::create(2026, 9, 8, 23, 59, 59));
    try {
        $customer = makeBookingCustomer('fee-before-cutoff@example.com');
        $type = makeRoomType('FeeBeforeCutoff');
        $room = makeBookingRoom('LF4', $type->id);
        // check-in 2026-09-10 -> cutoff = 2026-09-09 00:00 > frozen now().
        $booking = makeConfirmedBookingWithRooms($customer, '2026-09-10', '2026-09-12', [[$room, '100.00']]);
        $token = loginAsEmployee($this, 'fee-before-staff@example.com', 'admin');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
            ->assertStatus(200)
            ->assertJsonPath('data.late_fee_amount', null);

        expect($booking->fresh()->late_fee_amount)->toBeNull();
    } finally {
        $this->travelBack();
    }
});

test('the fee sums one night across multiple rooms at their snapshot prices', function () {
    $customer = makeBookingCustomer('fee-multi@example.com');
    $type = makeRoomType('FeeMulti');
    $roomA = makeBookingRoom('LF5a', $type->id);
    $roomB = makeBookingRoom('LF5b', $type->id);
    $booking = makeConfirmedBookingWithRooms(
        $customer,
        Carbon::today()->format('Y-m-d'),
        Carbon::today()->addDay()->format('Y-m-d'),
        [[$roomA, '100.00'], [$roomB, '150.00']]
    );
    $token = loginAsEmployee($this, 'fee-multi-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', '250.00');

    expect($booking->fresh()->late_fee_amount)->toBe('250.00');
    expect($roomA->fresh()->status)->toBe('available');
    expect($roomB->fresh()->status)->toBe('available');
});

test('the fee accumulates fractional snapshot prices in integer cents', function () {
    $customer = makeBookingCustomer('fee-cents@example.com');
    $type = makeRoomType('FeeCents');
    $roomA = makeBookingRoom('LF6a', $type->id);
    $roomB = makeBookingRoom('LF6b', $type->id);
    $booking = makeConfirmedBookingWithRooms(
        $customer,
        Carbon::today()->format('Y-m-d'),
        Carbon::today()->addDay()->format('Y-m-d'),
        [[$roomA, '99.99'], [$roomB, '0.01']]
    );
    $token = loginAsEmployee($this, 'fee-cents-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', '100.00');

    expect($booking->fresh()->late_fee_amount)->toBe('100.00');
});

test('a pending_payment staff cancellation inside the window records no late fee', function () {
    $customer = makeBookingCustomer('fee-pending@example.com');
    $type = makeRoomType('FeePending');
    $room = makeBookingRoom('LF7', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');
    $token = loginAsEmployee($this, 'fee-pending-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Overbooked'])
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', null);

    expect($booking->fresh()->late_fee_amount)->toBeNull();
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($room->fresh()->status)->toBe('available');
});

test('a customer self-cancellation of a pending_payment booking records no late fee', function () {
    $customer = makeBookingCustomer('fee-cust-pending@example.com');
    $type = makeRoomType('FeeCustPending');
    $room = makeBookingRoom('LF8', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    \Laravel\Sanctum\Sanctum::actingAs($customer);
    $this->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.late_fee_amount', null);

    expect($booking->fresh()->late_fee_amount)->toBeNull();
});

test('a customer cannot self-cancel a confirmed booking so can never trigger a fee', function () {
    $customer = makeBookingCustomer('fee-cust-confirmed@example.com');
    $type = makeRoomType('FeeCustConfirmed');
    $room = makeBookingRoom('LF9', $type->id);
    $booking = makeConfirmedBookingWithRooms(
        $customer,
        Carbon::today()->format('Y-m-d'),
        Carbon::today()->addDay()->format('Y-m-d'),
        [[$room, '100.00']]
    );

    \Laravel\Sanctum\Sanctum::actingAs($customer);
    $this->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
        ->assertStatus(409);

    expect($booking->fresh()->status)->toBe('confirmed');
    expect($booking->fresh()->late_fee_amount)->toBeNull();
    expect($room->fresh()->status)->toBe('reserved');
});

test('the hold-expiry sweep cancellation records no late fee', function () {
    $customer = makeBookingCustomer('fee-hold@example.com');
    $type = makeRoomType('FeeHold');
    $room = makeBookingRoom('LF10', $type->id);
    $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment', true);

    $this->artisan('bookings:release-expired-holds');

    $booking = $booking->fresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancellation_reason)->toBe('Payment deadline passed');
    expect($booking->late_fee_amount)->toBeNull();
    expect($booking->late_fee_applied_at)->toBeNull();
});

test('staff cancellation of terminal statuses is rejected with 409 and no fee', function () {
    foreach (['checked_in', 'checked_out', 'no_show', 'cancelled'] as $status) {
        $customer = makeBookingCustomer("fee-terminal-{$status}@example.com");
        $type = makeRoomType('FeeTerminal' . ucfirst($status));
        $room = makeBookingRoom('LFT' . $status, $type->id);
        $booking = makeHoldingBooking($customer, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), $status);
        $token = loginAsEmployee($this, "fee-terminal-{$status}-staff@example.com", 'admin');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
            ->assertStatus(409);

        expect($booking->fresh()->status)->toBe($status);
        expect($booking->fresh()->late_fee_amount)->toBeNull();
    }
});

test('a client cannot manipulate the late fee fields on a cancel request', function () {
    $customer = makeBookingCustomer('fee-inject@example.com');
    $type = makeRoomType('FeeInject');
    $room = makeBookingRoom('LF11', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
    $token = loginAsEmployee($this, 'fee-inject-staff@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", [
            'reason' => 'x',
            'late_fee_amount' => '0.01',
            'late_fee_applied_at' => '2020-01-01 00:00:00',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', '100.00');

    // The server-computed timestamp is used, never the client-supplied one.
    expect($response->json('data.late_fee_applied_at'))->not->toBe('2020-01-01 00:00:00');
    expect($booking->fresh()->late_fee_amount)->toBe('100.00');
});

test('the staff cancellation reason defaults to Cancelled by staff when omitted', function () {
    $customer = makeBookingCustomer('fee-default-reason@example.com');
    $type = makeRoomType('FeeDefaultReason');
    $room = makeBookingRoom('LF12', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
    $token = loginAsEmployee($this, 'fee-default-reason-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", [])
        ->assertStatus(200)
        ->assertJsonPath('data.cancellation_reason', 'Cancelled by staff');
});

test('the recorded fee is visible in the staff show and the customer own show', function () {
    $customer = makeBookingCustomer('fee-visible@example.com');
    $type = makeRoomType('FeeVisible');
    $room = makeBookingRoom('LF13', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);

    $staffToken = loginAsEmployee($this, 'fee-visible-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$staffToken}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$staffToken}")
        ->getJson("/api/v1/staff/bookings/{$booking->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', '100.00');

    $this->flushHeaders();
    \Laravel\Sanctum\Sanctum::actingAs($customer);
    $this->getJson("/api/v1/customer/bookings/{$booking->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.late_fee_amount', '100.00');
});

test('admin, manager and receptionist can all record a late cancellation fee', function () {
    foreach (['admin', 'manager', 'receptionist'] as $role) {
        $customer = makeBookingCustomer("fee-role-{$role}@example.com");
        $type = makeRoomType('FeeRole' . ucfirst($role));
        $room = makeBookingRoom("LFR-{$role}", $type->id);
        $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
        $token = loginAsEmployee($this, "fee-role-{$role}-staff@example.com", $role);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
            ->assertStatus(200)
            ->assertJsonPath('data.late_fee_amount', '100.00');
    }
});

test('a customer cannot use the staff cancel route - 403', function () {
    $customer = makeBookingCustomer('fee-403@example.com');
    $type = makeRoomType('Fee403');
    $room = makeBookingRoom('LF14', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);

    $customerToken = loginAsCustomer($this, 'fee-403-customer@example.com');
    $this->withHeader('Authorization', "Bearer {$customerToken}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(403);

    expect($booking->fresh()->status)->toBe('confirmed');
    expect($booking->fresh()->late_fee_amount)->toBeNull();
});

test('a guest cannot use the staff cancel route - 401', function () {
    $customer = makeBookingCustomer('fee-401@example.com');
    $type = makeRoomType('Fee401');
    $room = makeBookingRoom('LF14g', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);

    $this->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'x'])
        ->assertStatus(401);

    expect($booking->fresh()->status)->toBe('confirmed');
    expect($booking->fresh()->late_fee_amount)->toBeNull();
});

test('a customer cannot cancel another customer booking - 404', function () {
    $owner = makeBookingCustomer('fee-owner@example.com');
    $type = makeRoomType('Fee404');
    $room = makeBookingRoom('LF15', $type->id);
    $booking = makeHoldingBooking($owner, $room, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), 'pending_payment');

    $intruderToken = loginAsCustomer($this, 'fee-404-intruder@example.com');
    $this->withHeader('Authorization', "Bearer {$intruderToken}")
        ->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")
        ->assertStatus(404);

    expect($booking->fresh()->status)->toBe('pending_payment');
    expect($booking->fresh()->late_fee_amount)->toBeNull();
});

test('a cancellation and its late fee roll back together when a write fails', function () {
    $customer = makeBookingCustomer('fee-rollback@example.com');
    $type = makeRoomType('FeeRollback');
    $room = makeBookingRoom('LF16', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);

    // Simulate a mid-transaction failure on the room audit write: the whole
    // DB::transaction in cancelByStaff must roll back - the booking must stay
    // 'confirmed', the room 'reserved', and the fee must never be written.
    try {
        RoomStatusLog::creating(function () {
            throw new RuntimeException('simulated audit write failure');
        });

        $service = app(BookingService::class);
        expect(fn () => $service->cancelByStaff($booking, 'Guest requested'))->toThrow(RuntimeException::class);
    } finally {
        RoomStatusLog::flushEventListeners();
    }

    $booking = $booking->fresh();
    expect($booking->status)->toBe('confirmed');
    expect($booking->cancelled_at)->toBeNull();
    expect($booking->cancellation_reason)->toBeNull();
    expect($booking->late_fee_amount)->toBeNull();
    expect($booking->late_fee_applied_at)->toBeNull();
    expect($room->fresh()->status)->toBe('reserved');
});

test('a repeated cancellation is rejected - exactly one fee record can ever exist', function () {
    $customer = makeBookingCustomer('fee-double@example.com');
    $type = makeRoomType('FeeDouble');
    $room = makeBookingRoom('LF17', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
    $token = loginAsEmployee($this, 'fee-double-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'First'])
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', '100.00');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Second'])
        ->assertStatus(409);

    $booking = $booking->fresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->late_fee_amount)->toBe('100.00');
    expect($room->fresh()->status)->toBe('available');
    expect(RoomStatusLog::where('room_id', $room->id)->where('to_status', 'available')->count())->toBe(1);
});

test('a refund of a verified payment does not trigger a late fee', function () {
    $type = makeRoomType('FeeRefund');
    $room = makeBookingRoom('LF18', $type->id);
    $customer = makeBookingCustomer('fee-refund@example.com');
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
    $verifier = \App\Models\Employee::create([
        'name' => 'Verifier', 'email' => 'fee-refund-verifier@example.com',
        'password' => 'Password123!', 'role' => 'manager',
    ]);
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'refund/receipt.pdf');
    $payment->verified_at = now();
    $payment->verified_by = $verifier->id;
    $payment->save();

    $token = loginAsEmployee($this, 'fee-refund-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'Guest cancelled on arrival'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');

    expect($payment->fresh()->status)->toBe('refunded');
    $booking = $booking->fresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->late_fee_amount)->toBeNull();
    expect($booking->late_fee_applied_at)->toBeNull();
    expect($room->fresh()->status)->toBe('available');
});

test('a refund attempt after a staff-cancelled fee does not clear the fee - 409', function () {
    $type = makeRoomType('FeeRefundAfter');
    $room = makeBookingRoom('LF19', $type->id);
    $customer = makeBookingCustomer('fee-refund-after@example.com');
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
    $verifier = \App\Models\Employee::create([
        'name' => 'Verifier', 'email' => 'fee-refund-after-verifier@example.com',
        'password' => 'Password123!', 'role' => 'manager',
    ]);
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'refund/receipt.pdf');
    $payment->verified_at = now();
    $payment->verified_by = $verifier->id;
    $payment->save();

    $token = loginAsEmployee($this, 'fee-refund-after-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/cancel", ['reason' => 'Guest requested'])
        ->assertStatus(200)
        ->assertJsonPath('data.late_fee_amount', '100.00');

    // The refund engine rejects a cancelled booking (unchanged), so it can
    // never clear a recorded fee - the two paths don't mix.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(409);

    $booking = $booking->fresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->late_fee_amount)->toBe('100.00');
    expect($payment->fresh()->status)->toBe('verified');
});

test('the no-show sweep never applies a late fee', function () {
    $customer = makeBookingCustomer('fee-no-show@example.com');
    $type = makeRoomType('FeeNoShow');
    $room = makeBookingRoom('LF20', $type->id);
    $booking = makeConfirmedBookingWithRooms(
        $customer,
        Carbon::today()->subDay()->format('Y-m-d'),
        Carbon::today()->addDay()->format('Y-m-d'),
        [[$room, '100.00']]
    );

    $this->artisan('bookings:mark-no-show')
        ->expectsOutputToContain('Marked 1')
        ->assertExitCode(0);

    $booking = $booking->fresh();
    expect($booking->status)->toBe('no_show');
    expect($booking->no_show_at)->not->toBeNull();
    expect($booking->late_fee_amount)->toBeNull();
    expect($booking->late_fee_applied_at)->toBeNull();
    expect($room->fresh()->status)->toBe('available');
});

test('the bookings table has the two additive late-fee columns', function () {
    expect(Schema::hasColumn('bookings', 'late_fee_amount'))->toBeTrue();
    expect(Schema::hasColumn('bookings', 'late_fee_applied_at'))->toBeTrue();
});