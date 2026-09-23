<?php

/**
 * Staff account management (employees): the admin-only surface.
 *
 * Rules exercised:
 * - The module lives under /api/v1/staff/employees and is ADMIN-only
 *   (route role:admin + EmployeePolicy second layer).
 * - Admin can list (paginated, with search/role/status filters), create,
 *   view, update (role/status/phone/name/email), change password, and
 *   soft-delete employee accounts.
 * - Deactivation = status:inactive; AuthController::login rejects inactive
 *   employees.
 * - Password is never echoed back (Employee.$hidden).
 * - Manager/receptionist staff and any customer get 403; nobody gets in
 *   unauthenticated.
 */

use App\Models\Employee;
use Illuminate\Support\Facades\Hash;

test('the employee list contains only the calling admin when no other accounts exist', function () {
    $token = loginAsEmployee($this, 'emp-admin-list@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/employees')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'emp-admin-list@example.com');
});

test('an admin can create, view, update, and soft-delete an employee', function () {
    $token = loginAsEmployee($this, 'emp-admin-crud@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $create = $this->withHeaders($auth)
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Receptionist Rosa',
            'email' => 'rosa@hotel.test',
            'password' => 'P@ssw0rd!',
            'phone' => '012 345 678',
            'role' => 'receptionist',
            'property_id' => makeProperty()->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Receptionist Rosa')
        ->assertJsonPath('data.email', 'rosa@hotel.test')
        ->assertJsonPath('data.role', 'receptionist')
        ->assertJsonPath('data.status', 'active');

    $id = $create->json('data.id');

    $this->withHeaders($auth)
        ->getJson("/api/v1/staff/employees/{$id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$id}", [
            'name' => 'Receptionist Rosa II',
            'email' => 'rosa@hotel.test',
            'role' => 'receptionist',
            'property_id' => makeProperty()->id,
            'status' => 'inactive',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Receptionist Rosa II')
        ->assertJsonPath('data.status', 'inactive');

    $this->withHeaders($auth)
        ->deleteJson("/api/v1/staff/employees/{$id}")
        ->assertStatus(200);

    expect(Employee::find($id))->toBeNull();
    expect(Employee::withTrashed()->find($id))->not->toBeNull();
});

test('an admin API response never contains the password hash', function () {
    $token = loginAsEmployee($this, 'emp-admin-nopass@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $created = $this->withHeaders($auth)
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Nina', 'email' => 'nina@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'receptionist', 'property_id' => makeProperty()->id,
        ])
        ->assertStatus(201);

    $created->assertJsonMissingPath('data.password');

    $this->withHeaders($auth)
        ->getJson('/api/v1/staff/employees')
        ->assertJsonMissingPath('data.0.password');

    $this->withHeaders($auth)
        ->getJson("/api/v1/staff/employees/{$created->json('data.id')}")
        ->assertJsonMissingPath('data.password');
});

test('the created employee can actually log in with the given password', function () {
    $token = loginAsEmployee($this, 'emp-admin-create@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Manager Mo', 'email' => 'mo@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'manager',
        ])
        ->assertStatus(201);

    $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'mo@hotel.test', 'password' => 'P@ssw0rd!',
    ])->assertStatus(200)->assertJsonPath('data.employee.role', 'manager');
});

test('reactivating is the status flip that lets the account log in again', function () {
    $token = loginAsEmployee($this, 'emp-admin-cycle@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $created = $this->withHeaders($auth)
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Cy', 'email' => 'cy@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'receptionist', 'property_id' => makeProperty()->id,
        ])
        ->assertStatus(201);

    $id = $created->json('data.id');

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$id}", [
            'name' => 'Cy', 'email' => 'cy@hotel.test', 'role' => 'receptionist',
            'property_id' => makeProperty()->id,
            'status' => 'inactive',
        ])
        ->assertStatus(200);

    $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'cy@hotel.test', 'password' => 'P@ssw0rd!',
    ])->assertStatus(403);

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$id}", [
            'name' => 'Cy', 'email' => 'cy@hotel.test', 'role' => 'receptionist',
            'property_id' => makeProperty()->id,
            'status' => 'active',
        ])
        ->assertStatus(200);

    $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'cy@hotel.test', 'password' => 'P@ssw0rd!',
    ])->assertStatus(200);
});

