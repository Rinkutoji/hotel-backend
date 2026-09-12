<?php

use App\Models\Customer;

test('a customer can register successfully', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'Alice Customer',
        'email' => 'alice@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'phone' => '555-0100',
    ]);

    $response->assertStatus(201);
    $response->assertJson(['success' => true]);
    $response->assertJsonStructure([
        'data' => ['customer' => ['id', 'name', 'email'], 'token'],
    ]);
    $response->assertJsonMissingPath('data.customer.password');

    expect(Customer::where('email', 'alice@example.com')->exists())->toBeTrue();
});

test('registration fails with a duplicate email', function () {
    Customer::create([
        'name' => 'Existing', 'email' => 'dup@example.com', 'password' => 'Password123!',
    ]);

    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'New', 'email' => 'dup@example.com',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
    $response->assertJsonValidationErrors('email');
});

test('registration requires matching password confirmation', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'Bob', 'email' => 'bob@example.com',
        'password' => 'Password123!', 'password_confirmation' => 'DoesNotMatch',
    ]);

    $response->assertStatus(422);
});

test('a customer can log in with correct credentials', function () {
    Customer::create([
        'name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'Password123!',
    ]);

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'carol@example.com', 'password' => 'Password123!',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
    $response->assertJsonStructure(['data' => ['customer', 'token']]);
});

test('login fails with the wrong password', function () {
    Customer::create([
        'name' => 'Dan', 'email' => 'dan@example.com', 'password' => 'Password123!',
    ]);

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'dan@example.com', 'password' => 'WrongPassword',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['success' => false]);
});

test('login fails for a non-existent email with the same generic message as a wrong password', function () {
    $response = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'nobody@example.com', 'password' => 'Whatever123',
    ]);

    $response->assertStatus(401);
    $response->assertJsonPath('message', 'Invalid credentials.');
});

test('login fails for an inactive account with a distinct 403, not 401', function () {
    Customer::create([
        'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'Password123!',
        'status' => 'inactive',
    ]);

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'eve@example.com', 'password' => 'Password123!',
    ]);

    $response->assertStatus(403);
});

test('login requires email and password', function () {
    $response = $this->postJson('/api/v1/customer/auth/login', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email', 'password']);
});

test('an authenticated customer can view their own profile via me', function () {
    Customer::create([
        'name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'Password123!',
    ]);

    $token = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'frank@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me');

    $response->assertStatus(200);
    $response->assertJsonPath('data.email', 'frank@example.com');
    $response->assertJsonMissingPath('data.password');
});

test('a customer can log out, revoking only the current token', function () {
    Customer::create([
        'name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'Password123!',
    ]);

    $token = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'grace@example.com', 'password' => 'Password123!',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/auth/logout')
        ->assertStatus(200);

    // Laravel's auth guards are resolved once per application container
    // and cached (Illuminate\Auth\RequestGuard::$user memoizes after the
    // first resolution). In production this is a non-issue - every real
    // HTTP request boots a brand new container. But within a single test
    // method, $this->postJson()/getJson() calls share the SAME container,
    // so without this line the next call below would return the STALE
    // cached user from before logout, never re-querying
    // personal_access_tokens to notice it was deleted. This does not
    // touch authentication logic or weaken it - it only resets test-side
    // caching so the next simulated request behaves like a genuine fresh
    // HTTP request, which is what we actually want to prove.
    $this->app['auth']->forgetGuards();

    // The same token must now be rejected.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me')
        ->assertStatus(401);
});
