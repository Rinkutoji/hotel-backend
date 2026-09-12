<?php

use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Support\Facades\Gate;

// loginAsEmployee() is now declared in tests/Pest.php (shared across
// files) - was inline here in the original STEP 05 delivery, moved once
// STEP 06 needed the same helper.

// --- Receptionist ---

test('a receptionist can access a receptionist-level route', function () {
    $token = loginAsEmployee($this, 'recep1@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/receptionist-task')
        ->assertStatus(200);
});

test('a receptionist cannot access a manager-only route', function () {
    $token = loginAsEmployee($this, 'recep2@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/manager-task')
        ->assertStatus(403);
});

test('a receptionist cannot access an admin-only route', function () {
    $token = loginAsEmployee($this, 'recep3@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/admin-only')
        ->assertStatus(403);
});

// --- Manager ---

test('a manager can access a manager-level route', function () {
    $token = loginAsEmployee($this, 'mgr1@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/manager-task')
        ->assertStatus(200);
});

test('a manager can also access the receptionist-level route (included in its role list)', function () {
    $token = loginAsEmployee($this, 'mgr2@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/receptionist-task')
        ->assertStatus(200);
});

test('a manager cannot access an admin-only route', function () {
    $token = loginAsEmployee($this, 'mgr3@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/admin-only')
        ->assertStatus(403);
});

// --- Admin ---

test('an admin can access the admin-only route', function () {
    $token = loginAsEmployee($this, 'adm1@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/admin-only')
        ->assertStatus(200);
});

test('an admin can also access the manager-level and receptionist-level routes (explicitly listed on each)', function () {
    $token = loginAsEmployee($this, 'adm2@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/manager-task')
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/receptionist-task')
        ->assertStatus(200);
});

// --- Unauthenticated / wrong actor ---

test('an unauthenticated request to a role-protected route is rejected with 401', function () {
    $this->getJson('/api/v1/staff/_authz-check/any-staff')->assertStatus(401);
});

test('a customer token cannot access any role-protected staff route, even admin-only', function () {
    Customer::create([
        'name' => 'Uma', 'email' => 'uma@example.com', 'password' => 'Password123!',
    ]);

    $token = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'uma@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/admin-only')
        ->assertStatus(403);
});

// --- Role cannot be spoofed via request data ---

test('a receptionist cannot elevate to admin by sending a role claim in the request', function () {
    $token = loginAsEmployee($this, 'recep4@example.com', 'receptionist');

    // Spoofed claims via header AND body - neither is ever read by the
    // role middleware, which only looks at $user->role from the DB.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Role', 'admin')
        ->getJson('/api/v1/staff/_authz-check/admin-only?role=admin&is_admin=1');

    $response->assertStatus(403);
});

test('role is read from the employee record even when it changes between logins', function () {
    // Same person, role genuinely changed in the DB between logins -
    // proves the check re-reads the DB each time, not a cached claim
    // from the token itself.
    $employee = Employee::create([
        'name' => 'Vic', 'email' => 'vic@example.com', 'password' => 'Password123!',
        'role' => 'receptionist',
    ]);

    $token = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'vic@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/admin-only')
        ->assertStatus(403);

    $employee->update(['role' => 'admin']);
    // See CustomerAuthTest.php's logout test (STEP 04) for why this line
    // is needed - Laravel's per-container guard caching, not an app bug.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/_authz-check/admin-only')
        ->assertStatus(200);
});

// --- Refund authorization (Gate, not a route - see plan §6) ---

test('refund authorization matrix: admin allowed, manager allowed, receptionist and customer denied', function () {
    $admin = Employee::create([
        'name' => 'Admin', 'email' => 'refund-admin@example.com', 'password' => 'x', 'role' => 'admin',
    ]);
    $manager = Employee::create([
        'name' => 'Manager', 'email' => 'refund-mgr@example.com', 'password' => 'x', 'role' => 'manager',
    ]);
    $receptionist = Employee::create([
        'name' => 'Receptionist', 'email' => 'refund-recep@example.com', 'password' => 'x', 'role' => 'receptionist',
    ]);
    $customer = Customer::create([
        'name' => 'Customer', 'email' => 'refund-cust@example.com', 'password' => 'x',
    ]);

    expect(Gate::forUser($admin)->allows('process-refund'))->toBeTrue();
    expect(Gate::forUser($manager)->allows('process-refund'))->toBeTrue();
    expect(Gate::forUser($receptionist)->allows('process-refund'))->toBeFalse();
    expect(Gate::forUser($customer)->allows('process-refund'))->toBeFalse();
});

// --- CustomerPolicy (ownership, not yet wired to a route - see plan §4) ---

test('CustomerPolicy allows a customer to view their own record only', function () {
    $alice = Customer::create(['name' => 'Alice', 'email' => 'policy-alice@example.com', 'password' => 'x']);
    $bob = Customer::create(['name' => 'Bob', 'email' => 'policy-bob@example.com', 'password' => 'x']);

    expect(Gate::forUser($alice)->allows('view', $alice))->toBeTrue();
    expect(Gate::forUser($alice)->allows('view', $bob))->toBeFalse();
});
