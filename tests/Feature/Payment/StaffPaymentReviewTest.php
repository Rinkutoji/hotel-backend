<?php

use App\Models\Payment;

function staffPaymentFixtures(string $suffix): array
{
    $typeA = makeRoomType('Review A');
    $typeB = makeRoomType('Review B');
    $customerA = makeBookingCustomer("review-a{$suffix}@example.com");
    $customerB = makeBookingCustomer("review-b{$suffix}@example.com");
    $bookingA = makeHoldingBooking($customerA, makeBookingRoom("SA{$suffix}", $typeA->id), '2026-09-10', '2026-09-12', 'pending_payment');
    $bookingB = makeHoldingBooking($customerB, makeBookingRoom("SB{$suffix}", $typeB->id), '2026-09-10', '2026-09-12', 'pending_payment');

    return [
        'paymentA' => makePaymentFor($bookingA, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf'),
        'paymentB' => makePaymentFor($bookingB, 'pending', 'cash'),
        'customerA' => $customerA,
        'bookingA' => $bookingA,
    ];
}

test('any staff can list all payments with pagination meta', function () {
    $fixtures = staffPaymentFixtures('list');
    $token = loginAsEmployee($this, 'staff-paylist@example.com', 'receptionist');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 15);
});

test('the staff payment list filters by status', function () {
    $fixtures = staffPaymentFixtures('filter');
    $token = loginAsEmployee($this, 'staff-payfilter@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments?status=pending')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'pending');
});

test('the staff payment list searches by booking number', function () {
    $fixtures = staffPaymentFixtures('searchnum');
    $token = loginAsEmployee($this, 'staff-paysearch-num@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments?search=' . $fixtures['bookingA']->booking_number)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fixtures['paymentA']->id);
});

test('the staff payment list searches by customer name or email', function () {
    $fixtures = staffPaymentFixtures('searchemail');
    $token = loginAsEmployee($this, 'staff-paysearch-email@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments?search=' . $fixtures['customerA']->email)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fixtures['paymentA']->id);
});

test('the staff payment list rejects an invalid status and an over-large per_page with 422', function () {
    $token = loginAsEmployee($this, 'staff-payfilter-invalid@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments?status=bogus')
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments?per_page=51')
        ->assertStatus(422);
});

test('staff can view a payment with its booking, customer and verifier loaded', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('SV1', $type->id);
    $customer = makeBookingCustomer('staff-show@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    Storage::disk('receipts')->put('a/verified.pdf', '%PDF-1.4');
    $path = 'a/verified.pdf';
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', $path);
    $employee = \App\Models\Employee::create([
        'name' => 'Verifier', 'email' => 'verifier@example.com', 'password' => 'Password123!', 'role' => 'admin',
    ]);
    $payment->status = 'verified';
    $payment->verified_at = now();
    $payment->verified_by = $employee->id;
    $payment->save();

    $token = loginAsEmployee($this, 'staff-showviewer@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments/' . $payment->id)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.booking.id', $booking->id)
        ->assertJsonPath('data.booking.customer.id', $customer->id)
        ->assertJsonPath('data.verified_by.id', $employee->id);
});

test('staff rejection requires a reason and records it in notes', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('SR1', $type->id);
    $customer = makeBookingCustomer('staff-reject@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $path = $booking->booking_number . '/receipt.pdf';
    Storage::disk('receipts')->put($path, fakeReceiptFile('receipt.pdf')->get());
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', $path);

    $token = loginAsEmployee($this, 'staff-rejecter@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/reject', [])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/reject', ['reason' => 'Unreadable scan'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.notes', 'Unreadable scan');

    $payment->refresh();
    expect($payment->status)->toBe('rejected');
    expect($payment->verified_at)->toBeNull();
    // The rejected receipt file is kept for audit, not deleted.
    Storage::disk('receipts')->assertExists($path);
    // The booking stays pending until a corrected receipt is re-uploaded.
    expect($booking->fresh()->status)->toBe('pending_payment');
});

test('rejecting a non-receipted payment is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('SR2', $type->id);
    $customer = makeBookingCustomer('staff-reject-pending@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'pending');

    $token = loginAsEmployee($this, 'staff-reject-pending@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/reject', ['reason' => 'No receipt'])
        ->assertStatus(409);
});

test('a customer can view the payment list but only their own payments', function () {
    $fixtures = staffPaymentFixtures('custview');
    \Laravel\Sanctum\Sanctum::actingAs($fixtures['customerA']);

    $response = $this->getJson('/api/v1/customer/payments');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fixtures['paymentA']->id);
});

test('a customer cannot use staff payment routes - 403', function () {
    $token = loginAsCustomer($this, 'cust-staffpay@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/payments')
        ->assertStatus(403);
});

test('a guest cannot use staff payment routes - 401', function () {
    $this->getJson('/api/v1/staff/payments')->assertStatus(401);
});