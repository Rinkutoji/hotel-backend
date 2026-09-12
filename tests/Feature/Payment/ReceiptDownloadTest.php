<?php

use Illuminate\Support\Facades\Storage;

test('a customer can download their own receipt', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('D1', $type->id);
    $customer = makeBookingCustomer('receipt-dl@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $path = $booking->booking_number . '/stored.pdf';
    Storage::disk('receipts')->put($path, fakeReceiptFile('receipt.pdf')->get());
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', $path);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $response = $this->get('/api/v1/customer/payments/' . $payment->id . '/receipt');

    $response->assertStatus(200);
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('a customer cannot download another customer receipt - 404', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('D2', $type->id);
    $owner = makeBookingCustomer('receipt-dl-owner@example.com');
    $booking = makeHoldingBooking($owner, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $path = $booking->booking_number . '/stored.pdf';
    Storage::disk('receipts')->put($path, fakeReceiptFile('receipt.pdf')->get());
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', $path);

    $intruder = makeBookingCustomer('receipt-dl-intruder@example.com');
    \Laravel\Sanctum\Sanctum::actingAs($intruder);

    $this->get('/api/v1/customer/payments/' . $payment->id . '/receipt')
        ->assertStatus(404);
});

test('any staff can download any receipt', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('D3', $type->id);
    $customer = makeBookingCustomer('receipt-dl-staff@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $path = $booking->booking_number . '/stored.pdf';
    Storage::disk('receipts')->put($path, fakeReceiptFile('receipt.pdf')->get());
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', $path);

    $token = loginAsEmployee($this, 'staff-dl@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->get('/api/v1/staff/payments/' . $payment->id . '/receipt')
        ->assertStatus(200);
});

test('a missing physical receipt file returns 404', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('D4', $type->id);
    $customer = makeBookingCustomer('receipt-dl-missing@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'gone/never-stored.pdf');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->get('/api/v1/customer/payments/' . $payment->id . '/receipt')
        ->assertStatus(404);
});

test('a payment with no receipt_path returns 404 on download', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('D5', $type->id);
    $customer = makeBookingCustomer('receipt-dl-nopath@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->get('/api/v1/customer/payments/' . $payment->id . '/receipt')
        ->assertStatus(404);
});

test('a guest cannot download a receipt - 401', function () {
    $this->get('/api/v1/customer/payments/1/receipt')->assertStatus(401);
});