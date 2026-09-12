# STEP 05 — Authorization Plan (review before implementation below)

## 1. Authorization Architecture

Exactly the approved layered flow, nothing added:

```
Request → auth:sanctum → actor:customer/staff → role:* (staff routes only) → Policy/Gate → resource ownership
```

`role:*` never runs alone — it's always paired with `actor:staff` on the
same route, but (see §2) doesn't *trust* that pairing; it independently
confirms the resolved user is an `Employee` before checking role.

## 2. Middleware Design

One new middleware, `EnsureStaffHasRole`, registered under the alias
`role` — same single-parameterized-class pattern as `EnsureActor` from
STEP 04, not one class per role.

```php
Route::get('/x', ...)->middleware(['auth:sanctum', 'actor:staff', 'role:admin,manager']);
```

Laravel splits comma-separated middleware parameters automatically —
`role:admin,manager` calls `handle($request, $next, ...$roles)` with
`$roles = ['admin', 'manager']`.

**No implicit admin bypass.** `'admin'` is not silently added to every
allowed-roles list by the middleware itself. If a route should be
admin-accessible — which is normally true, since Admin has full access —
`'admin'` must be listed explicitly: `role:admin,manager,receptionist`,
not just `role:receptionist`. This is a deliberate choice: a middleware
with a hidden "admin always passes" special case is a subtle trap to
forget later; putting the guarantee at the route-definition level (every
future route remembers to include `admin`) is more auditable, even
though it means one extra word per route.

**Role always comes from the database**, never request input: `$user`
is `$request->user()` (resolved by Sanctum from the verified token), and
the check is `$user->role` — a column read from the `employees` table.
No controller or middleware anywhere reads a `role` field from a request
body or header for authorization purposes. This directly satisfies "do
not trust a role sent by the frontend."

## 3. Permission Matrix (as approved, restated for reference)

| | Admin | Manager | Receptionist | Customer |
|---|:---:|:---:|:---:|:---:|
| Admin-only actions | ✅ | ❌ | ❌ | ❌ |
| Manager/Admin actions | ✅ | ✅ | ❌ | ❌ |
| Receptionist-level operational actions | ✅ | ✅ | ✅ | ❌ |
| Own profile/bookings/payments | – | – | – | ✅ |
| Refund | ✅ | ✅ | ❌ | ❌ |

## 4. Policy Strategy

**Implementable now, using models that already exist:**
- `CustomerPolicy` (`view`/`update`) — self-ownership only
  (`$viewer->id === $target->id`). Uses the real `Customer` model from
  STEP 04. Not yet wired into a route via `$this->authorize()` — there's
  no profile-management endpoint yet (`/customer/me` is inherently
  self-referential and needs no ownership check; a future
  `GET /customer/profile/{id}`-style route would be where this applies).
  Written now because the ownership *rule* is simple, stable, and
  doesn't depend on anything unbuilt.
- **Refund authorization** — a `Gate`, not a model-bound `Policy`, since
  "can this user refund *anything*" is a role-based capability check,
  not tied to a specific resource instance. Registered in
  `AppServiceProvider::boot()`.

**Deliberately deferred, not stubbed:**
- `BookingPolicy`, `PaymentPolicy` — there is no `Booking` or `Payment`
  Eloquent model yet (STEP 03 only created migrations; models arrive
  with each business module, per the convention documented in
  `app/Models/README.md` since STEP 02). A policy class type-hinted
  against a model that doesn't exist would either not compile or would
  need a placeholder model created just to satisfy it — and an empty
  model built only so a policy has something to reference is exactly
  the "fake business behavior" this step was told to avoid. These
  arrive with STEP 10 (Booking) and STEP 11 (Payments).
- Staff/admin resource-level policies (e.g. "can this manager edit that
  receptionist's account") — no staff-management routes exist yet.
  Covered for now by route-level `role:*` gating; resource-level
  ownership policies arrive with that module.

## 5. Affected / New Files

| File | Change |
|---|---|
| `app/Http/Middleware/EnsureStaffHasRole.php` | new |
| `bootstrap/app.php` | register the `role` alias (resolves the STEP 04 placeholder comment) |
| `app/Policies/CustomerPolicy.php` | new |
| `app/Providers/AppServiceProvider.php` | register the `process-refund` Gate |
| `routes/api.php` | add authorization test fixture routes (see §6) |
| `tests/Feature/AuthorizationTest.php` | new |

## 6. Test Plan

Items 3–12 from the required list need something real to test against —
STEP 05 has no business routes yet to naturally exercise `role:*`. Rather
than invent fake business behavior, I'm adding a **small, explicitly
labeled set of fixture routes** under `/api/v1/staff/_authz-check/*`
whose only purpose is proving the middleware pipeline works — they return
a static "OK" message, nothing else, and are documented as temporary,
to be deleted once real role-protected business routes exist (STEP 07+).
This is different from fake business logic: nothing here pretends to
book a room or process a payment, it only proves who's let through.

| Route | Roles allowed |
|---|---|
| `GET /staff/_authz-check/any-staff` | any employee |
| `GET /staff/_authz-check/receptionist-task` | admin, manager, receptionist |
| `GET /staff/_authz-check/manager-task` | admin, manager |
| `GET /staff/_authz-check/admin-only` | admin |

Refund authorization (items 12) is tested directly against the Gate
(`Gate::forUser($user)->allows('process-refund')`), no HTTP route needed
— consistent with treating it as an authorization-layer capability
check, not a business endpoint.

Items 1–2 and 8 (customer-route access, customer-blocked-from-staff,
unauthenticated → 401) are already covered by STEP 04's suite and won't
be duplicated — STEP 05 adds only what's genuinely new.

---

Implementation follows below.
