<?php

use App\Models\RoomImage;

test('a guest can list a room type\'s images without authentication', function () {
    $type = makeRoomType();
    RoomImage::create(['room_type_id' => $type->id, 'path' => 'room-types/1/a.jpg', 'sort_order' => 1]);
    RoomImage::create(['room_type_id' => $type->id, 'path' => 'room-types/1/b.jpg', 'sort_order' => 2]);

    $response = $this->getJson("/api/v1/public/room-types/{$type->id}/images");

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'data');
});

test('images are returned in sort_order ascending, then id ascending as a tie-breaker', function () {
    $type = makeRoomType();
    $third = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/c.jpg', 'sort_order' => 5]);
    $firstTie = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $secondTie = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/b.jpg', 'sort_order' => 1]);

    $response = $this->getJson("/api/v1/public/room-types/{$type->id}/images");

    $ids = collect($response->json('data'))->pluck('id')->all();

    // Both sort_order=1 images come before sort_order=5, and between
    // the two tied at sort_order=1, the lower id ($firstTie) comes
    // first.
    expect($ids)->toBe([$firstTie->id, $secondTie->id, $third->id]);
});

test('the raw storage path is never exposed - only a computed url', function () {
    $type = makeRoomType();
    RoomImage::create(['room_type_id' => $type->id, 'path' => 'room-types/1/secret-path.jpg', 'sort_order' => 1]);

    $response = $this->getJson("/api/v1/public/room-types/{$type->id}/images");

    $response->assertJsonMissingPath('data.0.path');
    expect($response->json('data.0.url'))->toContain('secret-path.jpg');
});

test('listing images for a soft-deleted room type returns 404', function () {
    $type = makeRoomType();
    RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $type->delete();

    $this->getJson("/api/v1/public/room-types/{$type->id}/images")->assertStatus(404);
});

test('listing images for a non-existent room type returns 404', function () {
    $this->getJson('/api/v1/public/room-types/999999/images')->assertStatus(404);
});
