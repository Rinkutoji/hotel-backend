<?php

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Room;
use App\Services\PaymentService;

// Task 41: the T27 probe's permanent replacement - end-to-end proof that
// the staff LIST endpoints (bookings, payments, invoices, calendar)
// hard-scope receptionists to their assigned property and honor the
// admin property_id filter (spec §8.1). T27 wired the controllers with
// disposable probe coverage only; the ledger deferred the permanent
// tests to T41/T42, and T42's plan scope is analytics/reports only, so
// this file owns the staff-list surface. The rooms list is NOT
// duplicated here - its two permanent tests live in
// ReceptionistScopingTest (T21/T28). Fixture pattern follows
// ReceptionistScopingTest: the seeded MAIN chain plus a WEST chain.
// Invoices exist only after payment verification; the fixture calls the
// service directly (bypasses policies - T27 probe pattern, Ruling 23.7).

beforeEach(function () {
    $this->adminToken = loginAsEmployee($this, 'sls-admin@example.com', 'admin');
    $this->token = loginAsEmployee($this, 'sls-recep@example.com', 'receptionist');

    $this->mainRoom = makeBookingRoom('SLS1', makeRoomType('SLS-M')->id);

    $this->westProperty = makeProperty('WEST', 'West Hotel');
    $this->westBuilding = makeBuilding('WEST-B', $this->westProperty->id);
    $this->westRoom = Room::create([
        'room_number' => 'SLS2',
        'room_type_id' => makeRoomType('SLS-W')->id,
        'floor' => 1,
        'building_id' => $this->westBuilding->id,
    ]);

    $this->ownBooking = makeHoldingBooking(makeBookingCustomer('sls-own@example.com'), $this->mainRoom, '2026-10-01', '2026-10-03', 'confirmed');
    $this->westBooking = makeHoldingBooking(makeBookingCustomer('sls-west@example.com'), $this->westRoom, '2026-10-01', '2026-10-03', 'confirmed');

    $admin = Employee::where('email', 'sls-admin@example.com')->first();
    $this->ownPayment = app(PaymentService::class)->verify(
        makePaymentFor($this->ownBooking, 'receipt_uploaded'),
        $admin,
    );
    $this->westPayment = app(PaymentService::class)->verify(
        makePaymentFor($this->westBooking, 'receipt_uploaded'),
        $admin,
    );

    $null = Employee::create([
        'name' => 'No Prop',
        'email' => 'sls-null@example.com',
        'password' => 'Password123!',
        'role' => 'receptionist',
        'status' => 'active',
    ]);
    $this->nullToken = $null->createToken('test')->plainTextToken;
});

test('receptionist bookings list shows only their own property', function () {
    $res = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/bookings?property_id={$this->westProperty->id}")
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toContain($this->ownBooking->id)
        ->not->toContain($this->westBooking->id);
});

test('receptionist payments list shows only their own property', function () {
    $res = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/payments?property_id={$this->westProperty->id}")
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toContain($this->ownPayment->id)
        ->not->toContain($this->westPayment->id);
});

test('receptionist invoices list shows only their own property', function () {
    $ownInvoiceIds = Invoice::whereHas('payment', fn ($q) => $q->where('booking_id', $this->ownBooking->id))
        ->pluck('id')->all();
    $westInvoiceIds = Invoice::whereHas('payment', fn ($q) => $q->where('booking_id', $this->westBooking->id))
        ->pluck('id')->all();

    expect($ownInvoiceIds)->not->toBeEmpty();
    expect($westInvoiceIds)->not->toBeEmpty();

    $res = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/invoices?property_id={$this->westProperty->id}")
        ->assertOk();

    $returned = collect($res->json('data'))->pluck('id')->all();

    foreach ($ownInvoiceIds as $id) {
        expect($returned)->toContain($id);
    }
    foreach ($westInvoiceIds as $id) {
        expect($returned)->not->toContain($id);
    }
});

test('receptionist calendar shows only their own property bookings', function () {
    $res = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/calendar/bookings?from=2026-09-25&to=2026-10-10&property_id={$this->westProperty->id}")
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toContain($this->ownBooking->id)
        ->not->toContain($this->westBooking->id);
});

test('a receptionist with no property assignment fails closed on the lists', function () {
    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson('/api/v1/staff/bookings')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson('/api/v1/staff/payments')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson('/api/v1/staff/invoices')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson('/api/v1/staff/calendar/bookings?from=2026-09-25&to=2026-10-10')
        ->assertForbidden();
});

test('admin property filter narrows the bookings list', function () {
    $westOnly = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/staff/bookings?property_id={$this->westProperty->id}")
        ->assertOk();

    expect(collect($westOnly->json('data'))->pluck('id')->all())
        ->toContain($this->westBooking->id)
        ->not->toContain($this->ownBooking->id);

    $all = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/bookings')
        ->assertOk();

    expect(collect($all->json('data'))->pluck('id')->all())
        ->toContain($this->westBooking->id)
        ->toContain($this->ownBooking->id);
});
