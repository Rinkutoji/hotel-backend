<?php

use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomStatusLog;

// --- Valid transitions ---

test('a room can transition from available to maintenance', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T1', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-t1@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance", ['reason' => 'AC broken']);

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'maintenance');
});

test('a room can transition from maintenance back to available', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T2', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->status = 'maintenance';
    $room->save();
    $token = loginAsEmployee($this, 'admin-t2@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/available");

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'available');
});

test('a room can transition from cleaning to maintenance', function () {
    // A room can't yet reach "cleaning" through any real API call
    // (that's checkout-driven, not implemented) - status is set
    // directly here to test the transition RULE itself, independent of
    // how the starting state might be reached. See
    // docs/STEP-07-ROOM-PLAN.md §2.
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T3', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->status = 'cleaning';
    $room->save();
    $token = loginAsEmployee($this, 'admin-t3@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance");

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'maintenance');
});

// --- Invalid transitions ---

test('a room cannot transition from available directly to occupied - no such endpoint exists', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T4', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-t4@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/occupied")
        ->assertStatus(404);
});

test('the available endpoint rejects a room that is not currently in maintenance', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T5', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    // Room starts 'available' already - calling ".../status/available"
    // again is not a valid transition (there's no available->available
    // rule), and importantly, occupied/reserved/cleaning also can't
    // reach 'available' through this endpoint.
    $room->status = 'occupied';
    $room->save();
    $token = loginAsEmployee($this, 'admin-t5@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/available");

    $response->assertStatus(409);
});

test('the maintenance endpoint rejects a room that is occupied or reserved', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T6', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->status = 'occupied';
    $room->save();
    $token = loginAsEmployee($this, 'admin-t6@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance")
        ->assertStatus(409);
});

// --- Audit log ---

test('a status transition creates a room_status_logs entry with the expected fields', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T7', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-t7@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance", ['reason' => 'Leaking pipe']);

    $log = RoomStatusLog::where('room_id', $room->id)->first();

    expect($log)->not->toBeNull();
    expect($log->from_status)->toBe('available');
    expect($log->to_status)->toBe('maintenance');
    expect($log->reason)->toBe('Leaking pipe');
    expect($log->changed_by)->not->toBeNull();
    expect($log->created_at)->not->toBeNull();
});

test('changed_by records the actual employee who made the change', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T8', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'admin-t8@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance");

    $log = RoomStatusLog::where('room_id', $room->id)->first();
    $employee = Employee::where('email', 'admin-t8@example.com')->first();

    expect($log->changed_by)->toBe($employee->id);
});

test('an invalid transition attempt does not create a log entry', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T9', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $room->status = 'occupied';
    $room->save();
    $token = loginAsEmployee($this, 'admin-t9@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance");

    expect(RoomStatusLog::where('room_id', $room->id)->count())->toBe(0);
});

// --- Role permissions for status changes (broader than CRUD) ---

test('a receptionist CAN change room status, unlike create/update/delete', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T10', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'recep-status@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance")
        ->assertStatus(200);
});

test('a manager can change room status', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T11', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsEmployee($this, 'mgr-status@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance")
        ->assertStatus(200);
});

test('a customer cannot change room status', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T12', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);
    $token = loginAsCustomer($this, 'cust-status@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance")
        ->assertStatus(403);
});

test('an unauthenticated request to a status-change route is rejected with 401', function () {
    $type = makeRoomType();
    $room = Room::create(['room_number' => 'T13', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => makeBuilding()->id]);

    $this->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance")->assertStatus(401);
});
