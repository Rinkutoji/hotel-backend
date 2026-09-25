<?php

use App\Models\BookingRoom;
use App\Models\Employee;
use App\Models\Room;
use App\Policies\BookingPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RoomPolicy;

// Task 20: direct policy-level property scoping (spec §8.1 access matrix).
// Receptionists are confined to their assigned property; null-assignment
// receptionists fail closed; admin/manager and customer branches unchanged.

beforeEach(function () {
    $this->mainProperty = makeProperty();
    $this->mainRoom = makeBookingRoom('PPS1', makeRoomType()->id);

    $this->westProperty = makeProperty('WEST', 'West Hotel');
    $this->westBuilding = makeBuilding('WEST-B', $this->westProperty->id);
    $this->westRoom = Room::create([
        'room_number' => 'PPS2', 'room_type_id' => makeRoomType('WType')->id,
        'floor' => 1, 'building_id' => $this->westBuilding->id,
    ]);

    $this->receptionist = Employee::create([
        'name' => 'R', 'email' => 'pps-recep@example.com',
        'password' => 'Password123!', 'role' => 'receptionist',
        'property_id' => $this->mainProperty->id,
    ]);
    $this->nullReceptionist = Employee::create([
        'name' => 'RN', 'email' => 'pps-null@example.com',
        'password' => 'Password123!', 'role' => 'receptionist',
    ]);
    $this->admin = Employee::create([
        'name' => 'A', 'email' => 'pps-admin@example.com',
        'password' => 'Password123!', 'role' => 'admin',
    ]);
    $this->manager = Employee::create([
        'name' => 'M', 'email' => 'pps-mgr@example.com',
        'password' => 'Password123!', 'role' => 'manager',
    ]);

    $this->customer = makeBookingCustomer('pps-guest@example.com');
    $this->ownBooking = makeHoldingBooking($this->customer, $this->mainRoom, '2026-09-10', '2026-09-12', 'confirmed');
    $this->westBooking = makeHoldingBooking(makeBookingCustomer('pps-west@example.com'), $this->westRoom, '2026-09-10', '2026-09-12', 'confirmed');

    $this->mixedBooking = makeHoldingBooking($this->customer, $this->mainRoom, '2026-09-10', '2026-09-12', 'confirmed');
    BookingRoom::create([
        'booking_id' => $this->mixedBooking->id,
        'room_id' => $this->westRoom->id,
        'room_type_id' => $this->westRoom->room_type_id,
        'price_per_night' => 100,
    ]);

    $this->ownPayment = makePaymentFor($this->ownBooking);
    $this->westPayment = makePaymentFor($this->westBooking);
});

test('receptionist can view rooms of their own property only', function () {
    $policy = app(RoomPolicy::class);

    expect($policy->view($this->receptionist, $this->mainRoom))->toBeTrue();
    expect($policy->view($this->receptionist, $this->westRoom))->toBeFalse();
});

test('receptionist can change status of their own property rooms only', function () {
    $policy = app(RoomPolicy::class);

    expect($policy->changeStatus($this->receptionist, $this->mainRoom))->toBeTrue();
    expect($policy->changeStatus($this->receptionist, $this->westRoom))->toBeFalse();
});

test('receptionist without property assignment fails closed on room actions', function () {
    $policy = app(RoomPolicy::class);

    expect($policy->view($this->nullReceptionist, $this->mainRoom))->toBeFalse();
    expect($policy->changeStatus($this->nullReceptionist, $this->mainRoom))->toBeFalse();
});

test('admin and manager keep unrestricted room access', function () {
    $policy = app(RoomPolicy::class);

    foreach ([$this->admin, $this->manager] as $staff) {
        expect($policy->view($staff, $this->westRoom))->toBeTrue();
        expect($policy->changeStatus($staff, $this->westRoom))->toBeTrue();
    }
});

test('receptionist can perform every lifecycle action on a booking in their own property', function () {
    $policy = app(BookingPolicy::class);
    $booking = $this->ownBooking;

    expect($policy->view($this->receptionist, $booking))->toBeTrue();
    expect($policy->confirm($this->receptionist, $booking))->toBeTrue();
    expect($policy->checkIn($this->receptionist, $booking))->toBeTrue();
    expect($policy->checkOut($this->receptionist, $booking))->toBeTrue();
    expect($policy->cancel($this->receptionist, $booking))->toBeTrue();
});

