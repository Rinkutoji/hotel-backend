<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 16 - invoice generation. Approved rules (docs/STEP-16-IMPLEMENTATION
 * -PLAN.md §6/§11/§12): an invoice is generated atomically INSIDE
 * PaymentService::verify's transaction; exists iff the payment was verified;
 * one invoice per payment (UNIQUE); server-computed amounts from the locked
 * rows; INV- + 8 uppercase random; immutable (D-3 persists line-item
 * snapshots from booking_rooms.price_per_night x nights).
 */

function makePendingBookingWithRooms(
    Customer $customer,
    string $checkIn,
    string $checkOut,
    array $rooms,
    string $totalAmount = '200.00'
): App\Models\Booking {
    $booking = App\Models\Booking::create([
        'booking_number' => 'BK-TEST' . strtoupper(uniqid()),
        'customer_id' => $customer->id,
        'check_in_date' => $checkIn,
        'check_out_date' => $checkOut,
        'total_amount' => $totalAmount,
        'payment_deadline_at' => now()->addHours(2),
    ]);

    $booking->status = 'pending_payment';
    $booking->save();

    foreach ($rooms as [$room, $pricePerNight]) {
        App\Models\BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'price_per_night' => $pricePerNight,
        ]);
    }

    return $booking;
}

test('verifying a receipted payment atomically issues an invoice with snapped line items', function () {
    $type = makeRoomType('InvoiceGen');
    $room = makeBookingRoom('IV1', $type->id);
    $customer = makeBookingCustomer('invoice-gen@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'invoice-gen-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'verified');

    expect($payment->fresh()->status)->toBe('verified');
    expect($booking->fresh()->status)->toBe('confirmed');
    expect($room->fresh()->status)->toBe('reserved');

    $invoice = Invoice::where('payment_id', $payment->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->invoice_number)->toMatch('/^INV-[A-Z0-9]{8}$/');
    expect($invoice->total_amount)->toBe('200.00');
    expect($invoice->issued_at)->not->toBeNull();

    expect($invoice->items)->toHaveCount(1);
    $item = $invoice->items->first();
    expect($item->room_number)->toBe($room->room_number);
    expect($item->room_type_name)->toBe($type->name);
    expect($item->price_per_night)->toBe('100.00');
    expect($item->nights)->toBe(2);
    expect($item->line_total)->toBe('200.00');
});