test('a password update invalidates the old password and accepts the new one', function () {
    $token = loginAsEmployee($this, 'emp-admin-pass@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];
    $email = 'pw@hotel.test';

    $this->withHeaders($auth)
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Pw', 'email' => $email, 'password' => 'OldPass123!',
            'role' => 'receptionist', 'property_id' => makeProperty()->id,
        ])
        ->assertStatus(201);

    $employee = Employee::where('email', $email)->firstOrFail();

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$employee->id}", [
            'name' => 'Pw', 'email' => $email, 'role' => 'receptionist',
            'property_id' => makeProperty()->id,
            'password' => 'NewPass456!',
        ])
        ->assertStatus(200);

    expect(Hash::check('NewPass456!', $employee->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/admin/auth/login', [
        'email' => $email, 'password' => 'OldPass123!',
    ])->assertStatus(401);

    $this->postJson('/api/v1/admin/auth/login', [
        'email' => $email, 'password' => 'NewPass456!',
    ])->assertStatus(200);
});

test('omitting the password on update leaves it unchanged', function () {
    $token = loginAsEmployee($this, 'emp-admin-keep@example.com', 'admin');
    $employee = Employee::create([
        'name' => 'Keep', 'email' => 'keep@hotel.test', 'password' => 'SamePass123!',
        'role' => 'manager',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/employees/{$employee->id}", [
            'name' => 'Keep', 'email' => 'keep@hotel.test', 'role' => 'manager',
        ])
        ->assertStatus(200);

    expect(Hash::check('SamePass123!', $employee->fresh()->password))->toBeTrue();
});

test('store validates name, email, password, role, and status bounds', function () {
    $token = loginAsEmployee($this, 'emp-admin-validation@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'email' => 'no-name@hotel.test', 'password' => 'P@ssw0rd!', 'role' => 'admin',
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Bad Email', 'email' => 'not-an-email', 'password' => 'P@ssw0rd!', 'role' => 'admin',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Short PW', 'email' => 'short@hotel.test', 'password' => 'short', 'role' => 'admin',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Bad Role', 'email' => 'role@hotel.test', 'password' => 'P@ssw0rd!', 'role' => 'superuser',
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Bad Status', 'email' => 'status@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'admin', 'status' => 'fired',
    ])->assertStatus(422)->assertJsonValidationErrors('status');

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Bad Dept', 'email' => 'dept@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'admin', 'department_id' => 99999,
    ])->assertStatus(422)->assertJsonValidationErrors('department_id');
});

