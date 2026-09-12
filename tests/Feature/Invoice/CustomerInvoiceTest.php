<?php

use App\Models\Invoice;
use App\Services\PaymentService;
use Tests\TestCase;

/**
 * STEP 16 - customer invoice endpoints (read-only, own invoices only).
 * Ownership is enforced twice (InvoicePolicy + scoped findOrFail on the
 * payment's booking customer), so a foreign customer always gets 404.
 * Invoices are the immutable historical billing document - still visible
 * after a refund.
 *
 * Seed helper generates invoices via the service directly (no HTTP auth
 * request) so a real customer token stays the first authenticated request
 * in each test - the Sanctum guard caches the first resolved user per test.
 */

function seedOwnedInvoiceDirectly(TestCase $testCase, string $email, string $roomNumber): array
{
    $type = makeRoomType();
    $room = makeBookingRoom($roomNumber, $type->id);
    $customer = makeBookingCustomer($email);
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $verifier = App\Models\Employee::create([
        'name' => 'Verifier', 'email' => 'seed-direct-' . $roomNumber . '@example.com',
        'password' => 'Password123!', 'role' => 'admin',
    ]);
    app(PaymentService::class)->verify($payment, $verifier);

    return [$customer, $booking, $payment->fresh(), Invoice::where('payment_id', $payment->id)->firstOrFail()];
}

function loginAsExistingCustomer(TestCase $testCase, string $email): string
{
    return $testCase->postJson('/api/v1/customer/auth/login', [
        'email' => $email, 'password' => 'Password123!',
    ])->json('data.token');
}

test('a customer lists only their own invoices', function () {
    [$customerA, , , $invoiceA] = seedOwnedInvoiceDirectly($this, 'customer-own-a@example.com', 'CI-A');
    [, , , $invoiceB] = seedOwnedInvoiceDirectly($this, 'customer-own-b@example.com', 'CI-B');

    $token = loginAsExistingCustomer($this, 'customer-own-a@example.com');
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonCount(1, 'data');

    $numbers = collect($response->json('data'))->pluck('invoice_number')->all();
    expect($numbers)->toContain($invoiceA->invoice_number);
    expect($numbers)->not->toContain($invoiceB->invoice_number);
});

test('a customer can show their own invoice and gets 404 for another customers invoice', function () {
    [, , , $invoiceA] = seedOwnedInvoiceDirectly($this, 'customer-show-a@example.com', 'CI-S1');
    [, , , $invoiceB] = seedOwnedInvoiceDirectly($this, 'customer-show-b@example.com', 'CI-S2');

    $token = loginAsExistingCustomer($this, 'customer-show-a@example.com');
    $ownerId = $invoiceA->payment->booking->customer_id;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices/' . $invoiceA->id)
        ->assertStatus(200)
        ->assertJsonPath('data.invoice_number', $invoiceA->invoice_number)
        ->assertJsonPath('data.payment.booking.customer.id', $ownerId)
        ->assertJsonPath('data.items.0.room_number', 'CI-S1');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices/' . $invoiceB->id)
        ->assertStatus(404);
});

test('a customer token cannot read the staff invoice routes - 403', function () {
    [, , , $invoice] = seedOwnedInvoiceDirectly($this, 'customer-403@example.com', 'CI-C');

    $token = loginAsExistingCustomer($this, 'customer-403@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices')
        ->assertStatus(403);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices/' . $invoice->id)
        ->assertStatus(403);
});

test('a staff token cannot read the customer invoice routes - 403', function () {
    [, , , $invoice] = seedOwnedInvoiceDirectly($this, 'staff-cross@example.com', 'CI-Y');

    $token = loginAsEmployee($this, 'staff-cross-token@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices')
        ->assertStatus(403);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices/' . $invoice->id)
        ->assertStatus(403);
});

test('a customer sees their invoice before and after a staff refund', function () {
    [, , $payment, $invoice] = seedOwnedInvoiceDirectly($this, 'customer-refund@example.com', 'CI-R');

    $token = loginAsExistingCustomer($this, 'customer-refund@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.0.payment.status', 'verified');

    app(PaymentService::class)->refund($payment, 'Guest cancelled');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.0.payment.status', 'refunded');
});

test('a customer with only an unpaid booking gets an empty invoice list', function () {
    $customer = makeBookingCustomer('customer-empty@example.com');
    $type = makeRoomType();
    $room = makeBookingRoom('CI-E', $type->id);
    makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');

    $token = loginAsExistingCustomer($this, 'customer-empty@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/invoices')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

test('a guest cannot read customer invoices - 401', function () {
    $this->getJson('/api/v1/customer/invoices')->assertStatus(401);
    $this->getJson('/api/v1/customer/invoices/1')->assertStatus(401);
});