test('multi-room fractional snapshots accumulate in integer cents on the invoice', function () {
    $customer = makeBookingCustomer('invoice-cents@example.com');
    $type = makeRoomType('InvoiceCents');
    $roomA = makeBookingRoom('IV2a', $type->id);
    $roomB = makeBookingRoom('IV2b', $type->id);
    $booking = makePendingBookingWithRooms($customer, '2026-09-10', '2026-09-11', [[$roomA, '99.99'], [$roomB, '0.01']], '100.00');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $token = loginAsEmployee($this, 'invoice-cents-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200);

    $invoice = Invoice::where('payment_id', $payment->id)->first();
    expect($invoice->total_amount)->toBe('100.00');
    expect($invoice->items)->toHaveCount(2);

    $lineTotals = $invoice->items->pluck('line_total')->all();
    expect($lineTotals)->toContain('99.99');
    expect($lineTotals)->toContain('0.01');

    $totalCents = $invoice->items->sum(fn ($item) => (int) round(((float) $item->line_total) * 100));
    expect($totalCents)->toBe(10000);
});

test('no invoice exists for pending, receipt_uploaded, or rejected payments until the verify transition', function () {
    $c1 = makeBookingCustomer('inv-pending@example.com');
    $b1 = makeHoldingBooking($c1, makeBookingRoom('IV3a', makeRoomType('InvPending')->id), '2026-09-10', '2026-09-12', 'pending_payment');
    $p1 = makePaymentFor($b1, 'pending');

    $c2 = makeBookingCustomer('inv-uploaded@example.com');
    $b2 = makeHoldingBooking($c2, makeBookingRoom('IV3b', makeRoomType('InvUploaded')->id), '2026-09-10', '2026-09-12', 'pending_payment');
    $p2 = makePaymentFor($b2, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');

    $c3 = makeBookingCustomer('inv-rejected@example.com');
    $b3 = makeHoldingBooking($c3, makeBookingRoom('IV3c', makeRoomType('InvRejected')->id), '2026-09-10', '2026-09-12', 'pending_payment');
    $p3 = makePaymentFor($b3, 'rejected', 'bank_transfer', 'a/receipt.pdf', 'Bad receipt');

    expect(Invoice::count())->toBe(0);

    $token = loginAsEmployee($this, 'inv-none-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $p2->id . '/verify')
        ->assertStatus(200);

    expect(Invoice::where('payment_id', $p1->id)->exists())->toBeFalse();
    expect(Invoice::where('payment_id', $p3->id)->exists())->toBeFalse();
    expect(Invoice::where('payment_id', $p2->id)->exists())->toBeTrue();
});

test('a second verify is rejected with 409 and exactly one invoice can ever exist', function () {
    $type = makeRoomType('InvOnce');
    $room = makeBookingRoom('IV4', $type->id);
    $customer = makeBookingCustomer('invoice-once@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'invoice-once-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(409);

    expect(Invoice::where('payment_id', $payment->id)->count())->toBe(1);
});

test('an invoice and its line items roll back together with the verify transaction', function () {
    $type = makeRoomType('InvRollback');
    $room = makeBookingRoom('IV5', $type->id);
    $customer = makeBookingCustomer('invoice-rollback@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');
    $employee = App\Models\Employee::create([
        'name' => 'Verifier', 'email' => 'inv-rollback-verifier@example.com',
        'password' => 'Password123!', 'role' => 'admin',
    ]);

    // Simulate a mid-transaction failure on the FIRST invoice line-item write:
    // the invoice row has already been inserted, so a successful rollback
    // proves the invoice + items live in the same all-or-nothing unit as the
    // payment verification, booking confirmation and room reservation.
    try {
        InvoiceItem::creating(function () {
            throw new RuntimeException('simulated line-item write failure');
        });

        $service = app(PaymentService::class);
        expect(fn () => $service->verify($payment, $employee))->toThrow(RuntimeException::class);
    } finally {
        InvoiceItem::flushEventListeners();
    }

    expect(Invoice::count())->toBe(0);
    expect(InvoiceItem::count())->toBe(0);
    expect($payment->fresh()->status)->toBe('receipt_uploaded');
    expect($payment->fresh()->verified_at)->toBeNull();
    expect($booking->fresh()->status)->toBe('pending_payment');
    expect($room->fresh()->status)->toBe('available');
});

test('no invoice write route exists and injected invoice fields on verify are ignored', function () {
    $this->postJson('/api/v1/staff/invoices', ['invoice_number' => 'INV-HACKED01', 'total_amount' => '0.01'])
        ->assertStatus(405);
    $this->postJson('/api/v1/customer/invoices', ['invoice_number' => 'INV-HACKED01'])
        ->assertStatus(405);

    $type = makeRoomType('InvInject');
    $room = makeBookingRoom('IV6', $type->id);
    $customer = makeBookingCustomer('invoice-inject@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'invoice-inject-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify', [
            'invoice_number' => 'INV-HACKED01',
            'total_amount' => '0.01',
            'issued_at' => '2000-01-01T00:00:00.000000Z',
        ])
        ->assertStatus(200);

    $invoice = Invoice::where('payment_id', $payment->id)->first();
    expect($invoice->invoice_number)->toMatch('/^INV-[A-Z0-9]{8}$/');
    expect($invoice->total_amount)->toBe('200.00');
});

test('a refund of a verified payment leaves its invoice and totals intact', function () {
    $type = makeRoomType('InvRefund');
    $room = makeBookingRoom('IV7', $type->id);
    $customer = makeBookingCustomer('invoice-refund@example.com');
    $booking = makeHoldingBooking($customer, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking, 'receipt_uploaded', 'bank_transfer', 'a/receipt.pdf');
    $token = loginAsEmployee($this, 'invoice-refund-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/verify')
        ->assertStatus(200);

    $invoiceId = Invoice::where('payment_id', $payment->id)->firstOrFail()->id;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/payments/' . $payment->id . '/refund', ['reason' => 'Guest cancelled'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');

    $invoice = Invoice::findOrFail($invoiceId);
    expect($invoice->total_amount)->toBe('200.00');
    expect($invoice->items)->toHaveCount(1);
    expect(Invoice::where('payment_id', $payment->id)->count())->toBe(1);
    expect($booking->fresh()->status)->toBe('cancelled');
});

test('hold-expiry, no-show, staff cancel and customer self-cancel of unpaid bookings never issue an invoice', function () {
    $c1 = makeBookingCustomer('inv-hold@example.com');
    $b1 = makeHoldingBooking($c1, makeBookingRoom('IV8a', makeRoomType('InvHold')->id), '2026-09-10', '2026-09-12', 'pending_payment', true);
    $this->artisan('bookings:release-expired-holds');

    $c2 = makeBookingCustomer('inv-noshow@example.com');
    $b2 = makeConfirmedBookingWithRooms($c2, Carbon::today()->subDay()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[makeBookingRoom('IV8b', makeRoomType('InvNoShow')->id), '100.00']]);
    $this->artisan('bookings:mark-no-show');

    $c3 = makeBookingCustomer('inv-staffcancel@example.com');
    $b3 = makeConfirmedBookingWithRooms($c3, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[makeBookingRoom('IV8c', makeRoomType('InvStaffCancel')->id), '100.00']]);
    $staffToken = loginAsEmployee($this, 'inv-staffcancel-staff@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$staffToken}")
        ->postJson("/api/v1/staff/bookings/{$b3->id}/cancel", ['reason' => 'x'])
        ->assertStatus(200);

    $c4 = makeBookingCustomer('inv-selfcancel@example.com');
    $b4 = makeHoldingBooking($c4, makeBookingRoom('IV8d', makeRoomType('InvSelfCancel')->id), '2026-09-10', '2026-09-12', 'pending_payment');
    Laravel\Sanctum\Sanctum::actingAs($c4);
    $this->postJson("/api/v1/customer/bookings/{$b4->id}/cancel")->assertStatus(200);

    expect($b1->fresh()->status)->toBe('cancelled');
    expect($b2->fresh()->status)->toBe('no_show');
    expect($b3->fresh()->status)->toBe('cancelled');
    expect($b4->fresh()->status)->toBe('cancelled');
    expect(Invoice::count())->toBe(0);
});

test('a staff-confirmed booking that never pays can check in and out with no invoice anywhere', function () {
    $customer = makeBookingCustomer('inv-unpaid@example.com');
    $type = makeRoomType('InvUnpaid');
    $room = makeBookingRoom('IV9', $type->id);
    $booking = makeConfirmedBookingWithRooms($customer, Carbon::today()->format('Y-m-d'), Carbon::today()->addDay()->format('Y-m-d'), [[$room, '100.00']]);
    $token = loginAsEmployee($this, 'inv-unpaid-staff@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-in")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'checked_in');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/bookings/{$booking->id}/check-out")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'checked_out');

    expect(Invoice::count())->toBe(0);
});

test('the invoices and invoice_items tables are additive with the approved shape', function () {
    expect(Schema::hasTable('invoices'))->toBeTrue();
    expect(Schema::hasTable('invoice_items'))->toBeTrue();

    expect(Schema::hasColumns('invoices', ['invoice_number', 'payment_id', 'total_amount', 'issued_at']))->toBeTrue();
    expect(Schema::hasColumn('invoices', 'deleted_at'))->toBeFalse();

    expect(Schema::hasColumns('invoice_items', ['invoice_id', 'room_number', 'room_type_name', 'price_per_night', 'nights', 'line_total']))->toBeTrue();
    expect(Schema::hasColumn('invoice_items', 'deleted_at'))->toBeFalse();
});

test('invoices enforce UNIQUE(payment_id) and UNIQUE(invoice_number)', function () {
    $customer = makeBookingCustomer('inv-schema@example.com');
    $roomA = makeBookingRoom('IV10a', makeRoomType('InvSchema')->id);
    $roomB = makeBookingRoom('IV10b', makeRoomType('InvSchema')->id);
    $bookingA = makeHoldingBooking($customer, $roomA, '2026-09-10', '2026-09-12', 'pending_payment');
    $bookingB = makeHoldingBooking($customer, $roomB, '2026-09-10', '2026-09-12', 'pending_payment');
    $paymentA = makePaymentFor($bookingA, 'pending');
    $paymentB = makePaymentFor($bookingB, 'pending');

    Invoice::create([
        'invoice_number' => 'INV-SCHEMA-1',
        'payment_id' => $paymentA->id,
        'total_amount' => 200,
        'issued_at' => now(),
    ]);

    $base = ['total_amount' => 200, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()];

    // Second invoice for the same payment -> UNIQUE(payment_id).
    expect(fn () => DB::table('invoices')->insert($base + ['invoice_number' => 'INV-SCHEMA-2', 'payment_id' => $paymentA->id]))
        ->toThrow(QueryException::class);

    // Second invoice with the same number -> UNIQUE(invoice_number).
    expect(fn () => DB::table('invoices')->insert($base + ['invoice_number' => 'INV-SCHEMA-1', 'payment_id' => $paymentB->id]))
        ->toThrow(QueryException::class);
});