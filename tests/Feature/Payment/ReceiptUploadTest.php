<?php

use App\Models\Payment;
use Illuminate\Support\Facades\Storage;

test('the first upload moves pending to receipt_uploaded and stores the file privately', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('R1', $type->id);
    $customer = makeBookingCustomer('receipt-upload@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'pending');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $response = $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ]);

    $response->assertStatus(200)->assertJsonPath('data.status', 'receipt_uploaded');

    $payment->refresh();
    expect($payment->receipt_path)->not->toBeNull();
    expect(Storage::disk('receipts')->assertExists($payment->receipt_path));
    // Deterministic private storage path: {booking_number}/{uuid}.pdf - no
    // client filename, no public URL.
    expect(str_starts_with($payment->receipt_path, $booking->booking_number . '/'))->toBeTrue();
    expect(str_ends_with($payment->receipt_path, '.pdf'))->toBeTrue();
});

test('a rejected payment can be re-submitted with a corrected receipt - 409-free', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('R2', $type->id);
    $customer = makeBookingCustomer('receipt-resubmit@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $oldPath = $booking->booking_number . '/old-receipt.pdf';
    Storage::disk('receipts')->put($oldPath, '%PDF-1.4 old');
    $payment = makePaymentFor($booking, 'rejected', 'bank_transfer', $oldPath, 'Unclear image');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.png'),
    ])->assertStatus(200)->assertJsonPath('data.status', 'receipt_uploaded');

    $payment->refresh();
    expect(str_ends_with($payment->receipt_path, '.png'))->toBeTrue();
    Storage::disk('receipts')->assertMissing($oldPath);
    Storage::disk('receipts')->assertExists($payment->receipt_path);
});

test('re-uploading on an already receipt_uploaded payment replaces the old file', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('R3', $type->id);
    $customer = makeBookingCustomer('receipt-replace@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $oldPath = $booking->booking_number . '/first-receipt.pdf';
    Storage::disk('receipts')->put($oldPath, '%PDF-1.4 first');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', $oldPath);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ])->assertStatus(200)->assertJsonPath('data.status', 'receipt_uploaded');

    $payment->refresh();
    expect($payment->receipt_path)->not->toBe($oldPath);
    Storage::disk('receipts')->assertMissing($oldPath);
    Storage::disk('receipts')->assertExists($payment->receipt_path);
});

test('a non-approved file type is rejected with 422', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R4', $type->id);
    $customer = makeBookingCustomer('receipt-badmime@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => \Illuminate\Http\UploadedFile::fake()->create('receipt.txt', 10, 'text/plain'),
    ])->assertStatus(422);
});

test('an oversized receipt is rejected with 422', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R5', $type->id);
    $customer = makeBookingCustomer('receipt-oversize@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $overflow = str_repeat('0', (config('payment.receipt_max_kb') * 1024) + 1);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf', $overflow),
    ])->assertStatus(422);
});

test('a request with no file at all is rejected with 422', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R6', $type->id);
    $customer = makeBookingCustomer('receipt-missing@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->postJson('/api/v1/customer/payments/' . $payment->id . '/receipt', [])
        ->assertStatus(422);
});

test('a customer cannot upload a receipt for another customer payment - 404', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('R7', $type->id);
    $owner = makeBookingCustomer('receipt-owner@example.com');
    $booking = makeHoldingBooking($owner, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    $intruder = makeBookingCustomer('receipt-intruder@example.com');
    \Laravel\Sanctum\Sanctum::actingAs($intruder);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ])->assertStatus(404);
});

test('uploading a receipt to a verified payment is rejected with 409', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('R8', $type->id);
    $customer = makeBookingCustomer('receipt-verified@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'verified', 'bank_transfer', 'x/verified.pdf');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ])->assertStatus(409);
});

test('uploading a receipt to a refunded payment is rejected with 409', function () {
    Storage::fake('receipts');
    $type = makeRoomType();
    $room = makeBookingRoom('R9', $type->id);
    $customer = makeBookingCustomer('receipt-refunded@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'refunded', 'bank_transfer', 'x/refunded.pdf');

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ])->assertStatus(409);
});

test('uploading a receipt for a terminal booking is rejected with 409', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R10', $type->id);
    $customer = makeBookingCustomer('receipt-terminal@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'cancelled');
    $payment = makePaymentFor($booking);

    \Laravel\Sanctum\Sanctum::actingAs($customer);

    $this->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ])->assertStatus(409);
});

test('a guest cannot upload a receipt - 401', function () {
    $this->post('/api/v1/customer/payments/1/receipt', [
        'receipt' => fakeReceiptFile('receipt.pdf'),
    ])->assertStatus(401);
});

test('a staff token cannot use the customer receipt upload route - 403', function () {
    $type = makeRoomType();
    $room = makeBookingRoom('R11', $type->id);
    $customer = makeBookingCustomer('receipt-staff-intrusion@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    $token = loginAsEmployee($this, 'staff-receipt@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/customer/payments/' . $payment->id . '/receipt', [
            'receipt' => fakeReceiptFile('receipt.pdf'),
        ])->assertStatus(403);
});