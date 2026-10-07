<?php

use App\Models\Building;

// Task 41: end-to-end HTTP proof of building CRUD (spec §7.1).
// Production behavior shipped in T24-T26 (BuildingController + routes);
// this file locks it in over HTTP.

beforeEach(function () {
    $this->adminToken = loginAsEmployee($this, 'bcr-admin@example.com', 'admin');
    $this->managerToken = loginAsEmployee($this, 'bcr-mgr@example.com', 'manager');
    $this->propertyA = makeProperty('BCR-A', 'Hotel A');
    $this->propertyB = makeProperty('BCR-B', 'Hotel B');
});

test('admin can create a building under a property', function () {
    $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/staff/properties/{$this->propertyA->id}/buildings", [
            'name' => 'Tower One',
            'code' => 'T1',
            'floor_count' => 12,
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Building created.')
        ->assertJsonPath('data.property_id', $this->propertyA->id)
        ->assertJsonPath('data.code', 'T1')
        ->assertJsonPath('data.floor_count', 12);

    $this->assertDatabaseHas('buildings', [
        'property_id' => $this->propertyA->id,
        'code' => 'T1',
        'floor_count' => 12,
    ]);
});

test('building code must be unique within a property', function () {
    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/staff/properties/{$this->propertyA->id}/buildings", ['name' => 'Wing', 'code' => 'W1'])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/staff/properties/{$this->propertyA->id}/buildings", ['name' => 'Wing 2', 'code' => 'W1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'The code has already been taken.');
});

test('the same building code is allowed in a different property', function () {
    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/staff/properties/{$this->propertyA->id}/buildings", ['name' => 'Shared', 'code' => 'SH1'])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/staff/properties/{$this->propertyB->id}/buildings", ['name' => 'Shared Too', 'code' => 'SH1'])
        ->assertCreated();
});

test('manager cannot update or delete a building', function () {
    $building = Building::create([
        'property_id' => $this->propertyA->id,
        'name' => 'Locked Tower',
        'code' => 'LOCK',
    ]);

    $this->withHeader('Authorization', "Bearer {$this->managerToken}")
        ->putJson("/api/v1/staff/buildings/{$building->id}", ['name' => 'Nope', 'code' => 'LOCK'])
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$this->managerToken}")
        ->deleteJson("/api/v1/staff/buildings/{$building->id}")
        ->assertForbidden();

    $this->assertNotSoftDeleted('buildings', ['id' => $building->id]);
});

test('admin can update and delete a building', function () {
    $building = Building::create([
        'property_id' => $this->propertyA->id,
        'name' => 'Locked Tower',
        'code' => 'LOCK',
    ]);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->putJson("/api/v1/staff/buildings/{$building->id}", ['name' => 'Renamed Tower', 'code' => 'LOCK'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Tower');

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->deleteJson("/api/v1/staff/buildings/{$building->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Building deleted.');

    $this->assertSoftDeleted('buildings', ['id' => $building->id]);
});

test('the nested building list returns the property buildings', function () {
    Building::create(['property_id' => $this->propertyA->id, 'name' => 'A-One', 'code' => 'A1']);
    Building::create(['property_id' => $this->propertyA->id, 'name' => 'A-Two', 'code' => 'A2']);
    Building::create(['property_id' => $this->propertyB->id, 'name' => 'B-One', 'code' => 'B1']);

    $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/staff/properties/{$this->propertyA->id}/buildings")
        ->assertOk();

    expect(collect($res->json('data'))->pluck('code')->all())
        ->toContain('A1')
        ->toContain('A2')
        ->not->toContain('B1');
});