test('receptionist is denied every lifecycle action on a cross-property booking', function () {
    $policy = app(BookingPolicy::class);
    $booking = $this->westBooking;

    expect($policy->view($this->receptionist, $booking))->toBeFalse();
    expect($policy->confirm($this->receptionist, $booking))->toBeFalse();
    expect($policy->checkIn($this->receptionist, $booking))->toBeFalse();
    expect($policy->checkOut($this->receptionist, $booking))->toBeFalse();
    expect($policy->cancel($this->receptionist, $booking))->toBeFalse();
});

test('receptionist is denied every lifecycle action on a booking spanning properties', function () {
    $policy = app(BookingPolicy::class);
    $booking = $this->mixedBooking;

    expect($policy->view($this->receptionist, $booking))->toBeFalse();
    expect($policy->confirm($this->receptionist, $booking))->toBeFalse();
    expect($policy->checkIn($this->receptionist, $booking))->toBeFalse();
    expect($policy->checkOut($this->receptionist, $booking))->toBeFalse();
    expect($policy->cancel($this->receptionist, $booking))->toBeFalse();
});

test('receptionist is denied a cross-property booking whose building was soft-deleted', function () {
    $policy = app(BookingPolicy::class);
    $paymentPolicy = app(PaymentPolicy::class);
    $this->westBuilding->delete();

    $booking = $this->westBooking;

    expect($policy->view($this->receptionist, $booking))->toBeFalse();
    expect($policy->confirm($this->receptionist, $booking))->toBeFalse();
    expect($policy->cancel($this->receptionist, $booking))->toBeFalse();
    expect($paymentPolicy->view($this->receptionist, $this->westPayment))->toBeFalse();
    expect($paymentPolicy->verify($this->receptionist, $this->westPayment))->toBeFalse();
});

test('receptionist without property assignment fails closed on booking view', function () {
    $policy = app(BookingPolicy::class);

    expect($policy->view($this->nullReceptionist, $this->ownBooking))->toBeFalse();
});

test('admin and manager keep unrestricted booking access', function () {
    $policy = app(BookingPolicy::class);
    $booking = $this->westBooking;

    foreach ([$this->admin, $this->manager] as $staff) {
        expect($policy->view($staff, $booking))->toBeTrue();
        expect($policy->confirm($staff, $booking))->toBeTrue();
        expect($policy->checkIn($staff, $booking))->toBeTrue();
        expect($policy->checkOut($staff, $booking))->toBeTrue();
        expect($policy->cancel($staff, $booking))->toBeTrue();
    }
});

test('customer booking branches are unchanged', function () {
    $policy = app(BookingPolicy::class);
    $booking = $this->ownBooking;

    expect($policy->view($this->customer, $booking))->toBeTrue();
    expect($policy->cancel($this->customer, $booking))->toBeTrue();
    expect($policy->confirm($this->customer, $booking))->toBeFalse();
    expect($policy->checkIn($this->customer, $booking))->toBeFalse();
    expect($policy->checkOut($this->customer, $booking))->toBeFalse();
});

test('receptionist can view, verify, reject and download receipt of a payment in their own property', function () {
    $policy = app(PaymentPolicy::class);
    $payment = $this->ownPayment;

    expect($policy->view($this->receptionist, $payment))->toBeTrue();
    expect($policy->verify($this->receptionist, $payment))->toBeTrue();
    expect($policy->reject($this->receptionist, $payment))->toBeTrue();
    expect($policy->downloadReceipt($this->receptionist, $payment))->toBeTrue();
});

test('receptionist is denied all payment actions on a cross-property payment', function () {
    $policy = app(PaymentPolicy::class);
    $payment = $this->westPayment;

    expect($policy->view($this->receptionist, $payment))->toBeFalse();
    expect($policy->verify($this->receptionist, $payment))->toBeFalse();
    expect($policy->reject($this->receptionist, $payment))->toBeFalse();
    expect($policy->downloadReceipt($this->receptionist, $payment))->toBeFalse();
});

test('receptionist without property assignment fails closed on payment view', function () {
    $policy = app(PaymentPolicy::class);

    expect($policy->view($this->nullReceptionist, $this->ownPayment))->toBeFalse();
});

test('admin and manager keep unrestricted payment access', function () {
    $policy = app(PaymentPolicy::class);
    $payment = $this->westPayment;

    foreach ([$this->admin, $this->manager] as $staff) {
        expect($policy->view($staff, $payment))->toBeTrue();
        expect($policy->verify($staff, $payment))->toBeTrue();
        expect($policy->reject($staff, $payment))->toBeTrue();
        expect($policy->downloadReceipt($staff, $payment))->toBeTrue();
    }
});
