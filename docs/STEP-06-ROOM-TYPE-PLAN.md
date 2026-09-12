# STEP 06 — Room Type Management Plan (review before implementation below)

## 0. Discovered Schema Issue — Read First

**PROBLEM.** STEP 06 requires "name uniqueness among **active** room
types" — a soft-deleted room type's name should be reusable. The existing
migration has `$table->string('name')->unique()`, a plain database-level
unique index with no awareness of `deleted_at`.

**WHY.** Soft-deleting a row (`$model->delete()` with `SoftDeletes`)
issues an `UPDATE ... SET deleted_at = ...`, not a real `DELETE FROM` — so
the row physically stays in the table, and MySQL's unique index still
sees it. Enforcing "unique among active" purely at the *validation* layer
while the *database* still has a plain unique constraint would create a
real bug: validation could pass (correctly checking only non-deleted
rows) and the `INSERT` could still fail with a duplicate-key error,
because the database constraint doesn't know about soft-deletes at all.

I also checked whether a composite `UNIQUE(name, deleted_at)` index would
fix this — it doesn't. MySQL treats `NULL` as distinct from `NULL` in
unique indexes, so a composite index including the nullable
`deleted_at` column would actually stop enforcing uniqueness among
*active* rows (all have `deleted_at = NULL`), which is the exact case
that matters most. This is a well-known MySQL gotcha with this pattern,
not something specific to this project.

**RECOMMENDED SOLUTION.** A new migration drops the plain unique index on
`name` alone. Uniqueness-among-active is enforced at the validation layer
instead (`Rule::unique('room_types', 'name')->whereNull('deleted_at')`),
accepting a small, well-understood trade-off (below).

**IMPACT.** Uniqueness enforcement moves from an unconditional database
guarantee to an application-level check. In practice this means: two
*simultaneous* requests creating a room type with the same brand-new name
could theoretically both pass validation and both succeed, since nothing
at the database level would catch it. This is a real trade-off, not
nothing — but reasonable here: room-type creation is an infrequent,
Admin/Manager-only action, not a high-concurrency public endpoint. The
alternative (a MySQL generated-column partial-unique-index trick) works
but adds real schema complexity for a race condition this endpoint's
actual usage pattern makes very unlikely. Flagging clearly rather than
deciding silently, per instruction — implementing this as the minimal
fix; happy to build the generated-column version instead if you'd rather
have the stronger guarantee.

New migration (does not touch the original STEP 03 file, which is
already applied):
```php
Schema::table('room_types', function (Blueprint $table) {
    $table->dropUnique(['name']);
});
```

---

## 1. Confirmed: No Other Schema Changes Needed

Every other approved field (`id`, `name`, `description`, `base_price`,
`capacity_adults`, `capacity_children`, `amenities`, timestamps,
soft-deletes) already exists exactly as specified from STEP 03. Only the
index above changes.

## 2. API Contract

| Method | URL | Auth | Roles |
|---|---|---|---|
| GET | `/api/v1/public/room-types` | None | — |
| GET | `/api/v1/public/room-types/{roomType}` | None | — |
| GET | `/api/v1/staff/room-types` | `auth:sanctum` + `actor:staff` | any |
| GET | `/api/v1/staff/room-types/{roomType}` | `auth:sanctum` + `actor:staff` | any |
| POST | `/api/v1/staff/room-types` | + `role:admin,manager` | admin, manager |
| PUT | `/api/v1/staff/room-types/{roomType}` | + `role:admin,manager` | admin, manager |
| DELETE | `/api/v1/staff/room-types/{roomType}` | + `role:admin,manager` | admin, manager |

Route model binding is implicit (`{roomType}` → `RoomType $roomType`).
**Soft-deleted records are automatically excluded** — Eloquent's
`SoftDeletes` global scope applies to the query implicit binding uses
under the hood, so a deleted room type's ID resolves to a clean 404
(`ModelNotFoundException`, already handled since STEP 02) with zero extra
code. No restore endpoint, per instruction.

## 3. Validation Rules

**Create / Update** (`PUT` is full-replacement semantics, all fields
required, matching REST convention for `PUT` vs `PATCH`):

