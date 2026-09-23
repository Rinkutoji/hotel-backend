<?php

use App\Models\Room;

// makeRoomType() is declared in tests/Pest.php (shared across all
// Room/RoomType test files in this directory).

test('a guest can list rooms without authentication', function () {
    $type = makeRoomType();
    Room::create(['room_number' => '101', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    Room::create(['room_number' => '102', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $response = $this->getJson('/api/v1/public/rooms');

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'data');
});

test('a guest can view a single room without authentication', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '201', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => makeBuilding()->id]);

    $response = $this->getJson("/api/v1/public/rooms/{$room->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('data.room_number', '201');
});

test('public room listing includes rooms of any status, not just available', function () {
    $type = makeRoomType();
    $availableRoom = Room::create(['room_number' => '301', 'room_type_id' => $type->id, 'floor' => 3, 'building_id' => makeBuilding()->id]);
    $maintenanceRoom = Room::create(['room_number' => '302', 'room_type_id' => $type->id, 'floor' => 3, 'building_id' => makeBuilding()->id]);
    $maintenanceRoom->status = 'maintenance';
    $maintenanceRoom->save();

    $response = $this->getJson('/api/v1/public/rooms');

    // Both rooms appear - the previous version of this test only ever
    // created one room and checked a count of 1, which would have
    // passed even if the maintenance room had been silently excluded
    // and only counted by coincidence. This version proves both an
    // available and a non-available room are present together, and
    // that the non-available room's real status is intact in the
    // response, not hidden or overwritten.
    $response->assertJsonCount(2, 'data');

    $roomNumbers = collect($response->json('data'))->pluck('room_number')->all();
    expect($roomNumbers)->toContain('301', '302');

    $maintenanceEntry = collect($response->json('data'))->firstWhere('room_number', '302');
    expect($maintenanceEntry['status'])->toBe('maintenance');
});

test('a soft-deleted room is excluded from the public list', function () {
    $type = makeRoomType();
    Room::create(['room_number' => '401', 'room_type_id' => $type->id, 'floor' => 4, 'building_id' => makeBuilding()->id]);
    $deleted = Room::create(['room_number' => '402', 'room_type_id' => $type->id, 'floor' => 4, 'building_id' => makeBuilding()->id]);
    $deleted->delete();

    $response = $this->getJson('/api/v1/public/rooms');

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.room_number', '401');
});

test('viewing a soft-deleted room directly returns 404', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => '501', 'room_type_id' => $type->id, 'floor' => 5, 'building_id' => makeBuilding()->id]);
    $room->delete();

    $this->getJson("/api/v1/public/rooms/{$room->id}")->assertStatus(404);
});
