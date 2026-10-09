<?php

use App\Models\Room;

// --- Admin / Manager: create ---

test('an admin can create a room', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-create@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', [
            'room_number' => '101', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id,
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.room_number', '101');
    $response->assertJsonPath('data.status', 'available');
    expect(Room::where('room_number', '101')->exists())->toBeTrue();
});

test('a manager can create a room', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'mgr-create@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', [
            'room_number' => '102', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id,
        ])
        ->assertStatus(201);
});

test('a receptionist cannot create a room', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'recep-create@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', [
            'room_number' => '103', 'room_type_id' => $type->id, 'floor' => 1,
        ])
        ->assertStatus(403);
});

test('a customer cannot access any staff room management route', function () {
    $type = makeRoomType();
    $token = loginAsCustomer($this, 'cust-room@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/rooms')
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'x', 'room_type_id' => $type->id, 'floor' => 1])
        ->assertStatus(403);
});

// --- Update / Delete ---

test('an admin can update a room', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '201', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-update@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/rooms/{$room->id}", [
            'room_number' => '202', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id,
        ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.room_number', '202');
});

test('a manager can update a room', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '203', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'mgr-update@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/rooms/{$room->id}", [
            'room_number' => '204', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id,
        ])
        ->assertStatus(200);
});

test('a receptionist cannot update a room', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '205', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'recep-update@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/rooms/{$room->id}", [
            'room_number' => '206', 'room_type_id' => $type->id, 'floor' => 2,
        ])
        ->assertStatus(403);
});

test('an admin can delete a room (soft delete, not hard delete)', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '301', 'room_type_id' => $type->id, 'floor' => 3, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-delete@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/rooms/{$room->id}")
        ->assertStatus(200);

    expect(Room::find($room->id))->toBeNull();
    expect(Room::withTrashed()->find($room->id))->not->toBeNull();
});

test('a manager can delete a room', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '302', 'room_type_id' => $type->id, 'floor' => 3, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'mgr-delete@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/rooms/{$room->id}")
        ->assertStatus(200);
});

test('a receptionist cannot delete a room', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '303', 'room_type_id' => $type->id, 'floor' => 3, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'recep-delete@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/rooms/{$room->id}")
        ->assertStatus(403);
});

// --- Validation ---

