<?php

/**
 * Employee property_id validation (location feature, spec §7.5):
 * receptionists MUST be assigned to an existing, non-soft-deleted
 * property; admins/managers MUST NOT receive a property from client
 * input. The rule lives on StoreRequest + UpdateRequest, so bad input
 * never reaches the controller.
 *
 * Update-behavior contract (EmployeeController::update is partial - only
 * sent keys are applied):
 * - role=receptionist without property_id in the payload → 422
 *   (required_if is an implicit rule, it runs even on a missing key).
 * - role=admin/manager WITH property_id → 422 (prohibited_unless).
 * - receptionist → admin/manager without property_id → 200; the old
 *   property_id stays on the row (deliberately not asserted here).
 */

use App\Models\Employee;

test('a receptionist cannot be created without a property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-missing@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'No Property', 'email' => 'noprop@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'receptionist',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('a receptionist can be created with a valid property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-withprop@example.com', 'admin');
    $property = makeProperty();

    $created = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'With Property', 'email' => 'withprop@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'receptionist', 'property_id' => $property->id,
        ])
        ->assertStatus(201);

    expect($created->json('data.property_id'))->toBe($property->id);
});

test('a receptionist cannot be assigned a nonexistent property', function () {
    $token = loginAsEmployee($this, 'propval-admin-ghost@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Ghost Property', 'email' => 'ghost@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'receptionist', 'property_id' => 999999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('a receptionist cannot be assigned a soft-deleted property', function () {
    $token = loginAsEmployee($this, 'propval-admin-softdel@example.com', 'admin');
    $property = makeProperty('SOFTDEL-'.uniqid(), 'Soft Deleted');
    $property->delete();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Deleted Property', 'email' => 'softdel@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'receptionist', 'property_id' => $property->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('an admin cannot be given a property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-noprop@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Admin With Property', 'email' => 'adminprop@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'admin', 'property_id' => makeProperty()->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('a manager cannot be given a property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-mgrprop@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Manager With Property', 'email' => 'mgrprop@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'manager', 'property_id' => makeProperty()->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('an admin is still created without any property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-plain@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Plain Admin', 'email' => 'plainadmin@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'admin',
        ])
        ->assertStatus(201);
});

test('a manager is still created without any property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-mgrplain@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/employees', [
            'name' => 'Plain Manager', 'email' => 'plainmgr@hotel.test', 'password' => 'P@ssw0rd!',
            'role' => 'manager',
        ])
        ->assertStatus(201);
});

test('updating an admin into a receptionist requires a property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-promote@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];
    $admin = Employee::create([
        'name' => 'Promoted', 'email' => 'promoted@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'admin',
    ]);

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$admin->id}", [
            'name' => 'Promoted', 'email' => 'promoted@hotel.test', 'role' => 'receptionist',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('updating a receptionist into a manager rejects a property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-demote@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];
    $property = makeProperty();
    $receptionist = Employee::create([
        'name' => 'Demoted', 'email' => 'demoted@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'receptionist', 'property_id' => $property->id,
    ]);

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$receptionist->id}", [
            'name' => 'Demoted', 'email' => 'demoted@hotel.test', 'role' => 'manager',
            'property_id' => $property->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('property_id');
});

test('updating a receptionist into an admin without a property assignment succeeds', function () {
    $token = loginAsEmployee($this, 'propval-admin-transition@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];
    $property = makeProperty();
    $receptionist = Employee::create([
        'name' => 'Transition', 'email' => 'transition@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'receptionist', 'property_id' => $property->id,
    ]);

    // Partial-update contract: only sent keys are applied; the old
    // property_id is deliberately NOT asserted on here (it stays on the
    // row - flagged for a later task).
    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$receptionist->id}", [
            'name' => 'Transition', 'email' => 'transition@hotel.test', 'role' => 'admin',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.role', 'admin');
});

test('a receptionist can be re-saved with the same property assignment', function () {
    $token = loginAsEmployee($this, 'propval-admin-resave@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];
    $property = makeProperty();
    $receptionist = Employee::create([
        'name' => 'Resave', 'email' => 'resave@hotel.test', 'password' => 'P@ssw0rd!',
        'role' => 'receptionist', 'property_id' => $property->id,
    ]);

    $this->withHeaders($auth)
        ->putJson("/api/v1/staff/employees/{$receptionist->id}", [
            'name' => 'Resave', 'email' => 'resave@hotel.test', 'role' => 'receptionist',
            'property_id' => $property->id,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.property_id', $property->id);
});
