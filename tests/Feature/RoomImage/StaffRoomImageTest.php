<?php

use App\Models\RoomImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// --- Upload: role permissions ---

test('an admin can upload a room type image', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-upload@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg', 800, 600),
        ]);

    $response->assertStatus(201);
    expect(RoomImage::where('room_type_id', $type->id)->count())->toBe(1);
});

test('a manager can upload a room type image', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'mgr-upload@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.png', 800, 600),
        ])
        ->assertStatus(201);
});

test('a receptionist cannot upload a room type image', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'recep-upload@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ])
        ->assertStatus(403);
});

test('a customer cannot upload a room type image', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsCustomer($this, 'cust-upload@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ])
        ->assertStatus(403);
});

test('an unauthenticated guest cannot upload a room type image', function () {
    Storage::fake('public');
    $type = makeRoomType();

    $this->post("/api/v1/staff/room-types/{$type->id}/images", [
        'image' => UploadedFile::fake()->image('room.jpg'),
    ])->assertStatus(401);
});

// --- Upload: validation ---

test('a non-image file is rejected', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-badfile@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->create('malicious.php', 100, 'application/x-php'),
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('image');
});

test('a file with a valid but unapproved image format (gif) is rejected', function () {
    // Genuinely valid GIF data (passes the bare 'image' rule) but not
    // in the mimes:jpg,jpeg,png,webp allowlist - proves the allowlist
    // is doing real work, not just relying on the generic 'image' rule.
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-gif@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.gif'),
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('image');
});

test('an oversized image is rejected', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-oversized@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('big.jpg')->size(6000),
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('image');
});

test('a request with no file at all is rejected', function () {
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-nofile@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/room-types/{$type->id}/images", [])
        ->assertStatus(422);
});

test('uploading to a soft-deleted room type returns 404', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $type->delete();
    $token = loginAsEmployee($this, 'admin-deletedtype-upload@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ])
        ->assertStatus(404);
});

// --- Storage behavior ---

test('a successful upload actually stores the file on the public disk', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-storecheck@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ]);

    $image = RoomImage::first();
    Storage::disk('public')->assertExists($image->path);
});

test('a successful upload creates a database record associated with the room type', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-dbcheck@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ]);

    $image = RoomImage::first();
    expect($image->room_type_id)->toBe($type->id);
});

test('the returned url points at the stored file, not a raw filesystem path', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-urlcheck@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ]);

    $image = RoomImage::first();
    expect($response->json('data.url'))->toBe(Storage::disk('public')->url($image->path));
    $response->assertJsonMissingPath('data.path');
});

test('deleting an image removes the physical file from storage', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $token = loginAsEmployee($this, 'admin-deletefile@example.com', 'admin');

    $uploadResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/staff/room-types/{$type->id}/images", [
            'image' => UploadedFile::fake()->image('room.jpg'),
        ]);

    $image = RoomImage::first();
    Storage::disk('public')->assertExists($image->path);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$image->id}")
        ->assertStatus(200);

    Storage::disk('public')->assertMissing($image->path);
    expect(RoomImage::find($image->id))->toBeNull();
});

test('deleting an image whose physical file is already missing does not crash', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $image = RoomImage::create([
        'room_type_id' => $type->id, 'path' => 'room-types/1/already-gone.jpg', 'sort_order' => 1,
    ]);
    // Deliberately never actually written to the fake disk - simulates
    // a file that's already missing for any reason.
    $token = loginAsEmployee($this, 'admin-missingfile@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$image->id}");

    $response->assertStatus(200);
    expect(RoomImage::find($image->id))->toBeNull();
});

// --- CRUD ---

test('any staff role can list a room type\'s images', function () {
    $type = makeRoomType();
    RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'recep-list@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/room-types/{$type->id}/images")
        ->assertStatus(200);
});

test('an admin can update an image\'s sort_order', function () {
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'admin-sort@example.com', 'admin');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-images/{$image->id}", ['sort_order' => 5]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.sort_order', 5);
    expect($image->fresh()->sort_order)->toBe(5);
});

test('a manager can update an image\'s sort_order', function () {
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'mgr-sort@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-images/{$image->id}", ['sort_order' => 2])
        ->assertStatus(200);
});

test('a receptionist cannot update an image\'s sort_order', function () {
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'recep-sort@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-images/{$image->id}", ['sort_order' => 2])
        ->assertStatus(403);
});

test('an admin can delete an image', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'admin-del@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$image->id}")
        ->assertStatus(200);
});

test('a manager can delete an image', function () {
    Storage::fake('public');
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'mgr-del@example.com', 'manager');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$image->id}")
        ->assertStatus(200);
});

test('a receptionist cannot delete an image', function () {
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'recep-del@example.com', 'receptionist');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$image->id}")
        ->assertStatus(403);

    expect(RoomImage::find($image->id))->not->toBeNull();
});

test('a customer cannot delete an image', function () {
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);
    $token = loginAsCustomer($this, 'cust-del@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$image->id}")
        ->assertStatus(403);
});

// --- Data isolation between room types ---

test('updating one room type\'s image does not affect another room type\'s images', function () {
    $typeA = makeRoomType('Type A');
    $typeB = makeRoomType('Type B');
    $imageA = RoomImage::create(['room_type_id' => $typeA->id, 'path' => 'a/1.jpg', 'sort_order' => 1]);
    $imageB = RoomImage::create(['room_type_id' => $typeB->id, 'path' => 'b/1.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'admin-isolation-update@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/room-images/{$imageA->id}", ['sort_order' => 99]);

    expect($imageB->fresh()->sort_order)->toBe(1);
});

test('deleting one room type\'s image does not affect another room type\'s images', function () {
    Storage::fake('public');
    $typeA = makeRoomType('Type C');
    $typeB = makeRoomType('Type D');
    $imageA = RoomImage::create(['room_type_id' => $typeA->id, 'path' => 'c/1.jpg', 'sort_order' => 1]);
    $imageB = RoomImage::create(['room_type_id' => $typeB->id, 'path' => 'd/1.jpg', 'sort_order' => 1]);
    $token = loginAsEmployee($this, 'admin-isolation-delete@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/room-images/{$imageA->id}");

    expect(RoomImage::find($imageB->id))->not->toBeNull();
});

// --- Security ---

test('unauthenticated requests to staff image management routes are rejected with 401', function () {
    $type = makeRoomType();
    $image = RoomImage::create(['room_type_id' => $type->id, 'path' => 'x/a.jpg', 'sort_order' => 1]);

    $this->getJson("/api/v1/staff/room-types/{$type->id}/images")->assertStatus(401);
    $this->putJson("/api/v1/staff/room-images/{$image->id}", ['sort_order' => 2])->assertStatus(401);
    $this->deleteJson("/api/v1/staff/room-images/{$image->id}")->assertStatus(401);
});
