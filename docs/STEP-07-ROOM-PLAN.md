# STEP 07 — Room Management Plan (review before implementation below)

## 0. Discovered Schema Issues — Read First

**Issue A — same pattern as STEP 06.**

PROBLEM: `room_number` needs to be "unique among active rooms," but the
existing migration has `$table->string('room_number')->unique()` — an
unconditional database constraint that doesn't know about soft-deletes.

WHY: Identical reasoning to the `room_types.name` fix in STEP 06 — a
soft-deleted row stays physically present, so a plain unique index still
blocks reusing its `room_number`, and a composite
`UNIQUE(room_number, deleted_at)` doesn't fix it either (MySQL treats
`NULL` as distinct from `NULL`, so it would stop enforcing uniqueness
among active rows, the case that matters).

RECOMMENDED SOLUTION: same fix as STEP 06 — drop the plain unique index,
enforce "unique among active" at the validation layer
(`Rule::unique('rooms','room_number')->whereNull('deleted_at')`).

IMPACT: same trade-off as STEP 06, restated for this table — a small
race-condition window on simultaneous creates with a brand-new room
number, on an infrequent Admin/Manager-only action.

**Issue B — new, not previously flagged.**

PROBLEM: STEP 07 requires `floor` to be **required, integer**. The
existing migration has `$table->string('floor')->nullable()`.

WHY: Back in STEP 03, `floor` was left nullable/string as an explicitly
flagged default assumption (§6 of the STEP 03 report) — no spec existed
yet, and I wanted to support non-numeric labels like "G" or "PH". STEP 07
now gives an explicit, firm requirement that supersedes that guess.

RECOMMENDED SOLUTION: a new migration drops the `floor` column and
re-adds it as `unsignedSmallInteger`, not nullable. I'm using
drop-then-add rather than Laravel's `->change()` method deliberately —
`->change()` for altering an existing column's *type* has a history of
requiring the `doctrine/dbal` package on some Laravel versions, which
isn't installed and isn't otherwise needed anywhere in this project.
Drop-then-add uses only `dropColumn()`/`unsignedSmallInteger()`, both
natively supported with no extra dependency, at the cost of two
`ALTER TABLE` statements instead of one — a trivial cost for a
zero-downtime-irrelevant dev-stage migration.

"Reasonable positive range" is interpreted as: DB type is `unsignedSmallInteger`
(0–65535, a coarse guard), actual business range enforced at validation
as `min:1, max:200` (basements/ground-floor-zero excluded, matching
"positive" literally — flag if basement support is actually wanted, that
would need a different, signed design).

