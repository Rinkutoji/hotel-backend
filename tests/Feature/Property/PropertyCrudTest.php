<?php

use App\Models\Property;
use Illuminate\Testing\TestResponse;

// Task 41: end-to-end HTTP proof of the staff property CRUD surface
// (spec §7.1) plus the public selector contract (spec §7.2). Production
// behavior shipped in T23-T26 (PropertyService, controllers, routes);
// this file locks it in over HTTP. PropertyServiceTest covers the
// service-level search/filter logic directly - here the filters are
// asserted end-to-end (IndexRequest validation included).

function pcrPostProperty(string $token, array $payload): TestResponse
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/properties', $payload);
}

beforeEach(function () {
    $this->adminToken = loginAsEmployee($this, 'pcr-admin@example.com', 'admin');
    $this->managerToken = loginAsEmployee($this, 'pcr-mgr@example.com', 'manager');
    $this->receptionistToken = loginAsEmployee($this, 'pcr-recep@example.com', 'receptionist');
});

test('admin can create a property with all fields persisted', function () {
    $res = pcrPostProperty($this->adminToken, [
        'name' => 'Seaside Resort',
        'code' => 'SEA',
        'description' => 'A resort by the sea',
        'address' => '1 Ocean Drive',
        'city' => 'Miami',
        'province_state' => 'FL',
        'country' => 'US',
        'postal_code' => '33101',
        'latitude' => 12.3456,
        'longitude' => -98.7654,
        'phone' => '+1-555-0100',
        'email' => 'stay@seaside.test',
        'website' => 'https://seaside.test',
        'check_in_time' => '14:00',
        'check_out_time' => '11:00',
        'timezone' => 'America/New_York',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $res->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Property created.')
        ->assertJsonPath('data.name', 'Seaside Resort')
        ->assertJsonPath('data.code', 'SEA')
        ->assertJsonPath('data.city', 'Miami')
        ->assertJsonPath('data.latitude', '12.345600')
        ->assertJsonPath('data.longitude', '-98.765400')
        ->assertJsonPath('data.currency', 'USD');

    $this->assertDatabaseHas('properties', [
        'code' => 'SEA',
        'city' => 'Miami',
        'province_state' => 'FL',
    ]);
});

test('a null currency falls back to the database default', function () {
    $res = pcrPostProperty($this->adminToken, [
        'name' => 'No Currency Hotel',
        'code' => 'NOCUR',
        'currency' => null,
    ]);

    $res->assertCreated()
        ->assertJsonPath('data.currency', 'USD');

    $this->assertDatabaseHas('properties', [
        'code' => 'NOCUR',
        'currency' => 'USD',
    ]);
});

test('property code must be unique', function () {
    pcrPostProperty($this->adminToken, ['name' => 'Unique One', 'code' => 'UNIQ'])
        ->assertCreated();

    pcrPostProperty($this->adminToken, ['name' => 'Unique Two', 'code' => 'UNIQ'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'The code has already been taken.');
});

test('latitude and longitude bounds are enforced', function () {
    $base = ['name' => 'Bounds', 'code' => 'BNDS'];

    pcrPostProperty($this->adminToken, $base + ['latitude' => 91])
        ->assertUnprocessable();

    pcrPostProperty($this->adminToken, $base + ['longitude' => 181])
        ->assertUnprocessable();

    pcrPostProperty($this->adminToken, $base + ['latitude' => 'abc'])
        ->assertUnprocessable();
});

test('email and website must be well-formed', function () {
    $base = ['name' => 'Contact Info', 'code' => 'CNTC'];

    pcrPostProperty($this->adminToken, $base + ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email field must be a valid email address.');

    pcrPostProperty($this->adminToken, $base + ['website' => 'not-a-url'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.website.0', 'The website field must be a valid URL.');
});

test('list search matches name', function () {
    Property::create(['name' => 'Alpha Hotel', 'code' => 'ALPHA', 'status' => 'active']);
    Property::create(['name' => 'Beta Lodge', 'code' => 'BETA', 'status' => 'active']);

    $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties?search=beta')
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toContain(Property::where('code', 'BETA')->value('id'))
        ->not->toContain(Property::where('code', 'ALPHA')->value('id'));
});

test('list search matches city', function () {
    Property::create(['name' => 'Downtown Suites', 'code' => 'DTWN', 'city' => 'Austin', 'status' => 'active']);
    Property::create(['name' => 'Lakeside Inn', 'code' => 'LAKE', 'city' => 'Duluth', 'status' => 'active']);

    $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties?search=austin')
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toContain(Property::where('code', 'DTWN')->value('id'))
        ->not->toContain(Property::where('code', 'LAKE')->value('id'));
});

test('list search covers code, province and country fields', function () {
    Property::create(['name' => 'Code Lodge', 'code' => 'CDLG', 'status' => 'active']);
    Property::create(['name' => 'Province House', 'code' => 'PRVH', 'province_state' => 'Tuscany', 'status' => 'active']);
    Property::create(['name' => 'Country House', 'code' => 'CTRY', 'country' => 'Italy', 'status' => 'active']);

    $byCode = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties?search=CDLG')->assertOk();
    expect(collect($byCode->json('data'))->pluck('id')->all())
        ->toContain(Property::where('code', 'CDLG')->value('id'));

    $byProvince = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties?search=tuscany')->assertOk();
    expect(collect($byProvince->json('data'))->pluck('id')->all())
        ->toContain(Property::where('code', 'PRVH')->value('id'));

    $byCountry = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties?search=italy')->assertOk();
    expect(collect($byCountry->json('data'))->pluck('id')->all())
        ->toContain(Property::where('code', 'CTRY')->value('id'));
});

test('list status filter returns subsets', function () {
    Property::create(['name' => 'Active House', 'code' => 'ACTV', 'status' => 'active']);
    Property::create(['name' => 'Closed House', 'code' => 'CLSD', 'status' => 'inactive']);

    $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties?status=inactive')
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toContain(Property::where('code', 'CLSD')->value('id'))
        ->not->toContain(Property::where('code', 'ACTV')->value('id'));
});

test('admin can update a property', function () {
    $property = Property::create(['name' => 'Old Name', 'code' => 'UPD1', 'status' => 'active']);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->putJson("/api/v1/staff/properties/{$property->id}", [
            'name' => 'New Name',
            'code' => 'UPD1',
            'city' => 'Portland',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Property updated.')
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.city', 'Portland');

    $this->assertDatabaseHas('properties', [
        'id' => $property->id,
        'name' => 'New Name',
        'city' => 'Portland',
    ]);
});

test('deleting a property soft-deletes it and the list omits it', function () {
    $property = Property::create(['name' => 'Doomed', 'code' => 'DOOM', 'status' => 'active']);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->deleteJson("/api/v1/staff/properties/{$property->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Property deleted.');

    $this->assertSoftDeleted('properties', ['id' => $property->id]);

    $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson('/api/v1/staff/properties')
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->not->toContain($property->id);
});

test('manager cannot create a property', function () {
    pcrPostProperty($this->managerToken, ['name' => 'Nope', 'code' => 'NOPE'])
        ->assertForbidden();
});

test('receptionist cannot create a property', function () {
    pcrPostProperty($this->receptionistToken, ['name' => 'Nope', 'code' => 'NOPE2'])
        ->assertForbidden();
});

test('receptionist list returns only their own property', function () {
    $west = Property::create(['name' => 'West Hotel', 'code' => 'WEST', 'status' => 'active']);

    $res = $this->withHeader('Authorization', "Bearer {$this->receptionistToken}")
        ->getJson('/api/v1/staff/properties')
        ->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())
        ->toBe([makeProperty()->id])
        ->not->toContain($west->id);
});

test('public list returns only active properties', function () {
    Property::create(['name' => 'Visible Hotel', 'code' => 'VIS', 'status' => 'active']);
    Property::create(['name' => 'Hidden Hotel', 'code' => 'HID', 'status' => 'inactive']);
    $doomed = Property::create(['name' => 'Ghost Hotel', 'code' => 'GHST', 'status' => 'active']);
    $doomed->delete();

    $res = $this->getJson('/api/v1/public/properties')
        ->assertOk()
        ->assertJsonPath('message', 'OK');

    expect(collect($res->json('data'))->pluck('code')->all())
        ->toContain('VIS')
        ->not->toContain('HID')
        ->not->toContain('GHST');
});
