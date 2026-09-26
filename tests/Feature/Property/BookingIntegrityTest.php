<?php

use App\Models\Building;
use App\Models\Property;
use App\Models\Room;
use Carbon\Carbon;

// Task 22: end-to-end HTTP proof of the booking property-integrity rules
// (spec §7.4; Task 19 behavior). The backend derives every room's chain
// Room -> Building -> Property from the LOCKED DB rows - request claims
// never override the rows. Covered here:
//   - 422 branches (ValidationException): mixed-property room set (key
//     'rooms'), claimed property_id mismatch (key 'property_id'), claimed
//     building_id mismatch (key 'building_id');
//   - 409 branches (BusinessRuleException): inactive property chain,
//     inactive building chain, soft-deleted building chain, soft-deleted
//     property chain;
//   - one 201 control (same-property booking with a MATCHING claim);
//   - availability: active-chain exclusion (no property filter) + the
//     property filter, which is written with full assertions but chained
//     with ->skip(...) - PublicBookingController::availability does not
//     pass property_id to searchAvailable yet (wired in Task 29; un-skip
//     obligation is ledger-tracked).

function bookingPayload22(Room $roomA, ?Room $roomB = null, array $claims = []): array
{
    $checkIn = Carbon::today()->addDay();

    $payload = [
        'check_in_date' => $checkIn->format('Y-m-d'),
        'check_out_date' => $checkIn->copy()->addDays(2)->format('Y-m-d'),
        'rooms' => $roomB === null ? [$roomA->id] : [$roomA->id, $roomB->id],
    ];

    return $payload + $claims;
}

beforeEach(function () {
    // MAIN chain (helpers return the migration-seeded rows)
    $this->mainRoom = makeBookingRoom('BI1', makeRoomType('BI-M')->id);

    // WEST chain (active)
    $this->westProperty = makeProperty('WEST', 'West Hotel');
    $this->westBuilding = makeBuilding('WEST-B', $this->westProperty->id);
    $this->westRoom = Room::create([
        'room_number' => 'BI2', 'room_type_id' => makeRoomType('BI-W')->id,
        'floor' => 1, 'building_id' => $this->westBuilding->id,
    ]);

    // Inactive-building chain (MAIN property, building inactive)
    $this->inactiveBuilding = Building::create([
        'property_id' => makeProperty()->id,
        'name' => 'Inactive Building', 'code' => 'BI-INACT-B', 'status' => 'inactive',
    ]);
    $this->inactiveBuildingRoom = Room::create([
        'room_number' => 'BI3', 'room_type_id' => makeRoomType('BI-IB')->id,
        'floor' => 1, 'building_id' => $this->inactiveBuilding->id,
    ]);

    // Inactive-property chain (property inactive, building active)
    $this->inactiveProperty = Property::create([
        'name' => 'Inactive Hotel', 'code' => 'BI-INACT-P', 'status' => 'inactive',
    ]);
    $this->inactivePropertyBuilding = Building::create([
        'property_id' => $this->inactiveProperty->id, 'name' => 'B under Inactive P',
        'code' => 'BI-IP-B', 'status' => 'active',
    ]);
    $this->inactivePropertyRoom = Room::create([
        'room_number' => 'BI4', 'room_type_id' => makeRoomType('BI-IP')->id,
        'floor' => 1, 'building_id' => $this->inactivePropertyBuilding->id,
    ]);

    // Soft-deleted building chain
    $this->trashedBuilding = Building::create([
        'property_id' => makeProperty()->id, 'name' => 'Trashed Building',
        'code' => 'BI-TRASH-B', 'status' => 'active',
    ]);
    $this->trashedBuilding->delete();
    $this->trashedBuildingRoom = Room::create([
        'room_number' => 'BI5', 'room_type_id' => makeRoomType('BI-TB')->id,
        'floor' => 1, 'building_id' => $this->trashedBuilding->id,
    ]);

    // Soft-deleted property chain (building active, property trashed)
    $this->trashedProperty = Property::create([
        'name' => 'Trashed Hotel', 'code' => 'BI-TRASH-P', 'status' => 'active',
    ]);
    $this->trashedPropertyBuilding = Building::create([
        'property_id' => $this->trashedProperty->id, 'name' => 'B under Trashed P',
        'code' => 'BI-TP-B', 'status' => 'active',
    ]);
    $this->trashedProperty->delete();
    $this->trashedPropertyRoom = Room::create([
        'room_number' => 'BI6', 'room_type_id' => makeRoomType('BI-TP')->id,
        'floor' => 1, 'building_id' => $this->trashedPropertyBuilding->id,
    ]);

    $this->token = loginAsCustomer($this, 'bi-customer@example.com');
});