test('creating a room with a duplicate active room_number fails validation', function () {
    $type = makeRoomType();
    Room::create(['room_number' => 'DUP-1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-dup@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'DUP-1', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('room_number');
});

test('a soft-deleted room_number can be reused in a different building but not the same building', function () {
    $type = makeRoomType();
    $building = makeBuilding();
    $original = Room::create(['room_number' => 'REUSE-1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $building->id]);
    $original->delete();

    $token = loginAsEmployee($this, 'admin-reuse@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'REUSE-1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $building->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('room_number');

    $westBuilding = makeBuilding('WEST');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'REUSE-1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $westBuilding->id])
        ->assertStatus(201)
        ->assertJsonPath('data.building_id', $westBuilding->id);
});

test('an active room_number can be reused in a different building', function () {
    $type = makeRoomType();
    Room::create(['room_number' => 'ACT-1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-activereuse@example.com', 'admin');

    $westBuilding = makeBuilding('WEST');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'ACT-1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $westBuilding->id])
        ->assertStatus(201)
        ->assertJsonPath('data.building_id', $westBuilding->id);
});

test('creating a room with a non-existent room_type_id fails validation', function () {
    $token = loginAsEmployee($this, 'admin-badtype@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'X1', 'room_type_id' => 999999, 'floor' => 1, 'building_id' => makeBuilding()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('room_type_id');
});

test('a room cannot be created referencing a soft-deleted room type', function () {
    $type = makeRoomType('Deleted Type');
    $type->delete();
    $token = loginAsEmployee($this, 'admin-deletedtype@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'X2', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('room_type_id');
});

test('floor must be a positive integer within a reasonable range', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-badfloor@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'X3', 'room_type_id' => $type->id, 'floor' => 0, 'building_id' => makeBuilding()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('floor');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'X4', 'room_type_id' => $type->id, 'floor' => -1, 'building_id' => makeBuilding()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('floor');
});

test('creating a room without a building_id fails validation', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-nobuilding@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'NB1', 'room_type_id' => $type->id, 'floor' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('building_id');
});

test('creating a room with a nonexistent building_id fails validation', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-badbuilding@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'NB2', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('building_id');
});

test('creating a room with a soft-deleted building_id fails validation', function () {
    $type = makeRoomType();
    $building = makeBuilding('DOOMED');
    $building->delete();
    $token = loginAsEmployee($this, 'admin-deletedbuilding@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'NB3', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $building->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('building_id');
});

test('a client-supplied status field is silently ignored on create - always starts available', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-statusinject-create@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', [
            'room_number' => 'X5', 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'occupied', 'building_id' => makeBuilding()->id,
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.status', 'available');
});

test('a client-supplied status field is silently ignored on update - no arbitrary status change via PUT', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'X6', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-statusinject-update@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/rooms/{$room->id}", [
            'room_number' => 'X6', 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'occupied', 'building_id' => makeBuilding()->id,
        ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'available');
    expect($room->fresh()->status)->toBe('available');
});

// --- Query: search / filter / sort / pagination ---

test('search filters rooms by room_number', function () {
    $type = makeRoomType();
    Room::create(['room_number' => 'A100', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'A200', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'B100', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $response = $this->getJson('/api/v1/public/rooms?search=A');

    $response->assertJsonCount(2, 'data');
});

test('filtering by room_type_id works', function () {
    $typeA = makeRoomType('Type A');
    $typeB = makeRoomType('Type B');
    Room::create(['room_number' => 'F1', 'room_type_id' => $typeA->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'F2', 'room_type_id' => $typeB->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $response = $this->getJson("/api/v1/public/rooms?room_type_id={$typeA->id}");

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.room_number', 'F1');
});

test('filtering by floor works', function () {
    $type = makeRoomType();
    Room::create(['room_number' => 'G1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'G2', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id]);

    $response = $this->getJson('/api/v1/public/rooms?floor=2');

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.room_number', 'G2');
});

test('filtering by status works', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'H1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'H2', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->status = 'maintenance';
    $room->save();

    $response = $this->getJson('/api/v1/public/rooms?status=maintenance');

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.room_number', 'H1');
    $response->assertJsonPath('data.0.status', 'maintenance');
});

test('sorting by an allowed field works', function () {
    $type = makeRoomType();
    Room::create(['room_number' => 'Z1', 'room_type_id' => $type->id, 'floor' => 9, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'A1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $response = $this->getJson('/api/v1/public/rooms?sort=floor&direction=asc');

    $response->assertJsonPath('data.0.room_number', 'A1');
});

test('an invalid sort field is rejected by validation', function () {
    $response = $this->getJson('/api/v1/public/rooms?sort='.urlencode('id; DROP TABLE rooms;--'));

    $response->assertStatus(422);
});

test('pagination respects a custom per_page and reports total correctly', function () {
    $type = makeRoomType();
    foreach (range(1, 5) as $i) {
        Room::create(['room_number' => "P{$i}", 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    }

    $response = $this->getJson('/api/v1/public/rooms?per_page=2');

    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('meta.total', 5);
});

test('per_page above the safe maximum is rejected by validation', function () {
    $this->getJson('/api/v1/public/rooms?per_page=9999')->assertStatus(422);
});

// --- Ownership / security ---

test('staff cannot view, update, or delete an already soft-deleted room', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'GONE1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->delete();
    $token = loginAsEmployee($this, 'admin-deleted404@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/rooms/{$room->id}")
        ->assertStatus(404);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/rooms/{$room->id}", ['room_number' => 'x', 'room_type_id' => $type->id, 'floor' => 1])
        ->assertStatus(404);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/rooms/{$room->id}")
        ->assertStatus(404);
});

test('unauthenticated requests to staff room routes are rejected with 401', function () {
    $this->getJson('/api/v1/staff/rooms')->assertStatus(401);
    $this->postJson('/api/v1/staff/rooms', [])->assertStatus(401);
});
