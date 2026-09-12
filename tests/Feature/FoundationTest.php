<?php

test('the application boots', function () {
    expect(true)->toBeTrue();
});

test('the health endpoint responds successfully', function () {
    $this->getJson('/api/v1/health')->assertStatus(200);
});

test('the health endpoint returns the standard JSON envelope', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertJsonStructure([
        'success',
        'message',
        'data' => [
            'app',
            'status',
            'timestamp',
            'database' => [
                'connected',
                'message',
            ],
        ],
    ]);

    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'ok',
        ],
    ]);
});

test('the health endpoint reports the database as connected', function () {
    // Feature tests run against in-memory sqlite (see phpunit.xml). This
    // proves the DB::connection()->getPdo() check in HealthController
    // actually works against a real (if lightweight) connection - not
    // that MySQL specifically is reachable, which can only be confirmed
    // by running this same test locally with the mysql connection active.
    $response = $this->getJson('/api/v1/health');

    $response->assertJsonPath('data.database.connected', true);
});

test('the v1 prefix is required - the same route without it does not exist', function () {
    // Proves versioning is actually enforced, not just that the
    // correctly-prefixed URL happens to work (already covered above).
    $this->getJson('/api/v1/health')->assertStatus(200);
    $this->getJson('/api/health')->assertStatus(404);
});

test('an unknown api route returns a JSON 404, not an HTML error page', function () {
    $response = $this->getJson('/api/v1/this-route-does-not-exist');

    $response->assertStatus(404);
    $response->assertJsonStructure(['success', 'message']);
    $response->assertJson(['success' => false]);
});

test('an unsupported http verb returns a JSON 405, not an HTML error page', function () {
    // /health only registers GET.
    $response = $this->postJson('/api/v1/health');

    $response->assertStatus(405);
    $response->assertJsonStructure(['success', 'message']);
    $response->assertJson(['success' => false]);
});