IMPACT: safe — no real room data exists yet (rooms don't become a usable
concept until this step's CRUD ships), so there's nothing to migrate or
lose.

Both fixes land in one migration,
`database/migrations/2025_02_04_000001_fix_rooms_table_constraints.php`.

---

## 1. API Contract

| Method | URL | Auth | Roles |
|---|---|---|---|
| GET | `/api/v1/public/rooms` | None | — |
| GET | `/api/v1/public/rooms/{room}` | None | — |
| GET | `/api/v1/staff/rooms` | sanctum+actor | any employee |
| GET | `/api/v1/staff/rooms/{room}` | sanctum+actor | any employee |
| POST | `/api/v1/staff/rooms` | +role | admin, manager |
| PUT | `/api/v1/staff/rooms/{room}` | +role | admin, manager |
| DELETE | `/api/v1/staff/rooms/{room}` | +role | admin, manager |
| POST | `/api/v1/staff/rooms/{room}/status/maintenance` | +role | admin, manager, **receptionist** |
| POST | `/api/v1/staff/rooms/{room}/status/available` | +role | admin, manager, **receptionist** |

Public browsing shows all non-deleted rooms regardless of current
`status` — no status-based filtering. True booking-availability (status
+ date range) is Booking-module territory (STEP 10+); approximating it
half-built here would be exactly the "don't fake the workflow"
instruction applied to the wrong module.

## 2. Status Transition Matrix

Only the two maintenance-related endpoints exist this step. Everything
else in the full lifecycle (`reserved`, `occupied`, `cleaning` as a
*destination*) is booking/check-in/check-out-driven and explicitly
deferred — `cleaning → available` specifically belongs to STEP 13
(Cleaning/Room Status), not here.

| From | To | Endpoint |
|---|---|---|
| `available` | `maintenance` | `POST .../status/maintenance` |
| `cleaning` | `maintenance` | `POST .../status/maintenance` |
| `maintenance` | `available` | `POST .../status/available` |
| anything else | anything else | rejected — `409 Conflict` |

A room can't currently *reach* `cleaning` through any real API call yet
(that requires checkout, which doesn't exist) — the `cleaning →
maintenance` rule is still implemented and tested (state set directly in
test setup), since the transition rule itself is real and approved even
though its starting state isn't reachable through the app yet.

Invalid transitions return **409 Conflict** via a new
`App\Exceptions\BusinessRuleException` — distinct from 422 (malformed
input) and 403 (wrong role) since the request is well-formed and
authorized, it just conflicts with the resource's current state. The
same exception type is reused for the Room Type deletion guard (§4).

## 3. Authorization Matrix

| | Admin | Manager | Receptionist | Customer |
|---|:---:|:---:|:---:|:---:|
| View (staff) | ✅ | ✅ | ✅ | – |
| View (public) | – | – | – | ✅ |
| Create | ✅ | ✅ | ❌ | ❌ |
| Update | ✅ | ✅ | ❌ | ❌ |
| Delete | ✅ | ✅ | ❌ | ❌ |
| Change status | ✅ | ✅ | **✅** | ❌ |

Same two-layer enforcement as STEP 06 (`role:*` middleware + Policy
re-check in the controller) — status-change routes get a **broader**
role list (`role:admin,manager,receptionist`) than create/update/delete
(`role:admin,manager`), matching the matrix exactly.

## 4. Service / Policy Structure

```
Route → role middleware → FormRequest → Controller → $this->authorize()
      → RoomService → Room / RoomStatusLog models → DB
```

`RoomPolicy`: `viewAny`/`view` (any `Employee`), `create`/`update`/
`delete` (admin/manager), `changeStatus` (admin/manager/receptionist).

`RoomService` owns: search/filter/sort/pagination, create (explicitly
sets `status = 'available'`, never from client input — see §5), update,
delete, and `transitionStatus()` (validates against the matrix in §2,
writes the room_status_logs row).

**The STEP 07 headline rule, implemented in `RoomTypeService::delete()`
(STEP 06's file, modified here):**
```php
if ($roomType->rooms()->exists()) {
    throw new BusinessRuleException('Cannot delete a room type that has physical rooms.');
}
```
Checks only *active* rooms (the default `rooms()` relationship already
excludes soft-deleted ones via `Room`'s own global scope) — a room type
whose rooms have all been individually soft-deleted is deletable, since
nothing active references it anymore.

## 5. Models

`Room` (new) and `RoomStatusLog` (new — STEP 03 built the table, no
model existed yet; this is genuinely the step that needs it, not a
premature addition). `RoomType` gains the `hasMany(Room::class)`
relationship, replacing the placeholder comment left there since STEP 06.

**Deliberate `$fillable` choice:** `status`, `status_changed_at`, and
`cleaning_started_at` are **not** in `Room::$fillable`. Every FormRequest
already excludes `status` from its rules (clients can't submit it), but
keeping it out of `$fillable` too is a second, independent layer — even
a future bug that accidentally mass-assigned raw request data couldn't
set status through it. Status is only ever set via explicit property
assignment inside `RoomService`.

## 6. Test Plan

Four new files: `PublicRoomTest.php`, `StaffRoomTest.php` (CRUD × roles,
validation, query features), `RoomStatusTest.php` (transition matrix,
audit log, receptionist-can/customer-cannot), `RoomTypeIntegrationTest.php`
(relationship + the deletion-guard headline rule). Roughly 40 tests,
covering every category listed in the instructions — including a direct
test that a `PUT` sending `"status": "occupied"` in the body is silently
ignored, not applied, proving the exclusion in §5 actually holds.

---

Implementation follows below.
