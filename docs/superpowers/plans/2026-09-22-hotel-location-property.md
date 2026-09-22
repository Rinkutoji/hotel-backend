# Hotel Location / Property Management — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add multi-property (Property → Building → Floor → Room) management to the existing hotel system: properties/buildings CRUD, property-scoped rooms/bookings/payments, property filters on dashboard/analytics/reports (server-side SQL), a public property selector, and receptionist property isolation — without breaking any existing functionality.

**Architecture:** Two new tables (`properties`, `buildings`) with `rooms.building_id` (NOT NULL after backfill) and nullable `employees.property_id`. Property is always derived through Room → Building → Property; no redundant columns on bookings/payments/invoices/customers. A single `PropertyScope` resolver computes the effective property filter per request (receptionists are hard-scoped server-side; admin/manager get optional filters). All aggregation filters are SQL `whereHas`/joins — never client-side.

**Tech Stack:** Laravel 11 (PHP 8.2, Pest, Pint), MySQL dev DB `hotel_management_v2` + SQLite test DB, React 19 + Vite frontend (`hotel-frontend`, Vitest, oxlint, axios, Tailwind). **No new composer or npm packages.**

**Spec:** `docs/superpowers/specs/2026-09-22-hotel-location-property-design.md` — the plan argues from the spec; executors read both.

## Global Constraints

- Do NOT rebuild, replace, or demo-ify anything; this extends the existing app.
- Do NOT add `property_id`/`building_id` to bookings, payments, invoices, or customers.
- Do NOT create a Floor table/model; `rooms.floor` stays a string column.
- Do NOT change RoomType architecture (stays global).
- Do NOT introduce new auth systems, composer/npm dependencies, or unrelated business-logic changes.
- Receptionists: assigned property only, enforced server-side in SQL; request params can never widen scope.
- `room_number` uniqueness: `UNIQUE(building_id, room_number)`; never globally unique again.
- Existing production data must survive: rooms backfilled to Main Hotel (`MAIN`) / Main Building; receptionists backfilled to Main Hotel.
- Two-layer auth convention: `role:*` route middleware + policy re-check in controller. ApiResponse envelope everywhere.
- All money stays decimal:2 / cents-integer; no float drift. Keep the cross-engine SQL helpers (SQLite tests vs MySQL runtime).
- Dev DB gotcha: after new migrations, run `php artisan migrate` against `hotel_management_v2` before browser smoke tests.
- Frontend: keep the lint-clean fetch pattern (no setState-in-effect; pure fetchData + `.then()` callbacks; params via `useSearchParams`).

## File Structure (created / modified)

**Backend — created:**
- `database/migrations/2025_03_01_000001_create_properties_table.php`
- `database/migrations/2025_03_01_000002_create_buildings_table.php`
- `database/migrations/2025_03_01_000003_add_building_id_to_rooms_table.php`
- `database/migrations/2025_03_01_000004_add_property_id_to_employees_table.php`
- `database/migrations/2025_03_01_000005_add_location_analytics_indexes.php`
- `app/Models/Property.php`, `app/Models/Building.php`
- `app/Support/PropertyScope.php`
- `app/Services/PropertyService.php`
- `app/Policies/PropertyPolicy.php`, `app/Policies/BuildingPolicy.php`
- `app/Http/Controllers/Api/V1/Staff/PropertyController.php`
- `app/Http/Controllers/Api/V1/Staff/BuildingController.php`
- `app/Http/Controllers/Api/V1/Public/PropertyController.php`
- `app/Http/Requests/Property/StoreRequest.php`, `UpdateRequest.php`, `IndexRequest.php`
- `app/Http/Requests/Building/StoreRequest.php`, `UpdateRequest.php`
- Tests: `tests/Feature/Property/PropertyCrudTest.php`, `BuildingCrudTest.php`, `ReceptionistScopingTest.php`, `BookingIntegrityTest.php`, `BackfillSchemaTest.php`, `AnalyticsFilterTest.php`, `ReportFilterTest.php`

**Backend — modified:**
- `app/Models/Room.php`, `Employee.php`, `Booking.php`, `Payment.php`, `Invoice.php`
- `app/Policies/RoomPolicy.php`, `BookingPolicy.php`, `PaymentPolicy.php`
- `app/Services/RoomService.php`, `BookingService.php`, `AnalyticsService.php`, `ReportService.php`
- `app/Http/Requests/Room/StoreRequest.php`, `UpdateRequest.php`, `IndexRequest.php`
- `app/Http/Requests/Employee/StoreRequest.php`, `UpdateRequest.php`
- `app/Http/Requests/Booking/CreateBookingRequest.php`, `AvailabilityRequest.php`, `FilterRequest.php`
- `app/Http/Requests/Payment/FilterRequest.php`, `Invoice/FilterRequest.php`, `Calendar/BookingsRequest.php`
- `app/Http/Requests/Dashboard/IndexRequest.php`, `Analytics/IndexRequest.php`
- `app/Http/Requests/Report/ReportWindowRequest.php`, `ReportExportRequest.php`, `BookingsRequest.php`, `RevenueRequest.php`, `RoomsRequest.php`
- `app/Http/Controllers/Api/V1/Staff/RoomController.php`, `BookingController.php`, `PaymentController.php`, `InvoiceController.php`, `CalendarController.php`, `EmployeeController.php`, `AnalyticsController.php`, `DashboardController.php`, `ReportController.php`, `RoomStatusController.php`
- `app/Http/Controllers/Api/V1/Public/RoomController.php`, `BookingController.php`
- `routes/api.php`
- `tests/Pest.php` (helpers) + every existing test file that creates rooms directly (grep `Room::create(`)

**Frontend — created (`/Applications/XAMPP/xamppfiles/htdocs/hotel-frontend/src/`):**
- `api/propertyApi.js`
- `hooks/useProperties.js`, `hooks/usePropertyDetail.js`
- `pages/staff/Properties.jsx`, `pages/staff/PropertyForm.jsx`, `pages/staff/PropertyDetail.jsx`, `pages/staff/BuildingForm.jsx`
- `test/propertyApi.test.js`, `test/staffProperties.test.jsx`, `test/staffPropertyForm.test.jsx`, `test/staffPropertyDetail.test.jsx`, `test/staffBuildingForm.test.jsx`, `test/propertyScopeUi.test.jsx`

**Frontend — modified:**
- `components/staff/Sidebar.jsx`, `routes/StaffApp.jsx`
- `pages/staff/RoomForm.jsx`, `pages/staff/Rooms.jsx`, `components/staff/RoomFilters.jsx`
- `components/staff/DashboardOverview.jsx`, `hooks/useDashboardOverview.js`, `api/dashboardApi.js`
- `pages/staff/Analytics.jsx`, `hooks/useAnalytics.js`, `components/staff/analytics/AnalyticsDateFilter.jsx` (if filter row lives there)
- `components/staff/ReportFilters.jsx`, `pages/staff/Reports.jsx`, `hooks/useReportSummary.js`
- `pages/customer/AvailabilitySearch.jsx`, `pages/customer/Rooms.jsx`, `pages/customer/BookingCheckout.jsx`
- Existing tests: `test/staffRoomForm.test.jsx`, `test/staffRooms.test.jsx`, `test/staffDashboard.test.jsx`, `test/reportsSection.test.jsx`, `test/useAnalytics.test.jsx`, `test/roomApi.test.js`, `test/availabilitySearch.test.jsx`, `test/bookingCheckout.test.jsx`

**Deleted:** nothing (no file deletions needed anywhere).

---

# PHASE 1 — Backend database

### Task 1: Create `properties` table

**Files:**
- Create: `database/migrations/2025_03_01_000001_create_properties_table.php`

**What:** New migration (numbered after `2025_02_20_000002_add_analytics_indexes.php`). Schema per spec §4.1:

```php
Schema::create('properties', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('code')->unique();
    $table->text('description')->nullable();
    $table->string('address')->nullable();
    $table->string('city')->nullable();
    $table->string('province_state')->nullable();
    $table->string('country')->nullable();
    $table->string('postal_code', 20)->nullable();
    $table->decimal('latitude', 9, 6)->nullable();
    $table->decimal('longitude', 9, 6)->nullable();
    $table->string('phone', 20)->nullable();
    $table->string('email')->nullable();
    $table->string('website')->nullable();
    $table->time('check_in_time')->nullable();
    $table->time('check_out_time')->nullable();
    $table->string('timezone', 64)->nullable();
    $table->char('currency', 3)->default('USD');
    $table->enum('status', ['active', 'inactive'])->default('active');
    $table->timestamps();
    $table->softDeletes();

    $table->index('name');
    $table->index('city');
    $table->index('province_state');
    $table->index('country');
    $table->index('status');
});
```

`down()`: `Schema::dropIfExists('properties');`

**Why:** First table of the hierarchy; lat/lng are typed decimals so they can never hold unvalidated free-form text (spec constraint). Search columns indexed for the spec's Location search.

**Depends on:** nothing.

**Testing:** run `php artisan migrate` on the **test** DB via `php artisan test` (any existing suite run proves the migration applies on SQLite); also verify `php artisan migrate:status` lists it.