test('email uniqueness is enforced on create and update', function () {
    loginAsEmployee($this, 'boss@hotel.test', 'admin');
    $token = loginAsEmployee($this, 'emp-admin-unique@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Dup', 'email' => 'boss@hotel.test', 'password' => 'P@ssw0rd!', 'role' => 'receptionist',
        'property_id' => makeProperty()->id,
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $other = $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Other', 'email' => 'other@hotel.test', 'password' => 'P@ssw0rd!', 'role' => 'receptionist',
        'property_id' => makeProperty()->id,
    ])->assertStatus(201)->json('data.id');

    // Ignoring its own email is fine...
    $this->withHeaders($auth)->putJson("/api/v1/staff/employees/{$other}", [
        'name' => 'Other', 'email' => 'other@hotel.test', 'role' => 'receptionist',
        'property_id' => makeProperty()->id,
    ])->assertStatus(200);

    // ...but stealing the boss's email is not.
    $this->withHeaders($auth)->putJson("/api/v1/staff/employees/{$other}", [
        'name' => 'Other', 'email' => 'boss@hotel.test', 'role' => 'receptionist',
        'property_id' => makeProperty()->id,
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('manager staff are forbidden from every employee endpoint', function () {
    $token = loginAsEmployee($this, 'emp-manager@example.com', 'manager');
    $employee = Employee::create([
        'name' => 'Target', 'email' => 'target@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'receptionist',
    ]);
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->getJson('/api/v1/staff/employees')->assertStatus(403);
    $this->withHeaders($auth)->postJson('/api/v1/staff/employees', [
        'name' => 'Sneak', 'email' => 'sneak@hotel.test', 'password' => 'P@ssw0rd!', 'role' => 'admin',
    ])->assertStatus(403);
    $this->withHeaders($auth)->getJson("/api/v1/staff/employees/{$employee->id}")->assertStatus(403);
    $this->withHeaders($auth)->putJson("/api/v1/staff/employees/{$employee->id}", [
        'name' => 'Sneak', 'email' => 'target@hotel.test', 'role' => 'admin',
    ])->assertStatus(403);
    $this->withHeaders($auth)->deleteJson("/api/v1/staff/employees/{$employee->id}")->assertStatus(403);
});

test('receptionist staff are forbidden from every employee endpoint', function () {
    $token = loginAsEmployee($this, 'emp-receptionist@example.com', 'receptionist');
    $employee = Employee::where('email', 'emp-receptionist@example.com')->firstOrFail();
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->getJson('/api/v1/staff/employees')->assertStatus(403);
    $this->withHeaders($auth)->getJson("/api/v1/staff/employees/{$employee->id}")->assertStatus(403);
    $this->withHeaders($auth)->putJson("/api/v1/staff/employees/{$employee->id}", [
        'name' => 'Sneak', 'email' => 'emp-receptionist@example.com', 'role' => 'receptionist',
    ])->assertStatus(403);
});

test('a customer token cannot reach the employee admin surface', function () {
    $token = loginAsCustomer($this, 'emp-customer@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/employees')
        ->assertStatus(403);
});

test('an unauthenticated caller gets 401 from the employee admin surface', function () {
    $this->getJson('/api/v1/staff/employees')->assertStatus(401);
});

test('the index filters by search, role, and status', function () {
    $token = loginAsEmployee($this, 'emp-admin-filter@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    foreach ([
        ['name' => 'Alice Admin', 'email' => 'alice@hotel.test', 'role' => 'admin'],
        ['name' => 'Bob Manager', 'email' => 'bob@hotel.test', 'role' => 'manager'],
        ['name' => 'Carol Reception', 'email' => 'carol@hotel.test', 'role' => 'receptionist'],
    ] as $account) {
        Employee::create($account + ['password' => 'P@ssw0rd!']);
    }

    $this->withHeaders($auth)->getJson('/api/v1/staff/employees?role=manager')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'bob@hotel.test');

    $this->withHeaders($auth)->getJson('/api/v1/staff/employees?search=carol')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'carol@hotel.test');

    $this->withHeaders($auth)->getJson('/api/v1/staff/employees?status=inactive')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');

    $this->withHeaders($auth)->getJson('/api/v1/staff/employees?sort=email&direction=asc')
        ->assertStatus(200)
        ->assertJsonPath('data.0.email', 'alice@hotel.test');
});

test('a soft-deleted employee disappears from the list and show but keeps the row', function () {
    $token = loginAsEmployee($this, 'emp-admin-delete@example.com', 'admin');
    $employee = Employee::create([
        'name' => 'Gone', 'email' => 'gone@hotel.test', 'password' => 'P@ssw0rd!', 'role' => 'receptionist',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/employees/{$employee->id}")
        ->assertStatus(200);

    // Deleted account is gone from the list (only the calling admin
    // remains) and from show (404), but the row is soft-deleted, not
    // destroyed.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/employees')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'emp-admin-delete@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/employees/{$employee->id}")
        ->assertStatus(404);

    expect(Employee::withTrashed()->find($employee->id))->not->toBeNull();

    // A deleted account cannot sign in again - the login query uses the
    // default (non-trashed) scope.
    $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'gone@hotel.test', 'password' => 'P@ssw0rd!',
    ])->assertStatus(401);
});
