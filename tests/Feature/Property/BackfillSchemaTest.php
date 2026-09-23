<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Hotel Location/Property - migration backfill and schema guarantees
|--------------------------------------------------------------------------
|
| Permanently locks the behavior of the 2025_03_01 location migrations:
|
|   000003 add_building_id_to_rooms_table  (backfill + NOT NULL +
|                                           unique(building_id, room_number))
|   000004 add_property_id_to_employees_table (receptionist backfill)
|   000005 add_location_analytics_indexes  (composite indexes)
|
| The Property/Building/Room Eloquent models belong to Phase 2 tasks and
| must not exist yet, so every fixture here is planted with raw
| DB::table(...) inserts and every invariant is read back with DB::table
| or Schema introspection. That exercises the REAL database constraints
| and the migrations' data steps rather than Eloquent assumptions.
|
| Coverage map (user's 14 required items):
|   1  Main Hotel exists with code MAIN ............ Test 3 (and Test 1)
|   2  Main Building exists with code MAIN ......... Test 3 (and Test 1)
|   3  Every room incl. soft-deleted backfilled ... Test 1
|   4  Room numbers unchanged ...................... Test 1
|   5  rooms.building_id NOT NULL .................. Test 1
|   6  Room number unique within a building ........ Test 1
|   7  Same number allowed across buildings ........ Test 1
|   8  Duplicate within one building rejected ...... Test 1
|   9  Building code unique within a property ...... Test 3
|   10 Same code allowed across properties ......... Test 3
|   11 Receptionists assigned to Main Hotel ........ Test 2
|   12 Admin/manager stay property_id NULL ......... Test 2
|   13 Analytics composites exist .................. Test 3
|   14 Unrelated pre-feature data intact ........... Test 1
|
| Round-trip pattern: app('migrator')->rollback([$path]) / ->run([$path])
| on the exact migration paths with pre-feature rows planted in between.
| Artisan::call('migrate:fresh') is NEVER used - it fails inside
| RefreshDatabase's SQLite transaction (VACUUM).
|
| NOTE on Test 1: migration 000005 indexes rooms.building_id. SQLite's
| native ALTER TABLE ... DROP COLUMN refuses to drop an indexed column
| (proven empirically - it throws "error in index
| rooms_building_id_status_index after drop column"), and SQLite has no
| DROP CONSTRAINT. A real production rollback therefore always removes
| 000005 before 000003 (migrate:rollback --step runs in reverse order),
| which is exactly what Test 1 does: rollback 000005 first, then the
| 000003 round-trip.
*/

// --- Test 1: rooms migration round-trip --------------------------------
// Items 3, 4, 5, 6, 7, 8, 14 (plus 1 and 2 as intermediate assertions).

test('rooms migration round-trip: backfill, NOT NULL, per-building uniqueness, unrelated data intact', function () {
    $migrator = app('migrator');
    $p3 = database_path('migrations/2025_03_01_000003_add_building_id_to_rooms_table.php');
    $p5 = database_path('migrations/2025_03_01_000005_add_location_analytics_indexes.php');

    // See the file header: 000005's composite index on rooms.building_id
    // blocks 000003's down() on SQLite, so the composites go first - the
    // same order a real `migrate:rollback --step` would use.
    $migrator->rollback([$p5]);
    $migrator->rollback([$p3]); // exercises down() on sqlite
    expect(Schema::hasColumn('rooms', 'building_id'))->toBeFalse();

    // Pre-feature data: one room type, an active room, a soft-deleted room.
    $typeId = DB::table('room_types')->insertGetId([
        'name' => 'Backfill RT', 'base_price' => 100.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('rooms')->insert([
        'room_number' => '101', 'room_type_id' => $typeId, 'floor' => 1,
        'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('rooms')->insert([
        'room_number' => '102', 'room_type_id' => $typeId, 'floor' => 2,
        'status' => 'available', 'deleted_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Unrelated pre-feature rows (item 14) - snapshotted BEFORE the re-run.
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Backfill Schema Customer', 'email' => 'backfill-schema@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = DB::table('bookings')->insertGetId([
        'booking_number' => 'T6-BK-1', 'customer_id' => $customerId,
        'check_in_date' => '2026-10-01', 'check_out_date' => '2026-10-03',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $paymentId = DB::table('payments')->insertGetId([
        'booking_id' => $bookingId, 'amount' => 100.00, 'method' => 'bank_transfer',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_number' => 'T6-INV-1', 'payment_id' => $paymentId, 'total_amount' => 100.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $unrelatedBefore = [
        'customer' => (array) DB::table('customers')->where('id', $customerId)->first(),
        'booking' => (array) DB::table('bookings')->where('id', $bookingId)->first(),
        'payment' => (array) DB::table('payments')->where('id', $paymentId)->first(),
        'invoice' => (array) DB::table('invoices')->where('id', $invoiceId)->first(),
    ];

    $migrator->run([$p3]); // exercises up() including the data step

    // Items 1 + 2: the data step creates Main Hotel + Main Building.
    $mainProperty = DB::table('properties')->where('code', 'MAIN')->first();
    expect($mainProperty)->not->toBeNull();
    $mainBuilding = DB::table('buildings')
        ->where('property_id', $mainProperty->id)
        ->where('code', 'MAIN')
        ->first();
    expect($mainBuilding)->not->toBeNull();

    // Item 3: EVERY room - the soft-deleted one included - has a home.
    // DB::table() has no global scopes, so '102' (deleted_at set) is visible.
    foreach (['101', '102'] as $number) {
        expect(DB::table('rooms')->where('room_number', $number)->value('building_id'))
            ->toBe($mainBuilding->id, "Room {$number} should have been backfilled to the MAIN building");
    }

    // Item 4: room numbers are unchanged by the migration.
    expect(DB::table('rooms')->orderBy('room_number')->pluck('room_number')->all())
        ->toBe(['101', '102']);

    // Item 5: building_id is NOT NULL - at the column level AND enforced
    // on direct inserts.
    $buildingColumn = collect(Schema::getColumns('rooms'))->firstWhere('name', 'building_id');
    expect($buildingColumn['nullable'])->toBeFalse();
    expect(fn () => DB::table('rooms')->insert([
        'room_number' => '103', 'room_type_id' => $typeId, 'floor' => 3,
        'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Items 6/7/8: room_number is unique PER BUILDING, not globally.
    $secondBuildingId = DB::table('buildings')->insertGetId([
        'property_id' => $mainProperty->id, 'name' => 'Second Building', 'code' => 'B2',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Item 7: the same number in a DIFFERENT building is allowed.
    DB::table('rooms')->insert([
        'room_number' => '101', 'room_type_id' => $typeId, 'floor' => 1,
        'building_id' => $secondBuildingId, 'status' => 'available',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Items 6 + 8: the same number in the SAME building is rejected
    // (a '101' already lives in the MAIN building from the backfill).
    expect(fn () => DB::table('rooms')->insert([
        'room_number' => '101', 'room_type_id' => $typeId, 'floor' => 1,
        'building_id' => $mainBuilding->id, 'status' => 'available',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Item 14: the unrelated rows are untouched.
    expect((array) DB::table('customers')->where('id', $customerId)->first())->toBe($unrelatedBefore['customer']);
    expect((array) DB::table('bookings')->where('id', $bookingId)->first())->toBe($unrelatedBefore['booking']);
    expect((array) DB::table('payments')->where('id', $paymentId)->first())->toBe($unrelatedBefore['payment']);
    expect((array) DB::table('invoices')->where('id', $invoiceId)->first())->toBe($unrelatedBefore['invoice']);

    // Fail-safe rollback with rows present: down() deletes nothing. The
    // planted rooms (including the soft-deleted one and the second-building
    // duplicate number) survive with unchanged room numbers; the column
    // itself is gone.
    $migrator->rollback([$p3]);
    expect(Schema::hasColumn('rooms', 'building_id'))->toBeFalse();
    expect(DB::table('rooms')->count())->toBe(3);
    expect(DB::table('rooms')->orderBy('room_number')->pluck('room_number')->all())
        ->toBe(['101', '101', '102']);
    expect(DB::table('rooms')->where('room_number', '102')->value('deleted_at'))->not->toBeNull();

    expect((array) DB::table('customers')->where('id', $customerId)->first())->toBe($unrelatedBefore['customer']);
    expect((array) DB::table('bookings')->where('id', $bookingId)->first())->toBe($unrelatedBefore['booking']);
    expect((array) DB::table('payments')->where('id', $paymentId)->first())->toBe($unrelatedBefore['payment']);
    expect((array) DB::table('invoices')->where('id', $invoiceId)->first())->toBe($unrelatedBefore['invoice']);
});

// --- Test 2: employees migration round-trip ----------------------------
// Items 11 and 12, plus the guarantee that down() deletes nothing.

test('employees migration round-trip: receptionists backfilled to MAIN, admin/manager stay NULL, down() deletes nothing', function () {
    $migrator = app('migrator');
    $p4 = database_path('migrations/2025_03_01_000004_add_property_id_to_employees_table.php');

    $migrator->rollback([$p4]); // exercises down() on sqlite
    expect(Schema::hasColumn('employees', 'property_id'))->toBeFalse();

    $ids = [];
    foreach ([
        'receptionist' => 'backfill-receptionist@example.com',
        'admin' => 'backfill-admin@example.com',
        'manager' => 'backfill-manager@example.com',
    ] as $role => $email) {
        $ids[$role] = DB::table('employees')->insertGetId([
            'name' => 'Backfill '.ucfirst($role), 'email' => $email, 'password' => 'x',
            'role' => $role, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migrator->run([$p4]); // exercises up() including the data step

    // Item 11: pre-existing receptionists are assigned to Main Hotel.
    // (MAIN property exists - created by 000003's data step during the
    // initial full migration of this test's database.)
    $mainPropertyId = DB::table('properties')->where('code', 'MAIN')->value('id');
    expect($mainPropertyId)->not->toBeNull();
    expect(DB::table('employees')->where('id', $ids['receptionist'])->value('property_id'))
        ->toBe($mainPropertyId);

    // Item 12: admin and manager remain property-agnostic (NULL).
    expect(DB::table('employees')->where('id', $ids['admin'])->value('property_id'))->toBeNull();
    expect(DB::table('employees')->where('id', $ids['manager'])->value('property_id'))->toBeNull();

    // Rollback with rows present: every employee row survives with
    // unchanged name/email/role/password; only the column disappears.
    $before = [];
    foreach ($ids as $role => $id) {
        $before[$role] = (array) DB::table('employees')->where('id', $id)->first();
    }

    $migrator->rollback([$p4]);
    expect(Schema::hasColumn('employees', 'property_id'))->toBeFalse();
    expect(DB::table('employees')->count())->toBe(3);

    foreach ($ids as $role => $id) {
        $row = (array) DB::table('employees')->where('id', $id)->first();
        expect($row['name'])->toBe($before[$role]['name']);
        expect($row['email'])->toBe($before[$role]['email']);
        expect($row['role'])->toBe($before[$role]['role']);
        expect($row['password'])->toBe($before[$role]['password']);
    }
});

// --- Test 3: end-state schema invariants -------------------------------
// Items 1, 2, 9, 10, 13 on a fresh fully-migrated database (no rollback).

test('end-state schema invariants: MAIN hotel/building exist, analytics composites exist, building codes unique per property', function () {
    // Item 1: Main Hotel exists with code MAIN (created by 000003's data
    // step even in an otherwise empty database).
    $mainProperty = DB::table('properties')->where('code', 'MAIN')->first();
    expect($mainProperty)->not->toBeNull();
    expect($mainProperty->name)->toBe('Main Hotel');

    // Item 2: exactly ONE Main Building exists under Main Hotel.
    $mainBuildings = DB::table('buildings')
        ->where('property_id', $mainProperty->id)
        ->where('code', 'MAIN')
        ->get();
    expect($mainBuildings)->toHaveCount(1);
    expect($mainBuildings->first()->name)->toBe('Main Building');

    // Item 13: the analytics composites exist with the exact ordered
    // columns. Column names are normalized the same way the Task 3/5
    // migrations do it (trim/strtolower/array-cast) for SQLite's casing.
    $hasIndex = fn (string $table, array $columns) => collect(Schema::getIndexes($table))->contains(
        fn (array $index) => array_map(
            fn ($column) => trim(strtolower((string) $column)),
            (array) ($index['columns'] ?? [])
        ) === $columns
    );

    expect($hasIndex('rooms', ['building_id', 'status']))->toBeTrue();
    expect($hasIndex('buildings', ['property_id', 'status']))->toBeTrue();

    // Item 9: a duplicate building code inside the SAME property is
    // rejected ('MAIN' already exists under Main Hotel).
    expect(fn () => DB::table('buildings')->insert([
        'property_id' => $mainProperty->id, 'name' => 'Duplicate Code', 'code' => 'MAIN',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Item 10: the SAME building code in a DIFFERENT property is allowed.
    $secondPropertyId = DB::table('properties')->insertGetId([
        'name' => 'Second', 'code' => 'SECOND', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('buildings')->insert([
        'property_id' => $secondPropertyId, 'name' => 'Second Main', 'code' => 'MAIN',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(DB::table('buildings')->where('property_id', $secondPropertyId)->where('code', 'MAIN')->count())
        ->toBe(1);
});
