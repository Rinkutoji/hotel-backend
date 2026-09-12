<?php

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * STEP 16 - staff invoice endpoints (read-only). Any staff role reads any
 * invoice; the list is paginated and searchable by booking number / customer
 * name / email, mirroring the staff payments index. Invoices can never be
 * written from any surface.
 */

function seedVerifiedInvoice(TestCase $testCase, string $email, string $roomNumber): array
{
    $type = makeRoomType();
    $room = makeBookingRoom($roomNumber, $type->id);
    $customer = makeBookingCustomer($email);
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($testCase, 'seed-staff-' . $roomNumber . '@example.com', 'admin');
    $testCase->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200);

    return [$customer, $booking, $payment, Invoice::where('payment_id', $payment->id)->firstOrFail()];
}

test('staff index lists all invoices paginated, newest issued first', function () {
    $this->travelTo(Carbon::create(2026, 9, 9, 9, 0, 0));
    try {
        seedVerifiedInvoice($this, 'staff-index-a@example.com', 'SI-A');
        $this->travelTo(Carbon::create(2026, 9, 9, 10, 0, 0));
        [, , , $invoiceB] = seedVerifiedInvoice($this, 'staff-index-b@example.com', 'SI-B');
    } finally {
        $this->travelBack();
    }

    $token = loginAsEmployee($this, 'staff-orders@example.com', 'admin');
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonCount(2, 'data');

    $numbers = collect($response->json('data'))->pluck('invoice_number')->all();
    expect($numbers)->toContain($invoiceB->invoice_number);
    expect($response->json('data.0.invoice_number'))->toBe($invoiceB->invoice_number);
});

test('staff search finds invoices by booking number, customer name and customer email', function () {
    [$customer, $booking, , $invoice] = seedVerifiedInvoice($this, 'staff-search@example.com', 'SI-S');
    $token = loginAsEmployee($this, 'staff-search-token@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices?search=' . substr($booking->booking_number, 3))
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.invoice_number', $invoice->invoice_number);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices?search=' . urlencode($customer->name))
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices?search=' . urlencode($customer->email))
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1);
});

test('admin, manager and receptionist can each view any invoice', function () {
    [, , , $invoice] = seedVerifiedInvoice($this, 'staff-anyrole@example.com', 'SI-R');

    foreach (['admin', 'manager', 'receptionist'] as $role) {
        $token = loginAsEmployee($this, "staff-anyrole-{$role}@example.com", $role);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/staff/invoices/' . $invoice->id)
            ->assertStatus(200)
            ->assertJsonPath('data.invoice_number', $invoice->invoice_number);
    }
});

test('the invoice filter rejects an unknown status query as shape-invalid - 422', function () {
    $token = loginAsEmployee($this, 'staff-422@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices?status=verified')
        ->assertStatus(422);
});

test('a guest and a customer cannot read the staff invoice list', function () {
    $this->getJson('/api/v1/staff/invoices')->assertStatus(401);

    $token = loginAsCustomer($this, 'staff-cust-403@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices')
        ->assertStatus(403);
});

test('an invoice response embeds payment, booking, customer and the line items', function () {
    [$customer, $booking, $payment, $invoice] = seedVerifiedInvoice($this, 'staff-shape@example.com', 'SI-P');

    $token = loginAsEmployee($this, 'staff-shape-token@example.com', 'admin');
    $data = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices/' . $invoice->id)
        ->assertStatus(200)
        ->json('data');

    expect($data['invoice_number'])->toMatch('/^INV-[A-Z0-9]{8}$/');
    expect($data['total_amount'])->toBe('200.00');
    expect($data['issued_at'])->not->toBeNull();

    expect($data['payment']['id'])->toBe($payment->id);
    expect($data['payment']['amount'])->toBe('200.00');
    expect($data['payment']['status'])->toBe('verified');

    expect($data['payment']['booking']['id'])->toBe($booking->id);
    expect($data['payment']['booking']['booking_number'])->toBe($booking->booking_number);
    expect($data['payment']['booking']['status'])->toBe('confirmed');

    expect($data['payment']['booking']['customer']['id'])->toBe($customer->id);
    expect($data['payment']['booking']['customer']['name'])->toBe($customer->name);
    expect($data['payment']['booking']['customer']['email'])->toBe($customer->email);

    expect($data['items'])->toHaveCount(1);
    expect($data['items'][0]['room_number'])->toBe('SI-P');
    expect($data['items'][0]['line_total'])->toBe('200.00');
});

test('an invoice stays visible to staff after its payment is refunded', function () {
    [, , $payment, $invoice] = seedVerifiedInvoice($this, 'staff-refund@example.com', 'SI-F');
    $token = loginAsEmployee($this, 'staff-refund-token@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'x'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices/' . $invoice->id)
        ->assertStatus(200)
        ->assertJsonPath('data.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.payment.status', 'refunded');
});

test('staff index honours per_page up to 50 and rejects 51 with 422', function () {
    seedVerifiedInvoice($this, 'staff-pp-1@example.com', 'SI-P1');
    seedVerifiedInvoice($this, 'staff-pp-2@example.com', 'SI-P2');
    $token = loginAsEmployee($this, 'staff-pp-token@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices?per_page=1')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(1, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/invoices?per_page=51')
        ->assertStatus(422);
});