test('a mixed-property room set is rejected with 422', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22($this->mainRoom, $this->westRoom))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonValidationErrors('rooms');
});

test('a claimed property_id that mismatches the locked rooms is rejected with 422', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22(
            $this->westRoom,
            claims: ['property_id' => $this->mainRoom->building->property_id],
        ))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonValidationErrors('property_id');
});

test('a claimed building_id that mismatches the locked rooms is rejected with 422', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22(
            $this->westRoom,
            claims: ['building_id' => $this->mainRoom->building_id],
        ))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonValidationErrors('building_id');
});

test('booking a room of an inactive property chain is rejected with 409', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22($this->inactivePropertyRoom))
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('booking a room of an inactive building chain is rejected with 409', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22($this->inactiveBuildingRoom))
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('booking a room of a soft-deleted building chain is rejected with 409', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22($this->trashedBuildingRoom))
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('booking a room of a soft-deleted property chain is rejected with 409', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22($this->trashedPropertyRoom))
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('a valid same-property booking with a matching property claim still succeeds', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload22(
            $this->mainRoom,
            claims: ['property_id' => $this->mainRoom->building->property_id],
        ))
        ->assertStatus(201);
});

test('availability excludes rooms of inactive and soft-deleted chains without a property filter', function () {
    $checkIn = Carbon::today()->addDay();

    $response = postAvailability([
        'check_in_date' => $checkIn->format('Y-m-d'),
        'check_out_date' => $checkIn->copy()->addDays(2)->format('Y-m-d'),
        'rooms' => [
            $this->mainRoom->id,
            $this->inactiveBuildingRoom->id,
            $this->inactivePropertyRoom->id,
            $this->trashedBuildingRoom->id,
            $this->trashedPropertyRoom->id,
        ],
    ]);

    $response->assertOk();

    $roomIds = collect($response->json('data'))->pluck('id');
    expect($roomIds)->toContain($this->mainRoom->id);
    expect($roomIds)->not->toContain($this->inactiveBuildingRoom->id);
    expect($roomIds)->not->toContain($this->inactivePropertyRoom->id);
    expect($roomIds)->not->toContain($this->trashedBuildingRoom->id);
    expect($roomIds)->not->toContain($this->trashedPropertyRoom->id);
});

test('availability without property_id returns rooms from active chains', function () {
    $checkIn = Carbon::today()->addDay();

    $response = postAvailability([
        'check_in_date' => $checkIn->format('Y-m-d'),
        'check_out_date' => $checkIn->copy()->addDays(2)->format('Y-m-d'),
        'rooms' => [$this->mainRoom->id, $this->westRoom->id],
    ]);

    $response->assertOk();

    $roomIds = collect($response->json('data'))->pluck('id');
    expect($roomIds)->toContain($this->mainRoom->id);
    expect($roomIds)->toContain($this->westRoom->id);
});

test('availability property filter returns only the claimed property rooms', function () {
    $checkIn = Carbon::today()->addDay();

    $mainFiltered = postAvailability([
        'check_in_date' => $checkIn->format('Y-m-d'),
        'check_out_date' => $checkIn->copy()->addDays(2)->format('Y-m-d'),
        'rooms' => [$this->mainRoom->id, $this->westRoom->id],
        'property_id' => $this->mainRoom->building->property_id,
    ]);

    $mainFiltered->assertOk();

    $mainIds = collect($mainFiltered->json('data'))->pluck('id');
    expect($mainIds)->toContain($this->mainRoom->id);
    expect($mainIds)->not->toContain($this->westRoom->id);

    $westFiltered = postAvailability([
        'check_in_date' => $checkIn->format('Y-m-d'),
        'check_out_date' => $checkIn->copy()->addDays(2)->format('Y-m-d'),
        'rooms' => [$this->mainRoom->id, $this->westRoom->id],
        'property_id' => $this->westProperty->id,
    ]);

    $westFiltered->assertOk();

    $westIds = collect($westFiltered->json('data'))->pluck('id');
    expect($westIds)->toContain($this->westRoom->id);
    expect($westIds)->not->toContain($this->mainRoom->id);
})->skip('T29: Public BookingController must pass property_id to searchAvailable (currently ignored)');
