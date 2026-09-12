<?php

use App\Models\RoomType;

// --- Admin: full CRUD ---

test('an admin can create a room type', function () {
    $token = loginAsEmployee($this, 'admin-create@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Suite', 'base_price' => 300, 'capacity_adults' => 2, 'capacity_children' => 1,
            'amenities' => ['WiFi', 'Mini Bar'],
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.name', 'Suite');
    expect(RoomType::where('name', 'Suite')->exists())->toBeTrue();
});

test('an admin can list room types', function () {
    RoomType::create(['name' => 'A', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'admin-list@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/room-types')
        ->assertStatus(200);
});

test('an admin can view a room type', function () {
    $roomType = RoomType::create(['name' => 'View Me', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'admin-view@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(200);
});

test('an admin can update a room type', function () {
    $roomType = RoomType::create(['name' => 'Old Name', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'admin-update@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-types/{$roomType->id}", [
            'name' => 'New Name', 'base_price' => 120, 'capacity_adults' => 2, 'capacity_children' => 0,
        ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.name', 'New Name');
});

test('an admin can delete a room type (soft delete, not hard delete)', function () {
    $roomType = RoomType::create(['name' => 'Doomed', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'admin-delete@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(200);

    expect(RoomType::find($roomType->id))->toBeNull();
    expect(RoomType::withTrashed()->find($roomType->id))->not->toBeNull();
});

// --- Manager: create/update/delete ---

test('a manager can create a room type', function () {
    $token = loginAsEmployee($this, 'mgr-create@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Manager Room', 'base_price' => 150, 'capacity_adults' => 2, 'capacity_children' => 0,
        ])
        ->assertStatus(201);
});

test('a manager can update a room type', function () {
    $roomType = RoomType::create(['name' => 'Mgr Update Target', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'mgr-update@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-types/{$roomType->id}", [
            'name' => 'Mgr Updated', 'base_price' => 110, 'capacity_adults' => 1, 'capacity_children' => 0,
        ])
        ->assertStatus(200);
});

test('a manager can delete a room type', function () {
    $roomType = RoomType::create(['name' => 'Mgr Doomed', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'mgr-delete@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(200);
});

// --- Receptionist: view/list only ---

test('a receptionist can list and view room types', function () {
    $roomType = RoomType::create(['name' => 'Recep View', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'recep-view@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/room-types')
        ->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(200);
});

test('a receptionist cannot create a room type', function () {
    $token = loginAsEmployee($this, 'recep-create@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Recep Attempt', 'base_price' => 100, 'capacity_adults' => 1, 'capacity_children' => 0,
        ])
        ->assertStatus(403);
});

test('a receptionist cannot update a room type', function () {
    $roomType = RoomType::create(['name' => 'Recep Update Target', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'recep-update@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-types/{$roomType->id}", [
            'name' => 'Recep Updated', 'base_price' => 100, 'capacity_adults' => 1, 'capacity_children' => 0,
        ])
        ->assertStatus(403);
});

test('a receptionist cannot delete a room type', function () {
    $roomType = RoomType::create(['name' => 'Recep Doomed', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'recep-delete@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(403);
});

// --- Customer: management routes forbidden ---

test('a customer cannot access staff room-type management routes', function () {
    $token = loginAsCustomer($this, 'cust-roomtype@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/room-types')
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', ['name' => 'x'])
        ->assertStatus(403);
});

// --- Validation ---

test('creating a room type with a duplicate active name fails validation', function () {
    RoomType::create(['name' => 'Duplicate Name', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'admin-dup@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Duplicate Name', 'base_price' => 120, 'capacity_adults' => 1, 'capacity_children' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('a soft-deleted room type name CAN be reused by a new room type', function () {
    $original = RoomType::create(['name' => 'Reusable Name', 'base_price' => 100, 'capacity_adults' => 1]);
    $original->delete();

    $token = loginAsEmployee($this, 'admin-reuse@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Reusable Name', 'base_price' => 150, 'capacity_adults' => 2, 'capacity_children' => 0,
        ])
        ->assertStatus(201);
});

test('updating a room type to its own unchanged name does not trigger a false duplicate error', function () {
    $roomType = RoomType::create(['name' => 'Stays The Same', 'base_price' => 100, 'capacity_adults' => 1]);
    $token = loginAsEmployee($this, 'admin-selfupdate@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-types/{$roomType->id}", [
            'name' => 'Stays The Same', 'base_price' => 130, 'capacity_adults' => 2, 'capacity_children' => 0,
        ])
        ->assertStatus(200);
});

test('base_price cannot be negative', function () {
    $token = loginAsEmployee($this, 'admin-negprice@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Negative Price', 'base_price' => -10, 'capacity_adults' => 1, 'capacity_children' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('base_price');
});

test('capacity_adults must be at least 1', function () {
    $token = loginAsEmployee($this, 'admin-badadults@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Bad Adults', 'base_price' => 100, 'capacity_adults' => 0, 'capacity_children' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('capacity_adults');
});

test('capacity_children cannot be negative', function () {
    $token = loginAsEmployee($this, 'admin-badchildren@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Bad Children', 'base_price' => 100, 'capacity_adults' => 1, 'capacity_children' => -1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('capacity_children');
});

test('amenities must be a flat array of strings, not arbitrary nested structures', function () {
    $token = loginAsEmployee($this, 'admin-badamenities@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [
            'name' => 'Bad Amenities', 'base_price' => 100, 'capacity_adults' => 1, 'capacity_children' => 0,
            'amenities' => ['WiFi', ['nested' => 'not allowed']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amenities.1');
});

test('a malformed request missing required fields fails validation', function () {
    $token = loginAsEmployee($this, 'admin-malformed@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/room-types', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'base_price', 'capacity_adults', 'capacity_children']);
});

// --- Query: search / filter / sort / pagination ---

test('search filters room types by name', function () {
    RoomType::create(['name' => 'Ocean View', 'base_price' => 100, 'capacity_adults' => 1]);
    RoomType::create(['name' => 'Garden View', 'base_price' => 100, 'capacity_adults' => 1]);
    RoomType::create(['name' => 'City Suite', 'base_price' => 100, 'capacity_adults' => 1]);

    $response = $this->getJson('/api/v1/public/room-types?search=View');

    $response->assertJsonCount(2, 'data');
});

test('price range filters work', function () {
    RoomType::create(['name' => 'Cheap', 'base_price' => 50, 'capacity_adults' => 1]);
    RoomType::create(['name' => 'Mid', 'base_price' => 150, 'capacity_adults' => 1]);
    RoomType::create(['name' => 'Pricey', 'base_price' => 500, 'capacity_adults' => 1]);

    $response = $this->getJson('/api/v1/public/room-types?min_price=100&max_price=200');

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'Mid');
});

test('minimum adult and child capacity filters work', function () {
    RoomType::create(['name' => 'Small', 'base_price' => 100, 'capacity_adults' => 1, 'capacity_children' => 0]);
    RoomType::create(['name' => 'Big Family', 'base_price' => 100, 'capacity_adults' => 4, 'capacity_children' => 3]);

    $response = $this->getJson('/api/v1/public/room-types?min_adults=3&min_children=2');

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'Big Family');
});

test('sorting by an allowed field works', function () {
    RoomType::create(['name' => 'Z Room', 'base_price' => 300, 'capacity_adults' => 1]);
    RoomType::create(['name' => 'A Room', 'base_price' => 100, 'capacity_adults' => 1]);

    $response = $this->getJson('/api/v1/public/room-types?sort=base_price&direction=asc');

    $response->assertJsonPath('data.0.name', 'A Room');
});

test('an invalid sort field is rejected by validation, not silently passed through', function () {
    $response = $this->getJson('/api/v1/public/room-types?sort=' . urlencode('id; DROP TABLE room_types;--'));

    $response->assertStatus(422);
});

test('pagination respects a custom per_page and reports total correctly', function () {
    foreach (range(1, 5) as $i) {
        RoomType::create(['name' => "Room {$i}", 'base_price' => 100, 'capacity_adults' => 1]);
    }

    $response = $this->getJson('/api/v1/public/room-types?per_page=2');

    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('meta.total', 5);
    $response->assertJsonPath('meta.per_page', 2);
});

test('per_page above the safe maximum is rejected by validation', function () {
    $response = $this->getJson('/api/v1/public/room-types?per_page=9999');

    $response->assertStatus(422);
});

// --- Ownership / security ---

test('staff cannot view, update, or delete an already soft-deleted room type', function () {
    $roomType = RoomType::create(['name' => 'Already Gone', 'base_price' => 100, 'capacity_adults' => 1]);
    $roomType->delete();
    $token = loginAsEmployee($this, 'admin-deleted404@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(404);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-types/{$roomType->id}", [
            'name' => 'x', 'base_price' => 1, 'capacity_adults' => 1, 'capacity_children' => 0,
        ])
        ->assertStatus(404);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$roomType->id}")
        ->assertStatus(404);
});

test('unauthenticated requests to staff room-type routes are rejected with 401', function () {
    $this->getJson('/api/v1/staff/room-types')->assertStatus(401);
    $this->postJson('/api/v1/staff/room-types', [])->assertStatus(401);
});