- [ ] Step 1: Write migration
- [ ] Step 2: Run `php artisan test --filter=DatabaseSchemaTest` — expected PASS (migration applies cleanly on SQLite)
- [ ] Step 3: Commit: `git add database/migrations/2025_03_01_000001_create_properties_table.php && git commit -m "feat(location): add properties table"`

### Task 2: Create `buildings` table

**Files:**
- Create: `database/migrations/2025_03_01_000002_create_buildings_table.php`

**What:**

```php
Schema::create('buildings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('property_id')
        ->constrained('properties')
        ->restrictOnDelete();
    $table->string('name');
    $table->string('code');
    $table->text('description')->nullable();
    $table->unsignedSmallInteger('floor_count')->nullable();
    $table->enum('status', ['active', 'inactive'])->default('active');
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['property_id', 'code']);
    $table->index('property_id');
});
```

**Why:** Building code is unique *per property* (`UNIQUE(property_id, code)`), so `BUILDING-A` can exist in both Hotel A and Hotel B. `restrictOnDelete` matches the existing rooms→room_types FK convention; properties are soft-deleted in this app, so it never fires.

**Depends on:** Task 1 (FK → properties).

**Testing:** same as Task 1.

- [ ] Step 1: Write migration
- [ ] Step 2: `php artisan test --filter=DatabaseSchemaTest` — PASS
- [ ] Step 3: Commit: `git add database/migrations/2025_03_01_000002_create_buildings_table.php && git commit -m "feat(location): add buildings table"`

### Task 3: Add `building_id` to rooms + unique-index swap + backfill

**Files:**
- Create: `database/migrations/2025_03_01_000003_add_building_id_to_rooms_table.php`

**What:** The data-safety-critical migration. `up()` in order:

```php
public function up(): void
{
    Schema::table('rooms', function (Blueprint $table) {
        $table->foreignId('building_id')
            ->nullable()
            ->after('room_type_id')
            ->constrained('buildings')
            ->restrictOnDelete();
        $table->index('building_id');
    });

    // Data step (idempotent): default property/building, then backfill
    // EVERY existing room (including soft-deleted rows - the query
    // touches the table directly, not the Eloquent scope).
    $propertyId = DB::table('properties')->where('code', 'MAIN')->value('id');
    if ($propertyId === null) {
        $propertyId = DB::table('properties')->insertGetId([
            'name' => 'Main Hotel',
            'code' => 'MAIN',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $buildingId = DB::table('buildings')
        ->where('property_id', $propertyId)
        ->where('code', 'MAIN')
        ->value('id');
    if ($buildingId === null) {
        $buildingId = DB::table('buildings')->insertGetId([
            'property_id' => $propertyId,
            'name' => 'Main Building',
            'code' => 'MAIN',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('rooms')->whereNull('building_id')->update(['building_id' => $buildingId]);

    // Constraints now that every row has a home.
    Schema::table('rooms', function (Blueprint $table) {
        $table->unsignedBigInteger('building_id')->nullable(false)->change();
        $table->dropUnique(['room_number']);
        $table->unique(['building_id', 'room_number']);
    });
}
```

`down()`: drop composite unique → re-add `unique(['room_number'])` (fails if duplicate numbers were since created across buildings — acceptable rollback limitation, note it in a comment) → drop index + FK + column `building_id`.

**Why:** Existing room numbers stay untouched (they were globally unique, so they are also unique within one building). The global unique index is swapped for `UNIQUE(building_id, room_number)` exactly as approved. `restrictOnDelete` on building_id matches convention.

**Depends on:** Tasks 1, 2.

**Testing:** Task 8 in this phase asserts the end state. For now: run the full suite — every existing test that creates a room will fail until the Pest helpers are updated (Phase 2 Task 12 covers that; run with `--filter=DatabaseSchemaTest` for this task).

- [ ] Step 1: Write migration
- [ ] Step 2: `php artisan test --filter=DatabaseSchemaTest` — PASS
- [ ] Step 3: Commit: `git add database/migrations/2025_03_01_000003_add_building_id_to_rooms_table.php && git commit -m "feat(location): attach rooms to buildings with per-building room-number uniqueness and Main Hotel backfill"`

### Task 4: Add `property_id` to employees + receptionist backfill

**Files:**
- Create: `database/migrations/2025_03_01_000004_add_property_id_to_employees_table.php`

**What:**

```php
public function up(): void
{
    Schema::table('employees', function (Blueprint $table) {
        $table->foreignId('property_id')
            ->nullable()
            ->after('department_id')
            ->constrained('properties')
            ->restrictOnDelete();
        $table->index('property_id');
    });

    $mainId = DB::table('properties')->where('code', 'MAIN')->value('id');
    if ($mainId !== null) {
        DB::table('employees')
            ->where('role', 'receptionist')
            ->whereNull('property_id')
            ->update(['property_id' => $mainId]);
    }
}
```

**Why:** Nullable at DB level for back-compat (spec §4.4), but every *receptionist* gets Main Hotel — the "receptionist never unrestricted" rule. Admin/manager stay NULL (= all properties). `down()` drops index, FK, column.

**Depends on:** Task 1 (FK), Task 3 (MAIN property guaranteed to exist whenever rooms existed; the `if ($mainId !== null)` guard covers fresh DBs).

**Testing:** same pattern as Task 1.

- [ ] Step 1: Write migration
- [ ] Step 2: `php artisan test --filter=DatabaseSchemaTest` — PASS
- [ ] Step 3: Commit: `git add database/migrations/2025_03_01_000004_add_property_id_to_employees_table.php && git commit -m "feat(location): add employee property assignment with receptionist backfill"`

### Task 5: Location-filter analytics indexes

**Files:**
- Create: `database/migrations/2025_03_01_000005_add_location_analytics_indexes.php`

**What:**

```php
Schema::table('rooms', function (Blueprint $table) {
    $table->index(['building_id', 'status']);
});
Schema::table('buildings', function (Blueprint $table) {
    $table->index(['property_id', 'status']);
});
```

**Why:** The property/building-filtered occupancy snapshots and availability checks in Phase 5 join `rooms.building_id` + `status` and `buildings.property_id` + `status`; the composite indexes keep them index-only. Follows the existing `2025_02_20_000002_add_analytics_indexes.php` pattern.

**Depends on:** Tasks 2, 3.

**Testing:** `php artisan test --filter=DatabaseSchemaTest` — PASS.

- [ ] Step 1: Write migration
- [ ] Step 2: Run schema test — PASS
- [ ] Step 3: Commit: `git add database/migrations/2025_03_01_000005_add_location_analytics_indexes.php && git commit -m "feat(location): add property-filter analytics indexes"`

### Task 6: Backfill + schema behavior tests

**Files:**
- Create: `tests/Feature/Property/BackfillSchemaTest.php`

**What:** Pest test asserting the post-migration invariants (RefreshDatabase runs all migrations, then the test seeds its own rows):

```php
test('room_number is unique per building, not globally', function () {
    $property = Property::create(['name' => 'A', 'code' => 'A', 'status' => 'active']);
    $propertyB = Property::create(['name' => 'B', 'code' => 'B', 'status' => 'active']);
    $buildingA = Building::create(['property_id' => $property->id, 'name' => 'A', 'code' => 'A']);
    $buildingA2 = Building::create(['property_id' => $property->id, 'name' => 'A2', 'code' => 'A2']);
    $buildingB = Building::create(['property_id' => $propertyB->id, 'name' => 'B', 'code' => 'B']);
    $type = makeRoomType('Backfill');

    Room::create(['room_number' => '101', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $buildingA->id]);
    Room::create(['room_number' => '101', 'room_type_id' => $type->id, 'floor' => 2, 'building_id' => $buildingA2->id]); // OK - different building
    Room::create(['room_number' => '101', 'room_type_id' => $type->id, 'floor' => 3, 'building_id' => $buildingB->id]); // OK - different property

    expect(Room::count())->toBe(3);

    $this->expectException(\Illuminate\Database\QueryException::class);
    Room::create(['room_number' => '101', 'room_type_id' => $type->id, 'floor' => 4, 'building_id' => $buildingA->id]);
});

test('building_code is unique per property', function () { /* same code in two properties OK; duplicate in one throws */ });

test('main hotel backfill assigns every pre-existing room', function () {
    // Simulates the data step's guarantee: after migrate:fresh + this seed,
    // a direct insert of a room WITHOUT building_id is impossible (NOT NULL).
    $this->expectException(\Illuminate\Database\QueryException::class);
    Room::create(['room_number' => '999', 'room_type_id' => makeRoomType('X')->id, 'floor' => 1]);
});
```

**Why:** Locks the two uniqueness decisions and the NOT NULL constraint in tests; guards future regressions of the index swap.

**Depends on:** Tasks 1–5; Phase 2 Task 12 (Pest helpers `makeRoomType` already exists; `Property`/`Building` models from Phase 2 Task 7/8 must exist first — implement this test file only after those tasks, or write it now and run it at the end of Phase 2).

**Testing:** `php artisan test --filter=BackfillSchemaTest` — PASS.

- [ ] Step 1: Write test
- [ ] Step 2: Run it (after Phase 2 models exist) — PASS
- [ ] Step 3: Commit with Phase 2 commits.

---

# PHASE 2 — Backend models

### Task 7: `Property` model

**Files:**
- Create: `app/Models/Property.php`

**What:**

