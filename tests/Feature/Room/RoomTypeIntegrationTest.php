<?php

use App\Models\Room;
use App\Models\RoomType;

test('a room correctly belongs to its room type', function () {
    $type = makeRoomType('Deluxe');
    $room = Room::create(['room_number' => 'R1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    expect($room->roomType->id)->toBe($type->id);
    expect($type->rooms()->first()->id)->toBe($room->id);
});

test('a room cannot be created referencing a soft-deleted room type', function () {
    $type = makeRoomType('Retired Type');
    $type->delete();

    $token = loginAsEmployee($this, 'admin-retiredtype@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/rooms', ['room_number' => 'R2', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('room_type_id');
});

test('THE HEADLINE RULE: a room type with active rooms cannot be soft-deleted', function () {
    $type = makeRoomType('Has Rooms');
    Room::create(['room_number' => 'R3', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $token = loginAsEmployee($this, 'admin-hasrooms@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$type->id}");

    $response->assertStatus(409);
    expect(RoomType::find($type->id))->not->toBeNull();
});

test('a room type whose only room has been soft-deleted CAN then be deleted', function () {
    $type = makeRoomType('Emptied Out');
    $room = Room::create(['room_number' => 'R4', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->delete();

    $token = loginAsEmployee($this, 'admin-emptied@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$type->id}");

    $response->assertStatus(200);
    expect(RoomType::find($type->id))->toBeNull();
});

test('a room type with multiple active rooms is still blocked from deletion', function () {
    $type = makeRoomType('Multi Room Type');
    Room::create(['room_number' => 'R5', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => 'R6', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $token = loginAsEmployee($this, 'admin-multirooms@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$type->id}")
        ->assertStatus(409);
});

test('a manager is also blocked by the deletion guard, not just admin', function () {
    $type = makeRoomType('Manager Blocked');
    Room::create(['room_number' => 'R7', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $token = loginAsEmployee($this, 'mgr-blocked@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-types/{$type->id}")
        ->assertStatus(409);
});
