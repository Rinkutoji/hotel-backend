<?php

/**
 * STEP 17 - Pricing tiers / discounts: booking-price math and the money
 * invariants it must preserve (D-3 / D-6 approvals).
 *
 * Approved rules (docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-3/D-6):
 * - Server-side integer cents: the tier percentage comes off each night
 *   BEFORE the booking_rooms.price_per_night snapshot, so the snapshot
 *   stores the final charged nightly rate and bookings.total_amount is the
 *   discounted total.
 * - Single discount per booking (no stacking): the tier with the largest
 *   min_nights <= the stay's nights applies.
 * - payments.amount stays = discounted bookings.total_amount; invoice
 *   total_amount stays = payment.amount; the STEP 15 late-fee formula keeps
 *   reading the (now discounted) snapshot - zero engine changes.
 * - Historical isolation: editing/deleting a definition never mutates an
 *   existing booking snapshot.
 * - Money/discount fields are never accepted from request bodies.
 *
 * Testing note: a single Pest test runs all its requests through ONE shared
 * application container and Sanctum guard, so a bearer-authenticated
 * customer request followed by a bearer-authenticated staff request in the
 * SAME test leaks the resolved actor. The repo's existing suite avoids that
 * (each test keeps one actor surface); where a STEP 17 invariant needs both
 * surfaces, this file switches actors with Sanctum::actingAs() right before
 * each request (the guard's setUser override), never mixed bearer tokens.
 */

use App\Models\BookingRoom;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

function makeStep17Admin(string $email): App\Models\Employee
{
    return App\Models\Employee::create([
        'name' => 'Step17 Admin', 'email' => $email,
        'password' => 'Password123!', 'role' => 'admin',
    ]);
}

