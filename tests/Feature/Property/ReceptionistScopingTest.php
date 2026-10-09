<?php

use App\Models\Employee;
use App\Models\Room;

// Task 21: end-to-end HTTP proof of receptionist property scoping
// (spec §8.1 access matrix). Task 20 added the policies; this file proves
// the non-bypassable SERVER-SIDE enforcement over HTTP on the endpoints
// that already exist and already scope by property:
//   GET /api/v1/staff/rooms/{room}
//   GET /api/v1/staff/bookings/{booking} + POST .../confirm
//   GET /api/v1/staff/payments/{payment} + POST .../verify
// (controllers call $this->authorize(...) FIRST, so a cross-property actor
// gets 403 before any business-rule status checks run). A receptionist
// with NO property assignment fails closed on every endpoint; admin and
// manager stay unrestricted. The plan's #5/#6 (employee-validation 422s)
// are ALREADY covered end-to-end by
// tests/Feature/Employee/EmployeePropertyValidationTest.php (Task 16) -
// same endpoint, same assertions, deliberately not duplicated here.
// Every test in this file runs active against the shipped endpoints -
// the last ->skip() chains were removed in T26/T28/T29 as each Phase-4
// dependency landed (ledger-tracked un-skip obligations).
//
// NOTE: BookingRoom/Property imports from the task template are omitted -
// neither class is referenced here (Pint no_unused_imports).

beforeEach(function () {
    // MAIN chain (seeded by migration 2025_03_01_000003, returned by helpers)
    $this->mainRoom = makeBookingRoom('RST1', makeRoomType('RST-M')->id);

    // WEST chain
    $this->westProperty = makeProperty('WEST', 'West Hotel');
    $this->westBuilding = makeBuilding('WEST-B', $this->westProperty->id);
    $this->westRoom = Room::create([
        'room_number' => 'RST2', 'room_type_id' => makeRoomType('RST-W')->id,
        'floor' => 1, 'building_id' => $this->westBuilding->id,
    ]);

    // Bookings + payments (makeHoldingBooking/makePaymentFor — status 'confirmed'/'pending')
    $this->ownBooking = makeHoldingBooking(makeBookingCustomer('rst-own@example.com'), $this->mainRoom, '2026-09-10', '2026-09-12', 'confirmed');
    $this->westBooking = makeHoldingBooking(makeBookingCustomer('rst-west@example.com'), $this->westRoom, '2026-09-10', '2026-09-12', 'confirmed');
    $this->ownPayment = makePaymentFor($this->ownBooking);
    $this->westPayment = makePaymentFor($this->westBooking);

    // Receptionist with MAIN assignment (loginAsEmployee assigns MAIN to receptionists — Task 20)
    $this->token = loginAsEmployee($this, 'rst-recep@example.com', 'receptionist');

    // Null-assignment receptionist: direct create + Sanctum token (actor:staff middleware
    // only checks instanceof Employee — verified app/Http/Middleware/EnsureActor.php).
    // Set status explicitly for determinism.
    $null = Employee::create([
        'name' => 'No Prop', 'email' => 'rst-null@example.com', 'password' => 'Password123!',
        'role' => 'receptionist', 'status' => 'active',
    ]);
    $this->nullToken = $null->createToken('test')->plainTextToken;

    // Admin + manager controls
    $this->adminToken = loginAsEmployee($this, 'rst-admin@example.com', 'admin');
    $this->managerToken = loginAsEmployee($this, 'rst-mgr@example.com', 'manager');
});

test('receptionist can view rooms of their own property only', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/rooms/{$this->mainRoom->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/rooms/{$this->westRoom->id}")
        ->assertForbidden();
});

test('receptionist can view bookings of their own property only', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/bookings/{$this->ownBooking->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/bookings/{$this->westBooking->id}")
        ->assertForbidden();
});

test('receptionist cannot confirm a booking from another property', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/staff/bookings/{$this->westBooking->id}/confirm")
        ->assertForbidden();
});

test('receptionist can view payments of their own property only', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/payments/{$this->ownPayment->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/payments/{$this->westPayment->id}")
        ->assertForbidden();
});

test('receptionist cannot verify a payment from another property', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/staff/payments/{$this->westPayment->id}/verify")
        ->assertForbidden();
});

test('a receptionist with no property assignment fails closed on detail endpoints', function () {
    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson("/api/v1/staff/rooms/{$this->mainRoom->id}")
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson("/api/v1/staff/bookings/{$this->ownBooking->id}")
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson("/api/v1/staff/payments/{$this->ownPayment->id}")
        ->assertForbidden();
});

test('admin keeps unrestricted access over HTTP', function () {
    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/staff/rooms/{$this->westRoom->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/staff/bookings/{$this->westBooking->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/staff/payments/{$this->westPayment->id}")
        ->assertOk();
});

test('manager keeps unrestricted access over HTTP', function () {
    $this->withHeader('Authorization', "Bearer {$this->managerToken}")
        ->getJson("/api/v1/staff/rooms/{$this->westRoom->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->managerToken}")
        ->getJson("/api/v1/staff/bookings/{$this->westBooking->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->managerToken}")
        ->getJson("/api/v1/staff/payments/{$this->westPayment->id}")
        ->assertOk();
});

test('receptionist sees only rooms of their assigned property on the rooms list (tampered property_id is ignored)', function () {
    $res = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/rooms?property_id={$this->westProperty->id}")
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id'))
        ->toContain($this->mainRoom->id)
        ->not->toContain($this->westRoom->id);
});

test("receptionist cannot view another property's detail", function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/staff/properties/{$this->westProperty->id}")
        ->assertForbidden();
});

test('a receptionist with no property assignment fails closed on the rooms list', function () {
    $this->withHeader('Authorization', "Bearer {$this->nullToken}")
        ->getJson('/api/v1/staff/rooms')
        ->assertForbidden();
});
