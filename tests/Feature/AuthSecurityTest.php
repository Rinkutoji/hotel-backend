<?php

use App\Models\Customer;
use App\Models\Employee;

test('an unauthenticated request to a protected customer route is rejected', function () {
    $this->getJson('/api/v1/customer/me')->assertStatus(401);
});

test('an unauthenticated request to a protected staff route is rejected', function () {
    $this->getJson('/api/v1/staff/me')->assertStatus(401);
});

test('a customer token cannot access a staff-only route', function () {
    Customer::create([
        'name' => 'Rita', 'email' => 'rita@example.com', 'password' => 'Password123!',
    ]);

    $token = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'rita@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/me')
        ->assertStatus(403);
});

test('an employee token cannot access a customer-only route', function () {
    Employee::create([
        'name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'Password123!',
        'role' => 'admin',
    ]);

    $token = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'sam@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me')
        ->assertStatus(403);
});

test('a malformed or garbage bearer token is rejected', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/api/v1/customer/me')
        ->assertStatus(401);
});

test('a revoked token is rejected on the very next request', function () {
    Customer::create([
        'name' => 'Tina', 'email' => 'tina@example.com', 'password' => 'Password123!',
    ]);

    $token = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'tina@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    // Confirm it works before revocation - otherwise a 401 afterward
    // proves nothing.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me')
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/auth/logout')
        ->assertStatus(200);

    // See CustomerAuthTest.php's logout test for why this line is needed
    // - Laravel's per-container guard caching, not an app bug.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me')
        ->assertStatus(401);
});