```php
class Property extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'description', 'address', 'city', 'province_state',
        'country', 'postal_code', 'latitude', 'longitude', 'phone', 'email',
        'website', 'check_in_time', 'check_out_time', 'timezone', 'currency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function buildings() { return $this->hasMany(Building::class); }
    public function rooms() { return $this->hasManyThrough(Room::class, Building::class); }
    public function employees() { return $this->hasMany(Employee::class); }

    public function scopeActive($query) { return $query->where('status', 'active'); }
}
```

**Why:** `status` IS fillable here (unlike Room.status) because admin CRUD manages active/inactive per spec. `latitude`/`longitude` cast decimal:6 so JSON output is a plain string like `13.730600`.

**Depends on:** Task 1 (table). Room/Employee relation classes already exist.

**Testing:** exercised by Task 6's tests + Phase 4 CRUD tests.

### Task 8: `Building` model

**Files:**
- Create: `app/Models/Building.php`

**What:**

```php
class Building extends Model
{
    use SoftDeletes;

    protected $fillable = ['property_id', 'name', 'code', 'description', 'floor_count', 'status'];

    protected function casts(): array
    {
        return ['floor_count' => 'integer'];
    }

    public function property() { return $this->belongsTo(Property::class); }
    public function rooms() { return $this->hasMany(Room::class); }
}
```

**Why:** Mirrors RoomType/Room model conventions (SoftDeletes, casts array style).

**Depends on:** Task 2.

### Task 9: `Room` relationship changes

**Files:**
- Modify: `app/Models/Room.php`

**What:** Add `'building_id'` to `$fillable`; add:

```php
public function building()
{
    return $this->belongsTo(Building::class);
}

public function property()
{
    return $this->hasOneThrough(Property::class, Building::class, 'id', 'id', 'building_id', 'property_id');
}
```

**Why:** `building_id` must be mass-assignable through `RoomService::create()` (validated input only). `property()` is the derivation convenience the spec demands (Room → Building → Property).

**Depends on:** Tasks 7, 8.

### Task 10: `Employee` relationship

**Files:**
- Modify: `app/Models/Employee.php`

**What:** Add `'property_id'` to `$fillable`; add `property()` belongsTo. Casts unchanged.

**Why:** Employee create/update (admin-only) sets the assignment; validation in Phase 3 enforces the role rules.

**Depends on:** Task 7.

### Task 11: Property scopes on `Booking`, `Payment`, `Invoice`

**Files:**
- Modify: `app/Models/Booking.php`
- Modify: `app/Models/Payment.php`
- Modify: `app/Models/Invoice.php`

**What:** Add to `Booking`:

```php
public function scopeByProperty($query, int $propertyId)
{
    return $query->whereHas('bookingRooms.room.building', fn ($q) => $q->where('property_id', $propertyId));
}

public function scopeByBuilding($query, int $buildingId)
{
    return $query->whereHas('bookingRooms.room', fn ($q) => $q->where('building_id', $buildingId));
}

public function scopeByRoomType($query, int $roomTypeId)
{
    return $query->whereHas('bookingRooms', fn ($q) => $q->where('room_type_id', $roomTypeId));
}

/** Property id of the booking's first room's building, or null. */
public function propertyId(): ?int
{
    return $this->bookingRooms()->with('room.building')->first()?->room?->building?->property_id;
}
```

Add to `Payment` (`booking()` belongsTo already exists):

```php
public function scopeByProperty($query, int $propertyId)
{
    return $query->whereHas('booking.bookingRooms.room.building', fn ($q) => $q->where('property_id', $propertyId));
}

public function propertyId(): ?int
{
    return $this->booking?->propertyId();
}
```

Add to `Invoice` (via `payment()`):

```php
public function scopeByProperty($query, int $propertyId)
{
    return $query->whereHas('payment.booking.bookingRooms.room.building', fn ($q) => $q->where('property_id', $propertyId));
}
```

**Why:** Single source of truth for the property join chain — every list endpoint, report, and policy check reuses these scopes instead of repeating the `whereHas` nesting.

**Depends on:** Task 9.

**Testing:** `php artisan test --filter=BackfillSchemaTest` still passes; full suite run comes at Phase 3 end (helpers fixed in Task 12 first).

### Task 12: Fix Pest helpers for the new NOT NULL `building_id`

**Files:**
- Modify: `tests/Pest.php`

**What:** Update `makeBookingRoom` (and add a shared building helper) so every room created in tests gets a valid building — `firstOrCreate` inside the test's fresh DB (never a static cache — RefreshDatabase wipes rows between tests but static state survives the process):

```php
function makeProperty(string $code = 'MAIN', string $name = 'Main Hotel'): \App\Models\Property
{
    return \App\Models\Property::firstOrCreate(
        ['code' => $code],
        ['name' => $name, 'status' => 'active'],
    );
}

function makeBuilding(?string $code = null, ?int $propertyId = null): \App\Models\Building
{
    $propertyId ??= makeProperty()->id;

    return \App\Models\Building::firstOrCreate(
        ['property_id' => $propertyId, 'code' => $code ?? 'MAIN'],
        ['name' => 'Main Building', 'status' => 'active'],
    );
}

function makeBookingRoom(string $number, int $roomTypeId, int $floor = 1): \App\Models\Room
{
    return \App\Models\Room::create([
        'room_number' => $number, 'room_type_id' => $roomTypeId, 'floor' => $floor,
        'building_id' => makeBuilding()->id,
    ]);
}
```

Then fix **every other direct room creation in tests**: `grep -rn "Room::create(\|Room::factory" tests/` — update each call site to pass `'building_id' => makeBuilding()->id` (e.g. `tests/Feature/Room/StaffRoomTest.php`, `RoomStatusTest.php`, `RoomTurnoverTest.php`, `tests/Feature/Booking/*`). Do not change test semantics — only make them satisfy the new FK.

**Why:** Without this, ~half the suite fails on the NOT NULL constraint. Helper-based creation keeps future property tests one-line away from a second property.

**Depends on:** Tasks 3, 7–11.

**Testing:** `php artisan test` — the full existing suite (517 tests) must be green again before Phase 3 starts.

- [ ] Step 1: Update Pest.php helpers
- [ ] Step 2: `grep -rn "Room::create(" tests/` — fix every call site
- [ ] Step 3: `php artisan test` — full suite PASS
- [ ] Step 4: Commit: `git add tests/ && git commit -m "test(location): give test rooms a default building after rooms.building_id becomes NOT NULL"`

### Task 13: `PropertyScope` resolver

**Files:**
- Create: `app/Support/PropertyScope.php`

**What:** The single place scope is decided (spec §8.1):

```php
namespace App\Support;

use App\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The ONLY place the effective property filter for a staff request is
 * decided. Receptionists are hard-scoped to their assigned property -
 * request params can never widen that scope (spec §8.1).
 */
class PropertyScope
{
    /**
     * @return int|null effective property id filter; null = all properties
     * @throws AuthorizationException when a receptionist has no assignment
     *                                (fail closed - legacy data edge)
     */
    public static function effectiveId(Employee $employee, ?int $requestedPropertyId): ?int
    {
        if (in_array($employee->role, ['admin', 'manager'], true)) {
            return $requestedPropertyId;
        }

        if ($employee->property_id === null) {
            throw new AuthorizationException('Your account is not assigned to a property.');
        }

        return (int) $employee->property_id; // request param deliberately ignored
    }
}
```

**Why:** One choke point = the "cannot be bypassed by URL/API tampering" requirement is provable in one place; every staff list controller calls this before querying.

**Depends on:** Task 10.

**Testing:** covered by Phase 3 Task 21 (ReceptionistScopingTest) — unit-verifiable via that file.

---

# PHASE 3 — Backend validation & authorization

### Task 14: `PropertyPolicy` + `BuildingPolicy`

**Files:**
- Create: `app/Policies/PropertyPolicy.php`
- Create: `app/Policies/BuildingPolicy.php`

**What:** Both follow the EmployeePolicy shape:

```php
class PropertyPolicy
{
    public function viewAny(Employee $employee): bool { return true; }
    public function view(Employee $employee, Property $property): bool
    {
        if ($employee->role === 'receptionist') {
            return $employee->property_id === $property->id;
        }
        return true;
    }
    public function create(Employee $employee): bool { return $employee->role === 'admin'; }
    public function update(Employee $employee): bool { return $employee->role === 'admin'; }
    public function delete(Employee $employee): bool { return $employee->role === 'admin'; }
}
```

`BuildingPolicy` identical shape; `view` checks the building's `property_id` against a receptionist's assignment.

**Why:** Admin CRUD / manager+receptionist view, per the confirmed matrix; two-layer convention (route `role:admin` + these re-checks).

**Depends on:** Tasks 7, 8.

### Task 15: Property + Building FormRequests

**Files:**
- Create: `app/Http/Requests/Property/StoreRequest.php`
- Create: `app/Http/Requests/Property/UpdateRequest.php`
- Create: `app/Http/Requests/Property/IndexRequest.php`
- Create: `app/Http/Requests/Building/StoreRequest.php`
- Create: `app/Http/Requests/Building/UpdateRequest.php`

**What:** Extend `ApiFormRequest` (existing base). Property StoreRequest rules:

```php
'name' => ['required', 'string', 'max:255'],
'code' => ['required', 'string', 'max:50', Rule::unique('properties', 'code')],
'description' => ['nullable', 'string'],
'address' => ['nullable', 'string', 'max:255'],
'city' => ['nullable', 'string', 'max:255'],
'province_state' => ['nullable', 'string', 'max:255'],
'country' => ['nullable', 'string', 'max:255'],
'postal_code' => ['nullable', 'string', 'max:20'],
'latitude' => ['nullable', 'numeric', 'between:-90,90'],
'longitude' => ['nullable', 'numeric', 'between:-180,180'],
'phone' => ['nullable', 'string', 'max:20'],
'email' => ['nullable', 'email', 'max:255'],
'website' => ['nullable', 'url', 'max:255'],
'check_in_time' => ['nullable', 'date_format:H:i'],
'check_out_time' => ['nullable', 'date_format:H:i'],
'timezone' => ['nullable', 'string', 'max:64'],
'currency' => ['nullable', 'string', 'size:3'],
'status' => ['nullable', 'string', 'in:active,inactive'],
```

UpdateRequest: identical + `Rule::unique(...)->ignore($this->route('property'))` on code. IndexRequest: `search` (nullable string), `code`, `city`, `province_state`, `country`, `status` (in:active,inactive), `sort` (in:name,code,city,country,status,created_at), `direction`, `per_page` (1–50) — mirroring `Room/IndexRequest`.

Building StoreRequest (property comes from the route):

```php
'name' => ['required', 'string', 'max:255'],
'code' => ['required', 'string', 'max:50',
    Rule::unique('buildings', 'code')->where('property_id', $this->route('property')->id)],
'description' => ['nullable', 'string'],
'floor_count' => ['nullable', 'integer', 'min:1', 'max:200'],
'status' => ['nullable', 'string', 'in:active,inactive'],
```

UpdateRequest: same + `->ignore($this->route('building'))`.

**Why:** Lat/lng numeric range validation = "never unvalidated free-form text"; code uniqueness per spec; list filters match the spec's Location search.

**Depends on:** Task 7, 8.

### Task 16: Employee validation — receptionist property assignment

**Files:**
- Modify: `app/Http/Requests/Employee/StoreRequest.php`
- Modify: `app/Http/Requests/Employee/UpdateRequest.php`

**What:** Add to both rule arrays:

```php
'property_id' => [
    'nullable', 'integer',
    Rule::exists('properties', 'id')->whereNull('deleted_at'),
    'required_if:role,receptionist',
    'prohibited_unless:role,receptionist',
],
```