test('a qualifying stay applies the tier and snapshots the discounted nightly rate', function () {
    $type = makeRoomType('Tiered');
    $room = makeBookingRoom('PD1', $type->id);
    makeDiscount('Two nights', 2, 10);
    $token = loginAsCustomer($this, 'disc-booking@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    // 2 nights x (100.00 - 10%) = 2 x 90.00
    $response->assertJsonPath('data.total_amount', '180.00');
    $response->assertJsonPath('data.discount.name', 'Two nights');
    $response->assertJsonPath('data.discount.min_nights', 2);
    $response->assertJsonPath('data.discount.percent_off', '10.00');
    $response->assertJsonPath('data.discount.nights', 2);
    $response->assertJsonPath('data.discount.saved_total', '20.00');
    $response->assertJsonPath('data.discount.total_after_discount', '180.00');

    $bookingRoom = BookingRoom::where('booking_id', $response->json('data.id'))->first();
    expect($bookingRoom->price_per_night)->toBe('90.00');
});

test('the tier with the largest qualifying min_nights applies - no stacking', function () {
    $type = makeRoomType('Stacking');
    $room = makeBookingRoom('PD2', $type->id);
    makeDiscount('Short', 2, 10);
    makeDiscount('Long', 3, 20);
    $token = loginAsCustomer($this, 'disc-stack@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(4)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    // 3 nights x (100.00 - 20%) = 3 x 80.00 - only the Long tier applies.
    $response->assertJsonPath('data.total_amount', '240.00');
    $response->assertJsonPath('data.discount.name', 'Long');
    $response->assertJsonPath('data.discount.percent_off', '20.00');
    $response->assertJsonPath('data.discount.saved_total', '60.00');
    expect(BookingRoom::where('booking_id', $response->json('data.id'))->first()->price_per_night)->toBe('80.00');
});

test('a stay shorter than every tier minimum is charged full price with no discount key', function () {
    $type = makeRoomType('TooShort');
    $room = makeBookingRoom('PD3', $type->id);
    makeDiscount('Two nights', 2, 10);
    $token = loginAsCustomer($this, 'disc-short@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    $response->assertJsonPath('data.total_amount', '100.00');
    expect($response->json('data'))->not->toHaveKey('discount');
});

test('integer-cents discount math stays exact on fractional base prices', function () {
    $type = makeRoomType('HalfCent');
    $type->update(['base_price' => 99.99]);
    $room = makeBookingRoom('PD4', $type->id);
    makeDiscount('Half off', 2, 50);
    $token = loginAsCustomer($this, 'disc-fractional@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201);

    // 9999 cents - round(9999 x 50%) = 5000 off -> 4999 charged/night, x 2.
    $response->assertJsonPath('data.total_amount', '99.98');
    $response->assertJsonPath('data.discount.saved_total', '100.00');
    $response->assertJsonPath('data.discount.total_after_discount', '99.98');
    expect(BookingRoom::where('booking_id', $response->json('data.id'))->first()->price_per_night)->toBe('49.99');
});

test('payments.amount stays locked to the discounted total and the invoice follows', function () {
    Storage::fake('receipts');
    $type = makeRoomType('PayInv');
    $room = makeBookingRoom('PD5', $type->id);
    makeDiscount('Pay tier', 2, 10);
    $customer = makeBookingCustomer();
    $employee = makeStep17Admin('disc-pay-staff@example.com');

    Laravel\Sanctum\Sanctum::actingAs($customer);

    $bookingId = $this->postJson('/api/v1/customer/bookings', [
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.total_amount', '180.00')
        ->json('data.id');

    $paymentId = $this->postJson('/api/v1/customer/payments', [
        'booking_id' => $bookingId,
        'method' => 'bank_transfer',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.amount', '180.00')
        ->json('data.id');

    $this->post("/api/v1/customer/payments/{$paymentId}/receipt", [
        'receipt' => fakeReceiptFile('pay.pdf'),
    ])->assertStatus(200);

    Laravel\Sanctum\Sanctum::actingAs($employee);
    $this->postJson("/api/v1/staff/payments/{$paymentId}/verify")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'verified');

    $payment = Payment::findOrFail($paymentId);
    expect($payment->amount)->toBe('180.00');

    $invoice = Invoice::where('payment_id', $paymentId)->firstOrFail();
    expect($invoice->total_amount)->toBe('180.00');
    $item = $invoice->items->first();
    expect($item->price_per_night)->toBe('90.00');
    expect($item->nights)->toBe(2);
    expect($item->line_total)->toBe('180.00');
});

test('the STEP 15 late-fee formula reads the discounted nightly snapshot', function () {
    $type = makeRoomType('FeeDisc');
    $room = makeBookingRoom('PD6', $type->id);
    makeDiscount('Fee tier', 1, 10);

    // Customer creates a qualifying booking (same discount math), then
    // staff confirms + cancels it to exercise the late-fee path.
    $custToken = loginAsCustomer($this, 'disc-fee@example.com');
    $staff = makeStep17Admin('disc-fee-staff@example.com');

    $bookingId = $this->withHeader('Authorization', "Bearer {$custToken}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.total_amount', '90.00')
        ->json('data.id');

    Laravel\Sanctum\Sanctum::actingAs($staff);
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/confirm")
        ->assertStatus(200);

    // Check-in today => the 24h late window is already open; the fee is one
    // night of the discounted 90.00 snapshot, never the 100.00 base rate.
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/cancel", ['reason' => 'Late change'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.late_fee_amount', '90.00');
});

test('editing or deleting a tier never mutates already-booked snapshots', function () {
    $type = makeRoomType('Isolation');
    $room = makeBookingRoom('PD7', $type->id);
    $discount = makeDiscount('Dyn', 2, 10);
    $customer = makeBookingCustomer();
    $employee = makeStep17Admin('disc-isolation-staff@example.com');

    Laravel\Sanctum\Sanctum::actingAs($customer);
    $bookingId = $this->postJson('/api/v1/customer/bookings', [
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.total_amount', '180.00')
        ->json('data.id');

    // 1. Editing the definition to 50% leaves the parked booking alone.
    Laravel\Sanctum\Sanctum::actingAs($employee);
    $this->putJson("/api/v1/staff/discounts/{$discount->id}", [
        'name' => 'Dyn', 'min_nights' => 2, 'percent_off' => 50,
    ])->assertStatus(200);

    $parked = \App\Models\Booking::findOrFail($bookingId);
    expect($parked->total_amount)->toBe('180.00');
    expect(BookingRoom::where('booking_id', $bookingId)->first()->price_per_night)->toBe('90.00');

    // 2. Deleting the definition leaves it untouched too.
    $this->deleteJson("/api/v1/staff/discounts/{$discount->id}")->assertStatus(200);

    expect($parked->fresh()->total_amount)->toBe('180.00');
    expect(BookingRoom::where('booking_id', $bookingId)->first()->price_per_night)->toBe('90.00');
});

test('a new booking after a tier edit uses the updated value', function () {
    $type = makeRoomType('RePriced');
    $room = makeBookingRoom('PD8', $type->id);
    $discount = makeDiscount('Dyn', 2, 10);
    $customer = makeBookingCustomer();
    $employee = makeStep17Admin('disc-reprice-staff@example.com');

    Laravel\Sanctum\Sanctum::actingAs($customer);
    $first = $this->postJson('/api/v1/customer/bookings', [
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.total_amount', '180.00')
        ->json('data.id');

    Laravel\Sanctum\Sanctum::actingAs($employee);
    $this->putJson("/api/v1/staff/discounts/{$discount->id}", [
        'name' => 'Dyn', 'min_nights' => 2, 'percent_off' => 50,
    ])->assertStatus(200);

    Laravel\Sanctum\Sanctum::actingAs($customer);
    $this->postJson('/api/v1/customer/bookings', [
        'check_in_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(5)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.total_amount', '100.00')
        ->assertJsonPath('data.discount.percent_off', '50.00');

    expect(BookingRoom::where('booking_id', $first)->first()->price_per_night)->toBe('90.00');
});

test('a client cannot influence pricing or the discount at booking time', function () {
    $type = makeRoomType('TrustNobody');
    $room = makeBookingRoom('PD9', $type->id);
    makeDiscount('Real', 2, 10);
    $token = loginAsCustomer($this, 'disc-inject-book@example.com');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
            'price_per_night' => 1,
            'total_amount' => 1,
            'discount_id' => 999,
            'percent_off' => 99,
            'min_nights' => 1,
        ])
        ->assertStatus(201);

    $response->assertJsonPath('data.total_amount', '180.00');
    $response->assertJsonPath('data.discount.name', 'Real');
    expect(BookingRoom::where('booking_id', $response->json('data.id'))->first()->price_per_night)->toBe('90.00');
});