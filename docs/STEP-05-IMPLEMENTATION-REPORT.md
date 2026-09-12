# STEP 05 — Authorization — Implementation Report

Plan is in `docs/STEP-05-AUTHORIZATION-PLAN.md` (written first, per
instruction). This covers what was actually built on top of it.

---

## 1. Files Created / Modified

**Created:**
- `app/Http/Middleware/EnsureStaffHasRole.php`
- `app/Policies/CustomerPolicy.php` (replaces the placeholder `README.md`
  that lived in `app/Policies/` since STEP 02)
- `tests/Feature/AuthorizationTest.php` — 14 tests

**Modified:**
- `bootstrap/app.php` — registered the `role` middleware alias, resolving
  the placeholder comment left since STEP 04
- `app/Providers/AppServiceProvider.php` — registered the
  `process-refund` `Gate`
- `routes/api.php` — added the `_authz-check` fixture routes inside the
  existing `staff` group (see §3)

## 2. Middleware Aliases

| Alias | Class | Added |
|---|---|---|
| `actor` | `App\Http\Middleware\EnsureActor` | STEP 04 |
| `role` | `App\Http\Middleware\EnsureStaffHasRole` | STEP 05 |

## 3. Route Protection Examples

```php
// Any authenticated employee, any role:
Route::get('/x', ...)->middleware(['auth:sanctum', 'actor:staff']);

// Admin, Manager, or Receptionist:
Route::get('/x', ...)->middleware(['auth:sanctum', 'actor:staff', 'role:admin,manager,receptionist']);

// Admin or Manager only:
Route::get('/x', ...)->middleware(['auth:sanctum', 'actor:staff', 'role:admin,manager']);

// Admin only:
Route::get('/x', ...)->middleware(['auth:sanctum', 'actor:staff', 'role:admin']);
```

The four fixture routes actually added (temporary, see plan §6):

| Route | Roles allowed |
|---|---|
| `GET /api/v1/staff/_authz-check/any-staff` | any employee |
| `GET /api/v1/staff/_authz-check/receptionist-task` | admin, manager, receptionist |
| `GET /api/v1/staff/_authz-check/manager-task` | admin, manager |
| `GET /api/v1/staff/_authz-check/admin-only` | admin |

## 4. Policy Strategy

- **`CustomerPolicy`** — real, using the real `Customer` model, self-
  ownership only. Not yet called from any controller (no route needs it
  yet) — tested directly via `Gate::forUser(...)->allows('view', ...)`.
  Relies on Laravel's standard `App\Policies\{Model}Policy` auto-discovery
  convention — no manual registration, and none needed.
- **`process-refund` Gate** — role-based capability check (Admin/Manager
  only), registered in `AppServiceProvider::boot()`. Deliberately a Gate,
  not a model-bound Policy, since there's no `Payment` model yet and
  "who can refund" doesn't depend on a specific payment record.
- **`BookingPolicy` / `PaymentPolicy`** — deliberately **not created**.
  No `Booking`/`Payment` Eloquent models exist yet (STEP 03 only built
  migrations; models arrive with each business module per the
  `app/Models/README.md` convention). A policy referencing a nonexistent
  model would either not compile or require a throwaway placeholder
  model — which is exactly the "fake business behavior" this step was
  told to avoid. Arrives with STEP 10/11.

## 5. Tests (14 new, `tests/Feature/AuthorizationTest.php`)

Receptionist: can access receptionist-task ✅, cannot access manager-task
❌, cannot access admin-only ❌. Manager: can access manager-task ✅, can
also access receptionist-task ✅ (explicitly listed), cannot access
admin-only ❌. Admin: can access admin-only ✅, can also access
manager-task and receptionist-task ✅ (explicitly listed on each — no
implicit bypass, see plan §2). Unauthenticated → 401. Customer token on
an admin-only route → 403 (wrong actor, fails closed before role is even
checked). Role spoofing via header/query string → still 403 (role
middleware never reads request input). Role change reflected on next
request without re-login → proves the check re-reads the DB, not a
cached claim. Refund Gate matrix: admin ✅, manager ✅, receptionist ❌,
customer ❌. `CustomerPolicy`: owner ✅, non-owner ❌.

## 6. Exact Commands

```bash
php artisan test --filter=AuthorizationTest
php artisan test
```

Expected: **62 passed** (48 from STEP 02–04 + 14 new). No existing test
should regress — nothing in STEP 05 touched `EnsureActor`, either auth
controller, either model's auth-relevant code, or any STEP 02–04 route.

## 7. Verification Results

| Item | Status |
|---|---|
| All new/modified files pass `php -l` | **TESTED**, in this sandbox |
| Full-project syntax sweep after this step | **TESTED**, clean |
| No `role`-related name collisions with existing middleware/aliases | **TESTED** — confirmed only one `role` alias exists |
| Policy auto-discovery convention (`CustomerPolicy` ↔ `Customer`) | Standard, long-stable Laravel behavior, not new/version-specific like Sanctum's migration change was — not independently re-verified via search this step, flagged for transparency |
| `php artisan test` → 62 passed | **NOT VERIFIED** — same sandbox limitation as every prior step (no Packagist access here). Needs your run. |

## 8. Known Limitations

- **The `_authz-check` fixture routes use closures**, which means
  `php artisan route:cache` would fail if run while they exist (Laravel
  can't cache closure-based routes). Not a problem now — nothing in this
  project runs `route:cache` yet, that's STEP 22 (Production
  Preparation) territory — but worth remembering to delete these routes
  before then, which the plan already flags them for regardless.
- **No implicit admin bypass in `role:*` middleware** (see plan §2) means
  every future route that should be admin-accessible must explicitly
  list `admin` in its role parameter. This is a deliberate design
  trade-off (explicit over implicit), not an oversight — but it's a
  convention future steps need to remember, not something the framework
  enforces automatically.
- **`CustomerPolicy` and the refund `Gate` are both untested by any real
  HTTP route** — by design, since no controller exists yet that would
  call `$this->authorize(...)`. Their *rules* are verified directly;
  their *wiring into a real endpoint* will be verified when STEP 09
  (Customer Management) and STEP 11 (Payments) actually call them.

STOPPING HERE. STEP 06 has not started. Waiting for your test results
before anything further.