**Why:** Receptionist MUST have a property; admin/manager MUST NOT (they're unrestricted) — enforced at validation so bad input never reaches the controller (spec §7.5).

**Depends on:** Task 7.

### Task 17: Room request changes — `building_id` + per-building uniqueness

**Files:**
- Modify: `app/Http/Requests/Room/StoreRequest.php`
- Modify: `app/Http/Requests/Room/UpdateRequest.php`
- Modify: `app/Http/Requests/Room/IndexRequest.php`

**What:** StoreRequest:

```php
'building_id' => [
    'required', 'integer',
    Rule::exists('buildings', 'id')->whereNull('deleted_at'),
],
'room_number' => [
    'required', 'string', 'max:50',
    Rule::unique('rooms', 'room_number')
        ->where('building_id', $this->input('building_id'))
        ->whereNull('deleted_at'),
],
```

UpdateRequest: same, with `->ignore($this->route('room'))` on the unique. IndexRequest adds:

```php
'property_id' => ['nullable', 'integer', 'exists:properties,id'],
'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
```

**Why:** Rooms now require a building; duplicate `101` rejected only within the same building. Public room listing reuses this IndexRequest, so `property_id` works there too.

**Depends on:** Task 8.

### Task 18: Filter param rules on existing requests

**Files:**
- Modify: `app/Http/Requests/Booking/FilterRequest.php` — add `property_id`, `building_id` (nullable, exists)
- Modify: `app/Http/Requests/Booking/CreateBookingRequest.php` — add optional `property_id`, `building_id` (nullable, exists) alongside existing keys (do NOT touch existing rules)
- Modify: `app/Http/Requests/Booking/AvailabilityRequest.php` — add optional `property_id` (nullable, exists)
- Modify: `app/Http/Requests/Payment/FilterRequest.php`, `Invoice/FilterRequest.php`, `Calendar/BookingsRequest.php` — add `property_id` (nullable, exists)
- Modify: `app/Http/Requests/Dashboard/IndexRequest.php` — add `property_id`
- Modify: `app/Http/Requests/Analytics/IndexRequest.php` — add `property_id`, `building_id`, `room_type_id`
- Modify: `app/Http/Requests/Report/ReportWindowRequest.php`, `ReportExportRequest.php`, `BookingsRequest.php`, `RevenueRequest.php`, `RoomsRequest.php` — add the same three (nullable, exists; each file only where meaningful: window reports get property/building/room_type, RoomsRequest gets property/building)

**Why:** All new filters are allowlisted ints with `exists` — unknown ids 422 (never silently ignored), per spec §7.3.

**Depends on:** Tasks 7, 8.

### Task 19: Booking integrity + availability filtering in `BookingService`

**Files:**
- Modify: `app/Services/BookingService.php`

**What:**
1. `searchAvailable(string $checkIn, string $checkOut, array $roomIds, ?int $propertyId = null)`:
   - add `->when($propertyId, fn ($q) => $q->whereHas('building', fn ($bq) => $bq->where('property_id', $propertyId)))`
   - add active-chain exclusion:
   ```php
   ->whereHas('building', fn ($bq) => $bq
       ->whereNull('buildings.deleted_at')
       ->where('buildings.status', 'active')
       ->whereHas('property', fn ($pq) => $pq
           ->whereNull('properties.deleted_at')
           ->where('properties.status', 'active')))
   ```
2. `create()`: inside the transaction, after the room-existence check (line ~143), add the property-consistency block:

```php
// Property integrity (spec §7.4): every room must share one property,
// and any claimed property/building must match. Never trusted from
// the frontend - derived from the locked rows.
$propertyIds = $rooms->pluck('building.property_id')->unique();
if ($propertyIds->count() !== 1) {
    throw new BusinessRuleException('All rooms in a booking must belong to the same property.');
}
$actualPropertyId = (int) $propertyIds->first();

if (isset($data['property_id']) && (int) $data['property_id'] !== $actualPropertyId) {
    throw new BusinessRuleException('The selected rooms do not belong to the selected property.');
}
if (isset($data['building_id'])) {
    $buildingIds = $rooms->pluck('building_id')->unique();
    if ($buildingIds->count() !== 1 || (int) $buildingIds->first() !== (int) $data['building_id']) {
        throw new BusinessRuleException('The selected rooms do not belong to the selected building.');
    }
}

foreach ($rooms as $room) {
    if ($room->building === null || $room->building->trashed() || $room->building->status !== 'active'
        || $room->building->property === null || $room->building->property->trashed() || $room->building->property->status !== 'active') {
        throw new BusinessRuleException('One or more requested rooms are not available for the selected dates.');
    }
}
```

(Ensure the locked-room query loads `building.property`: add `->with('building.property')` to the `Room::whereIn(...)->lockForUpdate()` query at line ~134.)

**Why:** Spec's "Do not trust the frontend to determine the property" and "Room 101 of Property A must not appear as available when searching rooms for Property B" — both enforced from locked server state.

**Depends on:** Tasks 9, 18.

**Testing:** Phase 3 Task 22 (`BookingIntegrityTest`).

### Task 20: Policy changes — `RoomPolicy`, `BookingPolicy`, `PaymentPolicy`

**Files:**
- Modify: `app/Policies/RoomPolicy.php`
- Modify: `app/Policies/BookingPolicy.php`
- Modify: `app/Policies/PaymentPolicy.php`

**What:**
- `RoomPolicy::view(Employee $employee, Room $room)`: add `if ($employee->role === 'receptionist') { return $room->building?->property_id === $employee->property_id; }` before `return true`. `changeStatus(Employee $employee, Room $room)` gains the `$room` param with the same receptionist check.
- `BookingPolicy`: `view($user, $booking)` — for Employee add the receptionist check via `$booking->propertyId() === $employee->property_id`. Change signatures `confirm($user, Booking $booking)`, `checkIn($user, Booking $booking)`, `checkOut($user, Booking $booking)` (currently `$user`-only) to receive the booking and apply the receptionist check after the existing role check; `cancel($user, $booking)` already has the booking — add the check for Employees.
- `PaymentPolicy`: `view($user, Payment $payment)` — receptionist check via `$payment->propertyId()`. `verify($user, Payment $payment)` / `reject($user, Payment $payment)` — add the `$payment` param + receptionist check. `downloadReceipt($user, $payment)` — same receptionist check for Employees.

**Why:** Lifecycle actions and single-resource views become property-aware; admins/managers unaffected (role check short-circuits first).

**Depends on:** Tasks 11, 13.

**Testing:** Phase 3 Task 21.

### Task 21: Receptionist scoping tests

**Files:**
- Create: `tests/Feature/Property/ReceptionistScopingTest.php`

**What:** Pest file seeding two properties and exercising the resolver end-to-end:

```php
function secondProperty(): Property
{
    return Property::create(['name' => 'Hotel B', 'code' => 'B', 'status' => 'active']);
}

test('receptionist sees only rooms of their assigned property', function () {
    $roomA = makeBookingRoom('A1', makeRoomType('T')->id);
    $buildingB = Building::create(['property_id' => secondProperty()->id, 'name' => 'B1', 'code' => 'B1']);
    $roomB = Room::create(['room_number' => 'B1', 'room_type_id' => $roomA->room_type_id, 'floor' => 1, 'building_id' => $buildingB->id]);

    $receptionist = Employee::create([
        'name' => 'Reception', 'email' => 'recp@example.com', 'password' => 'Password123!',
        'role' => 'receptionist', 'property_id' => makeProperty()->id,
    ]);
    $token = $receptionist->createToken('test')->plainTextToken;

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/staff/rooms?property_id='.$buildingB->property_id);
    $res->assertOk();
    // The tampered property_id is IGNORED - still property A's rooms only.
    expect(collect($res->json('data'))->pluck('id'))->toContain($roomA->id)->not->toContain($roomB->id);
});

test('receptionist cannot view another property detail', function () { /* GET /staff/properties/{B} -> 403 */ });
test('receptionist cannot confirm a booking from another property', function () { /* 403 */ });
test('receptionist cannot verify a payment from another property', function () { /* 403 */ });
test('creating a receptionist without property fails validation', function () { /* POST /staff/employees -> 422 */ });
test('creating an admin with a property is rejected', function () { /* POST /staff/employees -> 422 */ });
test('receptionist with no assignment fails closed with 403', function () {
    // Legacy edge: directly create receptionist with property_id = null, hit rooms list.
});
```

**Why:** Proves the non-bypassable server-side scoping requirement — the tampered-`property_id` case is the critical assertion.

**Depends on:** Tasks 13–20 AND Phase 4 Task 23–29 (endpoints) — write this file in this phase, run it at the end of Phase 4.

### Task 22: Booking integrity tests

**Files:**
- Create: `tests/Feature/Property/BookingIntegrityTest.php`

**What:**

```php
test('mixed-property room set is rejected on create', function () {
    $type = makeRoomType('Integrity');
    $roomA = makeBookingRoom('I1', $type->id);
    $buildingB = Building::create(['property_id' => secondProperty()->id, 'name' => 'IB', 'code' => 'IB']);
    $roomB = Room::create(['room_number' => 'I2', 'room_type_id' => $type->id, 'floor' => 1, 'building_id' => $buildingB->id]);
    $token = loginAsCustomer($this, 'integrity@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', bookingPayload([$roomA, $roomB]))
        ->assertStatus(422);
});

test('claimed property that does not match the rooms is rejected', function () { /* bookingPayload($roomA) + ['property_id' => $buildingB->property_id] -> 422 */ });
test('rooms of an inactive property are rejected', function () { /* deactivate property, booking -> 422 */ });
test('availability omits rooms of other properties when property_id given', function () {
    // POST /public/availability with property_id = B returns no rooms, or only B's rooms.
});
test('availability excludes rooms of inactive properties', function () { /* ... */ });
```

**Why:** The backend-never-trusts-frontend rule, locked by tests.

**Depends on:** Task 19, Phase 4 endpoints (run at end of Phase 4).

- [ ] Step 1–2: write both test files now (they will fail until Phase 4 lands)
- [ ] Step 3: commit them with the Phase 4 commits once green

---

# PHASE 4 — Backend API

### Task 23: `PropertyService`

**Files:**
- Create: `app/Services/PropertyService.php`

**What:** Follows `RoomService` shape (SORTABLE_FIELDS allowlist, per_page clamp):

```php
class PropertyService
{
    private const SORTABLE_FIELDS = ['name', 'code', 'city', 'country', 'status', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        // applies: search (name/code/city/province_state/country LIKE),
        // code/city/province_state/country equality, status, sort/direction
        // allowlist, per_page clamp (15 default, 50 max), withCount('buildings')
    }

    public function create(array $data): Property { /* new Property($data)->save(); return fresh() */ }
    public function update(Property $property, array $data): Property { /* fill/save/fresh */ }
    public function delete(Property $property): void { /* $property->delete() (soft) */ }

    public function detail(Property $property): array
    {
        // buildings with room counts + occupancy:
        //   $property->buildings()->withCount('rooms')->get(), plus per-building
        //   occupied count via rooms relation status='occupied' count.
        // summary via SQL:
        //   room_count, occupied (rooms.status='occupied'), occupancy_pct,
        //   verified_revenue_total (Payment::byProperty verified sum),
        //   active_bookings (Booking::byProperty holding statuses count).
        // shape exactly as spec §7.1.
    }
}
```

**Why:** Keeps the controller thin and mirrors the existing service pattern (`app/Services/README.md`: services exist where logic lives; EmployeeController is the counter-example — properties have aggregation, so a service is justified).

**Depends on:** Tasks 7, 8, 11.

### Task 24: Staff `PropertyController` + `BuildingController`

**Files:**
- Create: `app/Http/Controllers/Api/V1/Staff/PropertyController.php`
- Create: `app/Http/Controllers/Api/V1/Staff/BuildingController.php`

**What:** PropertyController:

```php
public function index(IndexRequest $request, PropertyService $service): JsonResponse
{
    $this->authorize('viewAny', Property::class);

    $propertyId = PropertyScope::effectiveId($request->user(), $request->validated('property_id'));

    if ($propertyId !== null && $request->user()->role === 'receptionist') {
        // receptionist list: single-row pagination of their own property
        return ApiResponse::paginated(Property::whereKey($propertyId)->paginate(15));
    }

    $filters = $request->validated();
    if ($propertyId !== null) {
        $filters['property_id'] = $propertyId; // admin/manager optional filter
    }

    return ApiResponse::paginated($service->paginate($filters));
}

public function show(Property $property): JsonResponse
{
    $this->authorize('view', $property);          // PropertyPolicy rejects receptionists of other properties

    return ApiResponse::success(app(PropertyService::class)->detail($property));
}

public function store(StoreRequest $request, PropertyService $service): JsonResponse
{
    $this->authorize('create', Property::class);

    return ApiResponse::success($service->create($request->validated()), 'Property created.', 201);
}
// update() / destroy() mirror store() with $this->authorize('update'/'delete', $property)
```

BuildingController: `index(Property $property)` (authorize view on the property, return `$property->buildings()->orderBy('name')->paginate()`), `store(StoreRequest $request, Property $property)` (authorize create, `Building::class`), `show(Building $building)`, `update(UpdateRequest, Building)`, `destroy(Building)` — each with the matching BuildingPolicy authorize.

**Why:** Two-layer auth + PropertyScope; receptionist property list returns exactly their row (spec §7.1).

**Depends on:** Tasks 13, 14, 15, 23.

### Task 25: Public `PropertyController`

**Files:**
- Create: `app/Http/Controllers/Api/V1/Public/PropertyController.php`

**What:**

```php
public function index(): JsonResponse
{
    $properties = Property::query()
        ->where('status', 'active')
        ->orderBy('name')
        ->get(['id', 'name', 'code', 'city', 'country']);

    return ApiResponse::success($properties, 'OK');
}
```

**Why:** Booking-site selector data; active-only so soft-deleted/inactive properties never surface publicly (spec §7.2, §13).

**Depends on:** Task 7.

### Task 26: Routes

**Files:**
- Modify: `routes/api.php`

**What:** Inside the existing `Route::prefix('staff')->middleware([...])` group (after the `rooms` group, before `bookings`):

```php
// STEP 19 - Property/Building management. View: any staff role.
// Write: role:admin (PropertyPolicy/BuildingPolicy re-check inside).
Route::prefix('properties')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
    Route::get('/', [PropertyController::class, 'index']);
    Route::post('/', [PropertyController::class, 'store'])->middleware('role:admin');
    Route::get('/{property}', [PropertyController::class, 'show']);
    Route::put('/{property}', [PropertyController::class, 'update'])->middleware('role:admin');
    Route::delete('/{property}', [PropertyController::class, 'destroy'])->middleware('role:admin');

    Route::get('/{property}/buildings', [BuildingController::class, 'index']);
    Route::post('/{property}/buildings', [BuildingController::class, 'store'])->middleware('role:admin');
});

// Top-level, matching the room-images convention: identified by own id.
Route::prefix('buildings')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
    Route::get('/{building}', [BuildingController::class, 'show']);
    Route::put('/{building}', [BuildingController::class, 'update'])->middleware('role:admin');
    Route::delete('/{building}', [BuildingController::class, 'destroy'])->middleware('role:admin');
});
```

In the public group: `Route::get('/properties', [PublicPropertyController::class, 'index']);` Add the import statements at the top of the file.

**Why:** Follows the exact nesting/convention of room-types + room-images groups.

**Depends on:** Tasks 24, 25.

### Task 27: Scope the staff list endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Staff/RoomController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/BookingController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/PaymentController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/InvoiceController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/CalendarController.php`

**What:**
- `RoomController::index` — before calling the service:
  ```php
  $filters = $request->validated();
  $filters['property_id'] = PropertyScope::effectiveId($request->user(), $filters['property_id'] ?? null);
  return ApiResponse::paginated($service->paginate($filters));
  ```
  `show` — authorize('view', $room) now enforces the receptionist check (Task 20).
- `BookingController::index` — resolve `$propertyId = PropertyScope::effectiveId($request->user(), $request->validated('property_id'))`; then:
  ```php
  $query = Booking::query()->with([...]);
  if ($propertyId !== null) { $query->byProperty($propertyId); }
  if ($request->validated('building_id')) { $query->byBuilding((int) $request->validated('building_id')); }
  ```
  Lifecycle actions: change `$this->authorize('confirm', $booking)` etc. so the booking model is passed (BookingPolicy now needs it — Task 20).
- `PaymentController::index` — same scope resolution, `$query->byProperty($propertyId)`; `verify`/`reject` pass `$payment` to authorize; `show` unchanged (policy checks).
- `InvoiceController::index` — `$query->byProperty($propertyId)`.
- `CalendarController::bookings` — resolve scope; `when($propertyId, fn ($q) => $q->byProperty($propertyId))`.

**Why:** Every staff list is now server-side property-scoped through the single resolver; receptionist params are ignored by construction.

**Depends on:** Tasks 11, 13, 20.

### Task 28: Room service filters + employee controller

**Files:**
- Modify: `app/Services/RoomService.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/EmployeeController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/RoomStatusController.php`

**What:**
- `RoomService::paginate` — add (before the sort block):
  ```php
  if (isset($filters['property_id'])) {
      $query->whereHas('building', fn ($q) => $q->where('property_id', $filters['property_id']));
  }
  if (isset($filters['building_id'])) {
      $query->where('building_id', $filters['building_id']);
  }
  ```
  (Public RoomController shares this service, so the public `?property_id` filter works automatically; add `whereHas('building.property', active)`? No — public active-property filtering belongs in the availability path only, per spec. Keep list semantics unchanged.)
- `EmployeeController::store/update` — the validated `property_id` already flows through `array_filter($request->validated(), fn ($value) => $value !== null)`; add `->load('property')` on show, and include `property` in index eager load (`->with('property')`).
- `RoomStatusController` — pass the room into `$this->authorize('changeStatus', $room)` (RoomPolicy signature change from Task 20).

**Why:** Filter plumbing + policy wiring.

**Depends on:** Tasks 16, 20.

### Task 29: Public availability + public room list wiring

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Public/BookingController.php`
- Modify: `app/Http/Controllers/Api/V1/Public/RoomController.php`

**What:** `Public\BookingController::availability` passes `$data['property_id'] ?? null` as the 4th arg to `$service->searchAvailable(...)`. `Public\RoomController::index` is unchanged (RoomService already honors `property_id` from Task 28).

**Why:** Public availability becomes property-aware.

**Depends on:** Tasks 19, 28.

- [ ] Step 1: Implement Tasks 23–29 in order
- [ ] Step 2: `php artisan test --filter="PropertyCrudTest|BuildingCrudTest|ReceptionistScopingTest|BookingIntegrityTest|BackfillSchemaTest"` plus write `PropertyCrudTest`/`BuildingCrudTest` (see Phase 8) — all PASS
- [ ] Step 3: `php artisan test` — full suite PASS (including updated StaffEmployeeTest, StaffRoomTest expectations)
- [ ] Step 4: Pint on modified files: `vendor/bin/pint --test $(git ls-files -m | grep '\.php$')`
- [ ] Step 5: Commit: `git add app/ routes/ && git commit -m "feat(location): properties and buildings API with property-scoped staff lists"`

---

# PHASE 5 — Dashboard / Analytics / Reports SQL

### Task 30: `AnalyticsService` property/building/room-type filtering

**Files:**
- Modify: `app/Services/AnalyticsService.php`

**What:** Change public signatures (callers updated in Task 31):

```php
public function analytics(string $from, string $to, string $groupBy = 'day',
    ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array

public function dashboard(string $from, string $to, string $groupBy = 'day',
    ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
```

Add three private scoping helpers used by every aggregation:

```php
private function scopedBookings($query, ?int $propertyId, ?int $buildingId, ?int $roomTypeId)
{
    return $query
        ->when($propertyId, fn ($q) => $q->byProperty($propertyId))
        ->when($buildingId, fn ($q) => $q->byBuilding($buildingId))
        ->when($roomTypeId, fn ($q) => $q->byRoomType($roomTypeId));
}

private function scopedPayments($query, ?int $propertyId, ?int $buildingId)
{
    return $query
        ->when($propertyId, fn ($q) => $q->byProperty($propertyId))
        ->when($buildingId, fn ($q) => $q
            ->whereHas('booking.bookingRooms.room', fn ($rq) => $rq->where('building_id', $buildingId)));
}

private function scopedRooms($query, ?int $propertyId, ?int $buildingId)
{
    return $query
        ->when($propertyId, fn ($q) => $q->whereHas('building', fn ($bq) => $bq->where('property_id', $propertyId)))
        ->when($buildingId, fn ($q) => $q->where('building_id', $buildingId));
}
```

Thread the params through every private method (each currently takes `($start, $end)` or nothing):
- `bookingsIn($start, $end, $propertyId, $buildingId, $roomTypeId)` — wraps `scopedBookings`
- `summary()` — verified/refunded `Payment::query()` → `scopedPayments`; bookings → scoped `bookingsIn`
- `bookingTrend`, `bookingStatusCounts`, `bookingSourceCounts`, `checkFlow`, `guestStats` (its raw `DB::table('bookings as b')` queries gain `->when($propertyId, fn ($q) => $q->join('booking_rooms as br2', 'br2.booking_id', '=', 'b.id')->join('rooms as r2', 'r2.id', '=', 'br2.room_id')->join('buildings as bd2', 'bd2.id', '=', 'r2.building_id')->where('bd2.property_id', $propertyId)->distinct())` — or simpler: add the joins always when any filter is set and `where` accordingly), `todaysSnapshot`, `occupiedRoomNights` (joins already exist — add `join('rooms', ...)`, `join('buildings', ...)` + wheres)
- `revenueSeries`, `paymentStatusCounts`, `paymentMethodCounts` — `scopedPayments`
- `roomOccupancy` / `occupancySnapshot` / `inventorySnapshot` — `scopedRooms` (roomTypeId additionally filters `room_type_id`)
- `roomTypeStats` + `roomTypeRevenue` — add the `rooms`/`buildings` joins to the `DB::table(...)` queries and the wheres; when `roomTypeId` set, restrict `br.room_type_id`
- `occupancyTrend` — `roomsTotal` from filtered `occupancySnapshot`; `occupiedRoomNights` filtered

**Why:** Spec §9 — every KPI/chart is filtered in SQL so the dashboard/analytics selectors show true per-property aggregates; exports reuse the same method, so they match by construction. No filter = exactly today's queries (the `when()`s no-op), so unfiltered performance/behavior is unchanged.

**Depends on:** Task 11 (model scopes).

### Task 31: `ReportService` filters

**Files:**
- Modify: `app/Services/ReportService.php`

**What:** Every public method gains the optional params and applies them (reuse the `Booking`/`Payment` model scopes; `rooms()` uses `scopedRooms`-style whereHas on `rooms.building`):

```php
public function bookings(string $from, string $to, string $groupBy = 'day', ?string $status = null,
    ?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null): array
// revenue(from, to, groupBy, ?propertyId, ?buildingId)
// rooms(?from, ?to, ?propertyId, ?buildingId)
// guests(from, to, ?propertyId)
// sources(from, to, ?propertyId)
// checkIns/checkOuts/payments(from, to, groupBy, ?propertyId, ?buildingId)
// outstanding(?propertyId, ?buildingId)
// housekeeping/maintenance(from, to, ?propertyId, ?buildingId)  -> statusLogReport passes them down
```

Each applies the scopes inside its existing `$base` closures / query builders (`byProperty`/`byBuilding` whereHas). `housekeeping`/`maintenance` filter via `whereHas('bookingRooms.room.building', ...)` on the status-log query (verify the exact current query when implementing; keep its structure).

**Why:** Same reasoning as Task 30; exports reuse `dataFor()` → same methods → filtered exports.

**Depends on:** Task 30 (shared patterns), Task 11.

### Task 32: Controller wiring for dashboard/analytics/reports

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Staff/DashboardController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/AnalyticsController.php`
- Modify: `app/Http/Controllers/Api/V1/Staff/ReportController.php`

**What:**
- DashboardController index/export: `$this->analytics->dashboard($from, $to, $groupBy, $request->validated('property_id'))`.
- AnalyticsController: `$this->analytics->analytics($from, $to, $groupBy, $request->validated('property_id'), $request->validated('building_id'), $request->validated('room_type_id'))`.
- ReportController: each report method passes `$request->validated('property_id')` (and building/room_type where the request exposes them) into the matching service call; `export()`/`dataFor()` pass the same (ReportExportRequest now validates them — Task 18). Dashboard CSV `flatten()` needs no change (sections unchanged).

**Why:** The validated params reach the SQL layer; nothing is filtered client-side.

**Depends on:** Tasks 18, 30, 31.

- [ ] Step 1: Implement Tasks 30–32
- [ ] Step 2: Write + run `tests/Feature/Property/AnalyticsFilterTest.php` and `tests/Feature/Property/ReportFilterTest.php` (Phase 8 outlines them) — PASS
- [ ] Step 3: `php artisan test` — full suite PASS (existing Analytics/Dashboard/Report tests must be green with no params)
- [ ] Step 4: Pint on modified files
- [ ] Step 5: Commit: `git add app/ && git commit -m "feat(location): property/building/room-type filters across dashboard, analytics and reports SQL"`

---

# PHASE 6 — Frontend

(All frontend paths under `/Applications/XAMPP/xamppfiles/htdocs/hotel-frontend/src/`. Dev server is already running — HMR picks changes up; run `npm run dev` if not.)

### Task 33: `propertyApi.js`

**Files:**
- Create: `api/propertyApi.js`

**What:**

```js
import apiClient from './client'

export const publicProperties = {
  list: (params) => apiClient.get('/public/properties', { params }),
}

export const staffProperties = {
  list: (params) => apiClient.get('/staff/properties', { params }),
  show: (id) => apiClient.get(`/staff/properties/${id}`),
  create: (data) => apiClient.post('/staff/properties', data),
  update: (id, data) => apiClient.put(`/staff/properties/${id}`, data),
  destroy: (id) => apiClient.delete(`/staff/properties/${id}`),
  listBuildings: (propertyId) => apiClient.get(`/staff/properties/${propertyId}/buildings`),
  createBuilding: (propertyId, data) => apiClient.post(`/staff/properties/${propertyId}/buildings`, data),
  updateBuilding: (id, data) => apiClient.put(`/staff/buildings/${id}`, data),
  destroyBuilding: (id) => apiClient.delete(`/staff/buildings/${id}`),
}
```

**Why:** One client, mirrors `roomApi.js` conventions.

**Depends on:** Phase 4 endpoints.

### Task 34: `useProperties` + `usePropertyDetail` hooks

**Files:**
- Create: `hooks/useProperties.js`
- Create: `hooks/usePropertyDetail.js`

**What:** Follow the lint-clean pattern from `useDashboardOverview.js` exactly: `paramsRef`, `seqRef`, `inFlightRef`, pure `fetchData()` returning `{current, data, error}`, state updates inside `.then()` callbacks, `loading` derived from `settledParams`. `useProperties(params)` wraps `staffProperties.list`; `usePropertyDetail(id)` wraps `staffProperties.show`.

**Why:** oxlint `react/set-state-in-effect` is enforced by the lint gate; this pattern is the proven one in this codebase.

**Depends on:** Task 33.

### Task 35: Properties pages + BuildingForm

**Files:**
- Create: `pages/staff/Properties.jsx`
- Create: `pages/staff/PropertyForm.jsx`
- Create: `pages/staff/PropertyDetail.jsx`
- Create: `pages/staff/BuildingForm.jsx`

**What:**
- **Properties.jsx** — mirror `Rooms.jsx` structure: `useSearchParams` filters (`search`, `code`, `city`, `province_state`, `country`, `status`, `page`), `useProperties`, PropertyCard-style rows (name, code, city/country, status badge, building/room counts), `Link` to `/staff/properties/:id`, "New property" button visible to admin only (`useAuth().hasRole(['admin'])`). Receptionists: the backend returns only their property — the page renders it without filter selectors (`hasRole(['admin','manager'])` gate on the filter row).
- **PropertyForm.jsx** — create/edit (`/staff/properties/new`, `/:id/edit`), all spec fields with the same client-side validation + `getFieldErrors` pattern as `RoomForm.jsx`; lat/lng as `type="number" step="0.000001" min="-90"/"-180" max="90"/"180"`; submit → `staffProperties.create/update` → navigate to detail.
- **PropertyDetail.jsx** — sections: hotel info card (name, code, status badge, description, check-in/out, timezone, currency); address card; contacts card (phone/email/website); location card with **map toggle button** ("Show map"/"Hide map", default hidden) rendering when coordinates exist:
  ```jsx
  const { lat, lng } = { lat: Number(property.latitude), lng: Number(property.longitude) }
  const src = `https://www.openstreetmap.org/export/embed.html?bbox=${lng - 0.01}%2C${lat - 0.01}%2C${lng + 0.01}%2C${lat + 0.01}&layer=mapnik&marker=${lat}%2C${lng}`
  // <iframe title={`Map of ${property.name}`} src={src} className="h-64 w-full rounded-lg border-0" loading="lazy" />
  ```
  No key, no package, rendered only when toggled (spec §10). Buildings section lists each building (name, code, floor_count, room_count, occupied, occupancy_pct) with admin-only "New building"/"Edit" links. Summary cards: room count, occupied, occupancy %, verified revenue.
- **BuildingForm.jsx** — create (`/staff/properties/:id/buildings/new`) and edit (`/staff/buildings/:id/edit`) modes; name/code/description/floor_count/status; admin-gated by route.

**Why:** Admin UI per spec §6; all follow the existing design system (`.input`, `.label`, `.btn-outline`, card classes).

**Depends on:** Tasks 33, 34.

### Task 36: Sidebar + routes

**Files:**
- Modify: `components/staff/Sidebar.jsx`
- Modify: `routes/StaffApp.jsx`

**What:** Sidebar `NAV_ITEMS` gains `{ label: 'Properties', path: '/staff/properties' }` right after Rooms (no `roles` key — visible to all staff). StaffApp adds:

```jsx
<Route path="properties" element={<ProtectedRoute actor="staff"><Properties /></ProtectedRoute>} />
<Route path="properties/new" element={<ProtectedRoute actor="staff" roles={['admin']}><PropertyForm /></ProtectedRoute>} />
<Route path="properties/:id" element={<ProtectedRoute actor="staff"><PropertyDetail /></ProtectedRoute>} />
<Route path="properties/:id/edit" element={<ProtectedRoute actor="staff" roles={['admin']}><PropertyForm /></ProtectedRoute>} />
<Route path="properties/:id/buildings/new" element={<ProtectedRoute actor="staff" roles={['admin']}><BuildingForm /></ProtectedRoute>} />
<Route path="buildings/:id/edit" element={<ProtectedRoute actor="staff" roles={['admin']}><BuildingForm /></ProtectedRoute>} />
```

**Why:** Matches the exact ProtectedRoute/roles pattern of the existing room routes.

**Depends on:** Task 35.

### Task 37: Room form + room filters property/building support

**Files:**
- Modify: `pages/staff/RoomForm.jsx`
- Modify: `components/staff/RoomFilters.jsx`
- Modify: `pages/staff/Rooms.jsx`

**What:**
- RoomForm: fetch buildings grouped by property via `staffProperties.list({ per_page: 50 })` + per-property `listBuildings` (or a single `staffBuildings` list if you add `list: () => apiClient.get('/staff/buildings')` — prefer adding a `list` endpoint? **No new endpoint needed**: fetch properties, then `Promise.all(properties.map(p => staffProperties.listBuildings(p.id)))` via `useApi`; simpler and spec-compliant). Render a `<select name="building_id">` with `<optgroup label={property.name}>` per property; required client-side validation ("Select a building.") and include `building_id` in the payload; populate from `room.building_id` in edit mode.
- RoomFilters: when `hasRole(['admin','manager'])`, render property + building selects (property drives the building options) writing `property_id`/`building_id` into URL params with `page: 1`, same `commitParams` pattern. Receptionists: no selectors.
- Rooms.jsx: include `property_id`/`building_id` from search params in `apiParams`; add them to `CLEARED_FILTERS`.

**Why:** Spec §6 room form/filters; receptionists get server-scoped data without selectors.

**Depends on:** Tasks 28, 33.

### Task 38: Dashboard property selector

**Files:**
- Modify: `components/staff/DashboardOverview.jsx`
- Modify: `hooks/useDashboardOverview.js`
- Modify: `api/dashboardApi.js`

**What:**
- `useDashboardOverview({ from, to, groupBy, propertyId })` — add `propertyId` to `paramsRef`, `settledParams`, `loading` derivation, and deps (mechanical extension of the existing pattern).
- `dashboardApi.dashboardOverview.get/exportCsv` unchanged (params passed through).
- DashboardOverview: read `property_id` from `useSearchParams` (like `from`/`to`); fetch `staffProperties.list({ per_page: 50 })` via `useApi`; render a `<select>` ("All Properties" + each property) that commits `{ property_id: value || undefined }` to search params; pass `propertyId: Number(searchParams.get('property_id')) || undefined` into `useDashboardOverview` and into `exportCsv` params. **No page reload** — URL change alone refetches through the existing effect.

**Why:** Spec §7 global property selector; server-side filtering from Task 30; export inherits the filter.

**Depends on:** Tasks 30, 33.

### Task 39: Analytics + Reports filters

**Files:**
- Modify: `pages/staff/Analytics.jsx`
- Modify: `hooks/useAnalytics.js`
- Modify: `components/staff/ReportFilters.jsx`
- Modify: `pages/staff/Reports.jsx`
- Modify: `hooks/useReportSummary.js`

**What:**
- `useAnalytics` — extend params with `propertyId`, `buildingId`, `roomTypeId` (same mechanical pattern as Task 38); `useReportSummary` — thread `property_id`/`building_id`/`room_type_id` into `commonParams`/`bookingsParams`/`roomsFetcher`.
- Analytics.jsx — property/building selects (URL params, same commit pattern as DashboardOverview).
- ReportFilters.jsx — three new selects (property, building, room type — room type options from `staffRoomTypes.list`, building options depend on the chosen property) committing URL params; Reports.jsx passes them into `useReportSummary`. Exports/Print inherit automatically because they use the same params.

**Why:** Spec §8/§9; URL-param pattern keeps exports consistent with on-screen data.

**Depends on:** Tasks 30–32, 33.

- [ ] Step 1: Implement Tasks 33–39
- [ ] Step 2: `npm run lint` — 0 warnings
- [ ] Step 3: `npx vitest run` — existing 704 + new tests (Phase 8 frontend) PASS
- [ ] Step 4: `npm run build` — succeeds
- [ ] Step 5: Commit: `git add -A && git commit -m "feat(location): properties admin UI, dashboard selector, room/report/analytics filters"`

---

# PHASE 7 — Public booking flow

### Task 40: Property selector + property-aware search/availability

**Files:**
- Modify: `pages/customer/AvailabilitySearch.jsx`
- Modify: `pages/customer/Rooms.jsx`
- Modify: `pages/customer/BookingCheckout.jsx`

**What:**
- AvailabilitySearch: fetch `publicProperties.list()` via `useApi`; render a property `<select>` (default "All properties" → no param). When set, pass `property_id` to `publicRooms.listRooms` and to `checkAvailability`. Keep the chosen `property_id` in URL search params so the checkout can read it.
- customer Rooms.jsx: same optional property select feeding `publicRooms.listRooms({ property_id })`.
- BookingCheckout: read `property_id` from search params and include it in the booking payload (`property_id: Number(...)` when present). The backend re-validates against the rooms regardless (Task 19) — the frontend value is a convenience, never trusted.

**Why:** The booking site becomes property-aware; backend validation remains the authority (spec §7.4).

**Depends on:** Tasks 25, 29, 33.

- [ ] Step 1: Implement
- [ ] Step 2: `npm run lint` + `npx vitest run` (update `availabilitySearch.test.jsx`, `bookingCheckout.test.jsx` mocks for the new endpoint/props) — PASS
- [ ] Step 3: Commit: `git add -A && git commit -m "feat(location): property-aware public booking flow"`

---

# PHASE 8 — Tests

### Task 41: Backend — property/building CRUD tests

**Files:**
- Create: `tests/Feature/Property/PropertyCrudTest.php`
- Create: `tests/Feature/Property/BuildingCrudTest.php`

**What (PropertyCrudTest):** admin creates property (201, fields persisted incl. lat/lng decimals); code duplicate → 422; lat 91 / lng 181 / `lat: "abc"` → 422; bad email/website → 422; list filters (search by name/code/city/province/country, status) return subsets; update as admin works; delete soft-deletes (subsequent list omits it); manager POST → 403; receptionist POST → 403; receptionist list returns only their own property; public list returns only active.

**What (BuildingCrudTest):** admin creates building under property (201); duplicate code in same property → 422; same code in different property → 201; update/delete admin-only (manager 403); building list nested returns property's buildings.

### Task 42: Backend — analytics/report filter tests

**Files:**
- Create: `tests/Feature/Property/AnalyticsFilterTest.php`
- Create: `tests/Feature/Property/ReportFilterTest.php`

**What:** Seed property A (Main) and property B; two bookings (one per property, in the window), one verified payment per property; then:

```php
test('dashboard property filter changes SQL-derived counts', function () {
    $token = loginAsEmployee($this, 'filteradmin@example.com', 'admin');
    $all = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/dashboard?from='.$from.'&to='.$to);
    $filtered = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/dashboard?from='.$from.'&to='.$to.'&property_id='.$propertyB->id);

    expect($filtered->json('data.summary.total_bookings'))->toBe(1);
    expect($all->json('data.summary.total_bookings'))->toBe(2);
    // room_occupancy, room_types, booking_trend similarly scoped
});
test('analytics room occupancy is property-scoped', function () { /* ... */ });
test('reports bookings/revenue respect property_id', function () { /* /staff/reports/bookings?property_id=B -> total 1 */ });
test('report CSV export respects property_id', function () {
    // GET /staff/reports/bookings/export?property_id=B -> 200 text/csv, content contains B-only totals
});
test('dashboard CSV export respects property_id', function () { /* ... */ });
```

### Task 43: Backend — employee validation + regression updates

**Files:**
- Modify: `tests/Feature/Employee/StaffEmployeeTest.php` (add property_id cases: receptionist without property → 422; admin with property → 422; receptionist with valid property → 201, response includes property_id)
- Modify: `tests/Feature/Room/StaffRoomTest.php` (room create now requires building_id → 422 without; existing create tests pass `'building_id' => makeBuilding()->id`)
- Full-suite regression: `php artisan test` — all 517 existing + new tests green.

### Task 44: Frontend tests

**Files:**
- Create: `test/propertyApi.test.js` — axios-mock-adapter: endpoint/params/error mapping for every `staffProperties`/`publicProperties` call
- Create: `test/staffProperties.test.jsx` — list renders properties; filters write URL params; admin sees "New property", receptionist doesn't
- Create: `test/staffPropertyForm.test.jsx` — validation messages; create/edit payloads include all fields; lat/lng bounds
- Create: `test/staffPropertyDetail.test.jsx` — summary cards render; map iframe **absent by default**, appears after clicking "Show map", absent when coords are null; buildings list renders counts
- Create: `test/staffBuildingForm.test.jsx` — create/edit payloads
- Create: `test/propertyScopeUi.test.jsx` — dashboard selector changes `property_id` param and refetches (mock `useDashboardOverview` params); ReportFilters property/building selects round-trip URL params; RoomForm building select required
- Modify: `test/staffRoomForm.test.jsx`, `test/staffRooms.test.jsx`, `test/staffDashboard.test.jsx`, `test/reportsSection.test.jsx`, `test/useAnalytics.test.jsx`, `test/availabilitySearch.test.jsx`, `test/bookingCheckout.test.jsx` — update mocks/expectations for new endpoints and params

**Testing:** `npx vitest run` — all pass; `npm run lint` — 0 warnings.

- [ ] Step 1: Implement Tasks 41–44
- [ ] Step 2: `php artisan test` — full backend suite PASS
- [ ] Step 3: `npx vitest run` + `npm run lint` — PASS
- [ ] Step 4: Commit: `git add tests/ && git commit -m "test(location): property/building CRUD, scoping, filtering and UI tests"`

---

# PHASE 9 — Verification

### Task 45: Full verification gate

- [ ] Step 1: Backend: `php artisan test` — full suite green (expect ~550+ tests)
- [ ] Step 2: `vendor/bin/pint --test $(git ls-files -m | grep '\.php$')` — clean (run Pint fix if needed, then re-run tests)
- [ ] Step 3: **Migrate the dev DB** (memory: forgetting this produces SQLSTATE 42S22 in the browser): `php artisan migrate` against `hotel_management_v2`, then verify backfill:
      `php artisan tinker --execute="echo App\Models\Room::withoutGlobalScopes()->whereNull('building_id')->count();"` → `0`
      `php artisan tinker --execute="echo App\Models\Employee::where('role','receptionist')->whereNull('property_id')->count();"` → `0`
- [ ] Step 4: Frontend: `npx vitest run` (all pass), `npm run lint` (0 warnings), `npm run build` (succeeds)
- [ ] Step 5: Browser smoke test (browser-use; the browser is shared — keep UI actions atomic in single `evaluate_script` calls):
      1. Admin login (`admin@test.local` / `12345678`) → Properties in sidebar → create "Hotel B" (`B`) → detail page shows summary cards + map toggle renders iframe → create a building → Rooms page filter by property
      2. Dashboard → property selector "Hotel B" → KPIs change (server-side, verified via network request carrying `property_id`)
      3. Public site (`/` customer flow): property selector lists Main Hotel + Hotel B; availability for a Main-Hotel room with `property_id=B` returns nothing
      4. Receptionist login (`receptionist@test.local`) → sees only Main Hotel rooms; request to `/staff/properties/{hotelB-id}` → 403; booking lifecycle on a Hotel-B booking → 403
      5. Existing pages spot-check: Bookings, Payments, Invoices, Reports, Analytics, Calendar, Discounts, Room Types all render with live data
- [ ] Step 6: Report verification results; update project memory `dev-environment-setup.md` with any new gotchas (e.g., new test users or seed conventions for properties)

---

## Self-review notes (checklist the implementer must re-run before calling done)

1. **Spec coverage:** §4 (tables) → Tasks 1–5; §5 (migrations/backfill) → Tasks 3–4, 6; §6 (models) → 7–13; §7 (API) → 14–29; §8 (scoping/auth) → 13–14, 16, 20–21; §9–§10 (frontend/integration) → 30–39; §11 (testing) → 41–44; §12–§13 (non-goals/risks) → respected throughout (no Floor table, no new deps, active-chain exclusions).
2. **Placeholder scan:** every task contains exact paths, signatures, and code for the non-obvious parts. No TBDs.
3. **Type consistency:** `PropertyScope::effectiveId(Employee, ?int): ?int`, `Booking::scopeByProperty(Builder, int)`, `searchAvailable(..., ?int $propertyId = null)`, `analytics(from, to, groupBy, ?int $propertyId, ?int $buildingId, ?int $roomTypeId)` — used identically in controllers and tests above.
