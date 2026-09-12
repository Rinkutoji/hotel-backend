<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// --- Migrations run, and only the approved tables exist ---

test('all 10 approved tables exist after migration', function () {
    $tables = [
        'departments', 'customers', 'employees', 'room_types', 'rooms',
        'room_images', 'bookings', 'booking_rooms', 'payments', 'room_status_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table [$table] to exist");
    }
});

test('the default Laravel users/cache/jobs tables were intentionally removed', function () {
    expect(Schema::hasTable('users'))->toBeFalse();
    expect(Schema::hasTable('cache'))->toBeFalse();
    expect(Schema::hasTable('jobs'))->toBeFalse();
});

// --- Required fields ---

test('customers requires name, email, and password', function () {
    expect(fn () => DB::table('customers')->insert([
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('bookings requires check_in_date and check_out_date', function () {
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Required Fields Test', 'email' => 'reqfields@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('bookings')->insert([
        'booking_number' => 'BK-REQ-1', 'customer_id' => $customerId,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// --- Unique constraints ---

test('customers.email must be unique', function () {
    DB::table('customers')->insert([
        'name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('customers')->insert([
        'name' => 'Alice 2', 'email' => 'alice@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// rooms.room_number has NO database-level UNIQUE constraint - it was
// intentionally dropped in migration 2025_02_04_000001 so that a
// soft-deleted room's number can be reused by a new active room.
// Uniqueness among active rows is enforced at the validation layer
// (StoreRequest / UpdateRequest) via Rule::unique()->whereNull('deleted_at').
test('rooms.room_number has no DB-level unique constraint (soft-delete friendly)', function () {
    $typeId = DB::table('room_types')->insertGetId([
        'name' => 'Standard', 'base_price' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('rooms')->insert([
        'room_number' => '101', 'room_type_id' => $typeId, 'floor' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A second row with the same room_number should NOT throw at the DB
    // level - no UNIQUE index exists any more.
    DB::table('rooms')->insert([
        'room_number' => '101', 'room_type_id' => $typeId, 'floor' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('rooms')->where('room_number', '101')->count())->toBe(2);
});

test('payments.booking_id must be unique - one payment per booking', function () {
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $bookingId = DB::table('bookings')->insertGetId([
        'booking_number' => 'BK-1', 'customer_id' => $customerId,
        'check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDay()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('payments')->insert([
        'booking_id' => $bookingId, 'amount' => 100, 'method' => 'cash',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('payments')->insert([
        'booking_id' => $bookingId, 'amount' => 50, 'method' => 'cash',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('booking_rooms prevents the same room being added twice to the same booking', function () {
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $typeId = DB::table('room_types')->insertGetId([
        'name' => 'Deluxe', 'base_price' => 200,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $roomId = DB::table('rooms')->insertGetId([
        'room_number' => '201', 'room_type_id' => $typeId, 'floor' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = DB::table('bookings')->insertGetId([
        'booking_number' => 'BK-2', 'customer_id' => $customerId,
        'check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDay()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('booking_rooms')->insert([
        'booking_id' => $bookingId, 'room_id' => $roomId, 'room_type_id' => $typeId,
        'price_per_night' => 200, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('booking_rooms')->insert([
        'booking_id' => $bookingId, 'room_id' => $roomId, 'room_type_id' => $typeId,
        'price_per_night' => 200, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// --- Foreign key enforcement ---

test('rooms cannot reference a non-existent room_type_id', function () {
    expect(fn () => DB::table('rooms')->insert([
        'room_number' => '999', 'room_type_id' => 999999, 'floor' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a room cannot be deleted while it has booking history (restrictOnDelete)', function () {
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Dan', 'email' => 'dan@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $typeId = DB::table('room_types')->insertGetId([
        'name' => 'Suite', 'base_price' => 300,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $roomId = DB::table('rooms')->insertGetId([
        'room_number' => '301', 'room_type_id' => $typeId, 'floor' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = DB::table('bookings')->insertGetId([
        'booking_number' => 'BK-3', 'customer_id' => $customerId,
        'check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDay()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('booking_rooms')->insert([
        'booking_id' => $bookingId, 'room_id' => $roomId, 'room_type_id' => $typeId,
        'price_per_night' => 300, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('rooms')->where('id', $roomId)->delete())
        ->toThrow(QueryException::class);
});

test('deleting a department is blocked while an employee references it (restrictOnDelete)', function () {
    $deptId = DB::table('departments')->insertGetId([
        'name' => 'Housekeeping', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('employees')->insert([
        'name' => 'Housekeeper', 'email' => 'housekeeper@example.com', 'password' => 'x',
        'role' => 'receptionist', 'department_id' => $deptId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('departments')->where('id', $deptId)->delete())
        ->toThrow(QueryException::class);
});

// --- Nullable fields ---

test('customers.phone and address are optional', function () {
    $id = DB::table('customers')->insertGetId([
        'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('customers')->where('id', $id)->value('phone'))->toBeNull();
});

test('employees.department_id is optional', function () {
    $id = DB::table('employees')->insertGetId([
        'name' => 'No Department Yet', 'email' => 'nodept@example.com', 'password' => 'x',
        'role' => 'admin', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('employees')->where('id', $id)->value('department_id'))->toBeNull();
});

// --- Relationships ---

test('an employee correctly relates to its department', function () {
    $deptId = DB::table('departments')->insertGetId([
        'name' => 'Front Desk', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $empId = DB::table('employees')->insertGetId([
        'name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'x',
        'role' => 'receptionist', 'department_id' => $deptId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $found = DB::table('employees')->where('id', $empId)->first();
    expect($found->department_id)->toBe($deptId);
});

test('a booking correctly relates to multiple rooms via booking_rooms', function () {
    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $typeId = DB::table('room_types')->insertGetId([
        'name' => 'Family', 'base_price' => 250,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $room1 = DB::table('rooms')->insertGetId([
        'room_number' => '401', 'room_type_id' => $typeId, 'floor' => 4,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $room2 = DB::table('rooms')->insertGetId([
        'room_number' => '402', 'room_type_id' => $typeId, 'floor' => 4,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = DB::table('bookings')->insertGetId([
        'booking_number' => 'BK-4', 'customer_id' => $customerId,
        'check_in_date' => now()->toDateString(), 'check_out_date' => now()->addDays(2)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([$room1, $room2] as $roomId) {
        DB::table('booking_rooms')->insert([
            'booking_id' => $bookingId, 'room_id' => $roomId, 'room_type_id' => $typeId,
            'price_per_night' => 250, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('booking_rooms')->where('booking_id', $bookingId)->count())->toBe(2);
});

// --- Soft deletes (only where the architecture calls for them) ---

test('customers are soft-deleted, not hard-deleted', function () {
    $id = DB::table('customers')->insertGetId([
        'name' => 'Henry', 'email' => 'henry@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('customers')->where('id', $id)->update(['deleted_at' => now()]);

    expect(DB::table('customers')->where('id', $id)->exists())->toBeTrue();
    expect(DB::table('customers')->where('id', $id)->value('deleted_at'))->not->toBeNull();
});

test('rooms, employees, and room_types have soft-delete columns', function () {
    expect(Schema::hasColumn('rooms', 'deleted_at'))->toBeTrue();
    expect(Schema::hasColumn('employees', 'deleted_at'))->toBeTrue();
    expect(Schema::hasColumn('room_types', 'deleted_at'))->toBeTrue();
});

test('departments and room_images do not soft-delete - hard delete only', function () {
    expect(Schema::hasColumn('departments', 'deleted_at'))->toBeFalse();
    expect(Schema::hasColumn('room_images', 'deleted_at'))->toBeFalse();
});

test('bookings and payments do not soft-delete - lifecycle is tracked via status instead', function () {
    expect(Schema::hasColumn('bookings', 'deleted_at'))->toBeFalse();
    expect(Schema::hasColumn('payments', 'deleted_at'))->toBeFalse();
});

test('room_status_logs has created_at but no updated_at - append-only', function () {
    expect(Schema::hasColumn('room_status_logs', 'created_at'))->toBeTrue();
    expect(Schema::hasColumn('room_status_logs', 'updated_at'))->toBeFalse();
});