| Field | Rules |
|---|---|
| `name` | required, string, max:255, unique among non-deleted rows (update ignores its own row) |
| `description` | nullable, string, max:2000 |
| `base_price` | required, numeric, min:0, max:999999.99 |
| `capacity_adults` | required, integer, min:1, max:20 |
| `capacity_children` | required, integer, min:0, max:20 |
| `amenities` | nullable, array, max 30 items |
| `amenities.*` | string, max:100 — a flat array of short strings, e.g. `["WiFi", "Mini Bar"]`; rejects nested objects/arrays or any other shape |

**Index/list (search+filter+sort+pagination)** — same rules for both
public and staff, since the querying behavior is identical:

| Param | Rules |
|---|---|
| `search` | nullable, string, max:255 |
| `min_price` / `max_price` | nullable, numeric, min:0 (`max_price` ≥ `min_price`) |
| `min_adults` / `min_children` | nullable, integer, min:0 |
| `sort` | nullable, **allowlist**: `name`, `base_price`, `created_at` |
| `direction` | nullable, `asc`\|`desc` |
| `per_page` | nullable, integer, 1–50 |

An invalid `sort` value is rejected by validation (422) before it ever
reaches the query — it never falls through to a "default" silently, and
it's never string-interpolated into SQL regardless.

## 4. Authorization Matrix

| | Admin | Manager | Receptionist | Customer |
|---|:---:|:---:|:---:|:---:|
| List/View (staff) | ✅ | ✅ | ✅ | – |
| List/View (public) | – | – | – | ✅ (no auth) |
| Create | ✅ | ✅ | ❌ | ❌ |
| Update | ✅ | ✅ | ❌ | ❌ |
| Delete (soft) | ✅ | ✅ | ❌ | ❌ |

Enforced in two layers, matching the specified flow exactly
(defense-in-depth, not redundancy for its own sake): `role:admin,manager`
middleware blocks receptionists/customers before the controller runs at
all; `$this->authorize()` against `RoomTypePolicy` inside the controller
re-checks the same rule against the specific resource. Public routes call
neither `actor:*` nor the Policy — browsing is deliberately open.

## 5. Service / Policy Structure

```
Route → role middleware (write actions only) → FormRequest → Controller
      → $this->authorize() (RoomTypePolicy) → RoomTypeService → RoomType → DB
```

`RoomTypeService` owns: the search/filter/sort/pagination query
(allowlist enforced twice — once in validation, once again defensively
in the service itself), create, update, and delete. `RoomTypePolicy`
owns: `viewAny`/`view` (true for any `Employee`), `create`/`update`/
`delete` (`admin`/`manager` only).

**A forward-looking note, not something built now:** soft-delete does
**not** trigger the `restrictOnDelete` foreign-key constraint on
`rooms.room_type_id` from STEP 03 — that constraint only fires on a real
`DELETE FROM`, and soft-delete is an `UPDATE`. There's nothing to
orphan yet (no Rooms module exists), so no guard is added this step. But
**STEP 07 must add an application-level check** (e.g.
`$roomType->rooms()->exists()`) before allowing a room type to be
soft-deleted once real rooms can reference one — the database cannot
enforce this the way it does for hard deletes. Flagging now so it isn't
missed later.

## 6. Test Plan

~33 new tests across two files: `tests/Feature/RoomType/PublicRoomTypeTest.php`
(public browsing, soft-delete exclusion) and
`tests/Feature/RoomType/StaffRoomTypeTest.php` (full CRUD × all 4 roles,
validation, query features, deleted-resource behavior, unauthenticated
access). Includes a dedicated test proving the schema fix in §0 actually
works: a soft-deleted room type's name can be reused by a new one.

Two small test-infrastructure changes: the `loginAsEmployee()` helper
written inline in STEP 05's `AuthorizationTest.php` moves to
`tests/Pest.php` (shared location — this file will need it too, and
redeclaring the same function in two files would be a fatal error), and
a matching `loginAsCustomer()` helper is added alongside it.

---

Implementation follows below.
