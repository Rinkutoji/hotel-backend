<?php

use App\Models\Employee;

test('an employee can log in with correct credentials', function () {
    Employee::create([
        'name' => 'Manager Mike', 'email' => 'mike@example.com', 'password' => 'Password123!',
        'role' => 'manager',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'mike@example.com', 'password' => 'Password123!',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
    $response->assertJsonStructure(['data' => ['employee', 'token']]);
    $response->assertJsonMissingPath('data.employee.password');
});

test('employee login fails with invalid credentials', function () {
    Employee::create([
        'name' => 'Nina', 'email' => 'nina@example.com', 'password' => 'Password123!',
        'role' => 'receptionist',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'nina@example.com', 'password' => 'WrongPassword',
    ]);

    $response->assertStatus(401);
});

test('an inactive employee cannot log in', function () {
    Employee::create([
        'name' => 'Oscar', 'email' => 'oscar@example.com', 'password' => 'Password123!',
        'role' => 'receptionist', 'status' => 'inactive',
    ]);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'oscar@example.com', 'password' => 'Password123!',
    ]);

    $response->assertStatus(403);
});

test('an authenticated employee can view their own profile via me', function () {
    Employee::create([
        'name' => 'Paula', 'email' => 'paula@example.com', 'password' => 'Password123!',
        'role' => 'admin',
    ]);

    $token = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'paula@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/me');

    $response->assertStatus(200);
    $response->assertJsonPath('data.email', 'paula@example.com');
});

test('an employee can log out, revoking only the current token', function () {
    Employee::create([
        'name' => 'Quinn', 'email' => 'quinn@example.com', 'password' => 'Password123!',
        'role' => 'receptionist',
    ]);

    $token = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'quinn@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/auth/logout')
        ->assertStatus(200);

    // See CustomerAuthTest.php's logout test for why this line is needed
    // - Laravel's per-container guard caching, not an app bug.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/me')
        ->assertStatus(401);
});
