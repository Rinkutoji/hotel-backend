# STEP 03 — Database & Migrations — Implementation Report

Plan is in `docs/STEP-03-DATABASE-PLAN.md` (written first, per instruction).
This covers what was actually built on top of it.

---

## 1. Files Created / Modified

**Created — 10 migrations** (`database/migrations/`):
`2025_02_01_000001_create_departments_table.php` through
`..._000010_create_room_status_logs_table.php` — see §2 for exact order.

**Created — tests:**
- `tests/Feature/DatabaseSchemaTest.php` (20 tests — migrations, FK
  enforcement, unique constraints, required/nullable fields,
  relationships, soft-delete behavior)

**Modified:**
- `tests/Pest.php` — added `RefreshDatabase` for the `Feature` suite, so
  migrations actually run before each test (STEP 02's tests didn't need
  this; STEP 03's do)
- `database/seeders/DatabaseSeeder.php` — removed the default
  `User::factory()->create()` call (that model no longer exists), left
  intentionally empty — see plan §6 item 6

**Removed** (resolves the flag carried since STEP 02):
- `database/migrations/0001_01_01_000000_create_users_table.php`,
  `..._000001_create_cache_table.php`, `..._000002_create_jobs_table.php`
- `app/Models/User.php`
- `database/factories/UserFactory.php`

**Deliberately untouched:** `config/auth.php` still references
`App\Models\User::class` and a `users` provider. This is dormant, not
broken — `::class` resolves to a plain string at parse time and doesn't
require the class to exist unless something actually tries to use it,
and nothing does yet (no auth routes exist). Reconfiguring guards/providers
to point at `customers`/`employees` instead is STEP 04's job, not database
design's.

## 2. Migration Execution Order

1. `departments`
2. `customers`
3. `employees` (→ departments)
4. `room_types`
5. `rooms` (→ room_types)
6. `room_images` (→ room_types)
7. `bookings` (→ customers)
8. `booking_rooms` (→ bookings, rooms, room_types)
9. `payments` (→ bookings, employees)
10. `room_status_logs` (→ rooms, employees)

Enforced by filename timestamp, not alphabetical table name — each table
only references tables migrated before it.

## 3. Database Structure Summary

10 tables, 11 relationships (including the 1─1 `bookings↔payments`),
4 tables with soft-delete (`customers`, `employees`, `rooms`,
`room_types`), 6 native MySQL ENUM columns modeling the approved status
lists exactly, every currency column `decimal(10,2)`. Full detail —
column-by-column, every constraint and index with its reasoning — is in
`docs/STEP-03-DATABASE-PLAN.md`, not repeated here.

## 4. Commands to Run

```bash
php artisan migrate
php artisan migrate:status
```

To confirm it's reversible (then bring it back):
```bash
php artisan migrate:rollback
php artisan migrate
```

## 5. Test Commands

```bash
php artisan test --filter=DatabaseSchemaTest
php artisan test
```

## 6. Expected Results

- `php artisan migrate` — 10 migrations run, no errors
- `php artisan migrate:status` — all 10 show `Ran`
- `php artisan test --filter=DatabaseSchemaTest` — **20 passed**
- `php artisan test` (full suite) — **27 passed** (7 from STEP 02's
  `FoundationTest` + 20 new)

## 7. Verification Checklist

- [ ] `php artisan migrate` completes with no errors against real MySQL
- [ ] `php artisan migrate:status` shows all 10 as `Ran`, in the order in §2
- [ ] `php artisan test` → 27 passed, 0 failed
- [ ] In MySQL directly (Workbench/phpMyAdmin/`SHOW CREATE TABLE`),
      spot-check: `payments.booking_id` has a UNIQUE index, not just a
      plain FK index
- [ ] Spot-check `rooms.status` is a real ENUM column (not varchar) via
      `SHOW COLUMNS FROM rooms`
- [ ] `php artisan migrate:rollback` then `php artisan migrate` round-trips
      cleanly (confirms every `down()` is correct, not just every `up()`)

## 8. Known Limitations

- **Not executed against real MySQL here** — same sandbox limitation as
  every prior step (no Packagist access → no working `vendor/` in this
  environment). Every migration was syntax-checked (`php -l`, all 10
  clean) and reviewed column-by-column against the approved schema, but
  "parses correctly" and "creates the exact intended MySQL schema when
  actually run" are different claims. The checklist above is what
  closes that gap on your machine.
- **Tests run against SQLite** (`:memory:`, per `phpunit.xml` from
  STEP 02), not MySQL. Foreign keys, unique constraints, and NOT NULL
  enforcement all behave equivalently between the two for what these
  tests check, and Laravel's `enum()` migration helper correctly emits a
  MySQL-native ENUM on the mysql driver and an equivalent CHECK
  constraint on sqlite - but "equivalent for testing purposes" isn't
  "identical." The MySQL-specific spot-checks in the verification
  checklist above are the way to confirm the real thing, not the test
  suite's proxy for it.
- **No Eloquent models yet** — by design. Per the convention already
  documented in `app/Models/README.md` since STEP 02, models arrive with
  each table's owning business module (Customers in STEP 09, Room Types
  in STEP 06, etc.), not all at once here. `DatabaseSchemaTest.php`
  exercises the schema directly via `DB::table()`/`Schema::`, which is
  sufficient to verify the database itself without getting ahead of
  later steps.
- **No seed data** — see plan §6 item 6. Nothing was blocking on it.

STOPPING HERE. STEP 04 has not started. Waiting for your migration/test
results before anything further.
