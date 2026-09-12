# STEP 06 — Room Type Management — Implementation Report

Plan is in `docs/STEP-06-ROOM-TYPE-PLAN.md` (written first, per
instruction), including the discovered schema issue (§0) flagged before
any code was touched. This covers what was actually built on top of it.

---

## 1. Files Created / Modified

**Created:**
- `database/migrations/2025_02_03_000001_drop_unique_name_on_room_types_table.php`
  — the schema fix from plan §0
- `app/Models/RoomType.php`
- `app/Policies/RoomTypePolicy.php`
- `app/Services/RoomTypeService.php`
- `app/Http/Requests/RoomType/{Index,Store,Update}Request.php`
- `app/Http/Controllers/Api/V1/Public/RoomTypeController.php`
- `app/Http/Controllers/Api/V1/Staff/RoomTypeController.php`
- `tests/Feature/RoomType/PublicRoomTypeTest.php` (4 tests)
- `tests/Feature/RoomType/StaffRoomTypeTest.php` (30 tests)

**Modified:**
- `app/Http/Controllers/Controller.php` — added `AuthorizesRequests`,
  resolving the STEP 02 placeholder ("added if/when a controller needs
  `$this->authorize()`" — this is that moment)
- `routes/api.php` — added public and staff `room-types` routes
- `tests/Pest.php` — `loginAsEmployee()` moved here from STEP 05's
  `AuthorizationTest.php` (a second file now needs it; redeclaring a
  global function in two files is a fatal error), plus a new
  `loginAsCustomer()` helper
- `tests/Feature/AuthorizationTest.php` — removed the now-duplicate
  inline `loginAsEmployee()` declaration

## 2. API Endpoints

| Method | URL | Auth | Roles |
|---|---|---|---|
| GET | `/api/v1/public/room-types` | None | — |
| GET | `/api/v1/public/room-types/{roomType}` | None | — |
| GET | `/api/v1/staff/room-types` | sanctum+actor | any employee |
| GET | `/api/v1/staff/room-types/{roomType}` | sanctum+actor | any employee |
| POST | `/api/v1/staff/room-types` | sanctum+actor+role | admin, manager |
| PUT | `/api/v1/staff/room-types/{roomType}` | sanctum+actor+role | admin, manager |
| DELETE | `/api/v1/staff/room-types/{roomType}` | sanctum+actor+role | admin, manager |

## 3. Validation Rules

See plan §3 for the full table — implemented exactly as specified:
`name` unique among non-deleted rows only (the schema fix makes this
actually correct, not just validated-but-still-breakable),
`base_price` ≥ 0, `capacity_adults` ≥ 1, `capacity_children` ≥ 0,
`amenities` a flat array of ≤30 strings (≤100 chars each) — no nested
structures accepted. `PUT` uses full-replacement semantics (all fields
required), matching REST convention.

## 4. Authorization Behavior

Two independent layers, both real, not redundant-for-show:
`role:admin,manager` middleware rejects receptionist/customer before the
controller runs; `$this->authorize()` against `RoomTypePolicy`
re-confirms the same rule against the specific resource inside the
controller. Public routes touch neither — no `actor:*`, no Policy call
— browsing is unauthenticated by design.

## 5. Query / Filter / Sort / Pagination Behavior

Search matches `name` (partial, case-insensitive `LIKE`). Filters:
`min_price`/`max_price`, `min_adults`/`min_children`. Sort: allowlisted
to `name`/`base_price`/`created_at` — enforced twice (FormRequest
validation rejects anything else with 422 before the query runs; the
Service independently re-checks the same allowlist as a defensive
second layer). Pagination: `per_page` 1–50, default 15.

## 6. Tests Added (34 new — 96 total across the whole suite)

Public (4): list, view, soft-deleted excluded from list, soft-deleted
direct view → 404. Staff (30): Admin full CRUD (5), Manager
create/update/delete (3), Receptionist view-only + 3 forbidden actions
(4), Customer blocked from all management routes (1), validation —
duplicate active name, **soft-deleted name reuse** (directly proves the
schema fix), self-update doesn't false-positive as a duplicate, negative
price, invalid adult/child capacity, malformed amenities shape,
missing-required-fields (8), query features — search, price range,
capacity filters, sort, SQL-injection-shaped sort rejected by the
allowlist, pagination + safe maximum (7), deleted-resource 404 on
view/update/delete, unauthenticated → 401 (2).

## 7. Test Command

```bash
php artisan test --filter=RoomType
php artisan test
```

Expected: **96 passed** (62 from STEP 02–05 + 34 new). Nothing in this
step touched STEP 02–05 application code (only the two test-helper files
noted in §1, for organizational reasons, not behavior changes) — no
regression expected.

## 8. Verification Results

| Item | Status |
|---|---|
| Schema issue identified, explained, and flagged before changing anything | **TESTED** — reasoned through carefully (§0 of the plan), not assumed |
| MySQL `UNIQUE(name, deleted_at)` composite-index alternative considered and rejected | **TESTED** — reasoned through the NULL-handling semantics explicitly, not guessed |
| All new/modified files pass `php -l` | **TESTED**, in this sandbox |
| Full-project syntax sweep after this step | **TESTED**, clean |
| Route model binding correctly excludes soft-deleted `RoomType` records | **DESIGNED** — relies on Eloquent's documented `SoftDeletes` global-scope behavior applying transparently to implicit binding; not independently re-verified via search this step (long-stable Laravel behavior, unlike the Sanctum/Pest version specifics that needed checking earlier) |
| `php artisan test` → 96 passed | **NOT VERIFIED** — same sandbox limitation as every prior step. Needs your run. |

## 9. Known Limitations

- **Uniqueness-among-active-names has a small race-condition window**
  (plan §0's Impact section) — two simultaneous create requests with the
  same brand-new name could theoretically both pass validation. Accepted
  trade-off for an infrequent, Admin/Manager-only action; flagged, not
  hidden. A MySQL generated-column partial-unique-index would close this
  gap with real added schema complexity, if you'd rather have it.
- **No guard yet against deleting a room type that has rooms** — there
  are no rooms yet. STEP 07 must add this (plan §5); the database's
  `restrictOnDelete` FK cannot catch it because soft-delete is an
  `UPDATE`, not a `DELETE`, at the SQL level.
- **No API Resource classes** — `RoomType` is serialized directly
  (casts handle shape), consistent with how STEP 04 handled
  `Customer`/`Employee`. `RoomType` has no sensitive fields to hide, so
  this isn't a security gap, just a style consistency note.
- **`amenities` validation caps at 30 items / 100 chars each** — a
  reasonable-seeming default I chose, not a number given in the spec;
  easy to adjust if it doesn't match real requirements.

STOPPING HERE. STEP 07 has not started. Waiting for your test results
before anything further.
