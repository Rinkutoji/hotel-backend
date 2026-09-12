<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Payment;
use App\Policies\PaymentPolicy;

function staffOf(string $role): Employee
{
    return Employee::create([
        'name' => ucfirst($role), 'email' => 'policy-' . $role . '@example.com',
        'password' => 'Password123!', 'role' => $role,
    ]);
}

test('only customers can create payments', function () {
    $policy = new PaymentPolicy();
    $customer = Customer::create(['name' => 'C', 'email' => 'policy-cust@example.com', 'password' => 'Password123!']);

    expect($policy->create($customer))->toBeTrue();
    expect($policy->create(staffOf('admin')))->toBeFalse();
    expect($policy->create(staffOf('manager')))->toBeFalse();
    expect($policy->create(staffOf('receptionist')))->toBeFalse();
});

test('customers can only view their own payment and employees can view any payment', function () {
    $policy = new PaymentPolicy();
    $owner = makeBookingCustomer('policy-owner@example.com');
    $other = makeBookingCustomer('policy-other@example.com');
    $room = makeBookingRoom('POL1', makeRoomType()->id);
    $booking = makeHoldingBooking($owner, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    expect($policy->view($owner, $payment))->toBeTrue();
    expect($policy->view($other, $payment))->toBeFalse();
    expect($policy->view(staffOf('admin'), $payment))->toBeTrue();
    expect($policy->view(staffOf('receptionist'), $payment))->toBeTrue();
});

test('customers can view a list of their own payments and employees can list all', function () {
    $policy = new PaymentPolicy();
    $customer = makeBookingCustomer('policy-list@example.com');

    expect($policy->viewAny($customer))->toBeTrue();
    expect($policy->viewAny(staffOf('receptionist')))->toBeTrue();
});

test('only admin, manager and receptionist employees can verify and reject', function () {
    $policy = new PaymentPolicy();

    foreach (['admin', 'manager', 'receptionist'] as $role) {
        $staff = staffOf($role);
        expect($policy->verify($staff))->toBeTrue("employee role {$role} should be allowed to verify");
        expect($policy->reject($staff))->toBeTrue("employee role {$role} should be allowed to reject");
    }

    $customer = makeBookingCustomer('policy-verify-cust@example.com');
    expect($policy->verify($customer))->toBeFalse();
    expect($policy->reject($customer))->toBeFalse();
});

test('a non-owner customer is denied on every payment operation', function () {
    $policy = new PaymentPolicy();
    $owner = makeBookingCustomer('policy-op-owner@example.com');
    $intruder = makeBookingCustomer('policy-op-intruder@example.com');
    $room = makeBookingRoom('POL2', makeRoomType()->id);
    $booking = makeHoldingBooking($owner, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);

    expect($policy->view($intruder, $payment))->toBeFalse();
    expect($policy->uploadReceipt($intruder, $payment))->toBeFalse();
    expect($policy->downloadReceipt($intruder, $payment))->toBeFalse();
});

test('uploading a receipt is customer-owned only', function () {
    $policy = new PaymentPolicy();
    $owner = makeBookingCustomer('policy-upload-owner@example.com');
    $room = makeBookingRoom('POL3', makeRoomType()->id);
    $booking = makeHoldingBooking($owner, $room, '2026-09-10', '2026-09-12', 'pending_payment');
    $payment = makePaymentFor($booking);
    $staff = staffOf('receptionist');

    expect($policy->uploadReceipt($owner, $payment))->toBeTrue();
    expect($policy->uploadReceipt($staff, $payment))->toBeFalse();
    expect($policy->downloadReceipt($owner, $payment))->toBeTrue();
    expect($policy->downloadReceipt($staff, $payment))->toBeTrue();
});