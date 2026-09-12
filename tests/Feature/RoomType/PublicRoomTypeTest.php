<?php

use App\Models\RoomType;

test('a guest can list room types without authentication', function () {
    RoomType::create(['name' => 'Standard', 'base_price' => 100, 'capacity_adults' => 2]);
    RoomType::create(['name' => 'Deluxe', 'base_price' => 200, 'capacity_adults' => 2]);

    $response = $this->getJson('/api/v1/public/room-types');

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'data');
});

test('a guest can view a single room type without authentication', function () {
    $roomType = RoomType::create(['name' => 'Family Room', 'base_price' => 150, 'capacity_adults' => 4]);

    $response = $this->getJson("/api/v1/public/room-types/{$roomType->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('data.name', 'Family Room');
});

test('a soft-deleted room type is excluded from the public list', function () {
    RoomType::create(['name' => 'Active Room', 'base_price' => 100, 'capacity_adults' => 2]);
    $deleted = RoomType::create(['name' => 'Deleted Room', 'base_price' => 100, 'capacity_adults' => 2]);
    $deleted->delete();

    $response = $this->getJson('/api/v1/public/room-types');

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'Active Room');
});

test('viewing a soft-deleted room type directly returns 404', function () {
    $roomType = RoomType::create(['name' => 'Gone Room', 'base_price' => 100, 'capacity_adults' => 2]);
    $roomType->delete();

    $this->getJson("/api/v1/public/room-types/{$roomType->id}")->assertStatus(404);
});
