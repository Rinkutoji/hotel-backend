# STEP 04 — Authentication Plan (review before implementation below)

## 1. Sanctum Changes Required

- `composer.json`: add `laravel/sanctum: ^4.0` — confirmed via Laravel's own
  upgrade guide ("Laravel 11 no longer supports Sanctum 3.x... update to
  ^4.0"), not assumed.
- Laravel 11's skeleton doesn't ship Sanctum by default (confirmed by
  inspecting the genuine clone from STEP 02 — no `laravel/sanctum` in its
  `composer.json`), so it's a real addition, not redundant.
- **Sanctum 4.0 no longer auto-loads its own migration** — it must be
  hand-added to `database/migrations/`, not just installed via Composer.
- `config/sanctum.php` — new file, needed for `expiration` (the
  `SANCTUM_EXPIRATION` env var was already reserved back in STEP 02,
  unused until now).
- No `EnsureFrontendRequestsAreStateful` middleware, no
  `SANCTUM_STATEFUL_DOMAINS` wiring — that's Sanctum's SPA/cookie mode,
  which Architecture Baseline §3/§7 explicitly ruled out in favor of
  plain bearer tokens.

## 2. Affected / New Files

| File | Change |
|---|---|
| `composer.json` | add `laravel/sanctum` |
| `config/sanctum.php` | new |
| `database/migrations/..._create_personal_access_tokens_table.php` | new |
| `app/Models/Customer.php`, `app/Models/Employee.php` | new — first real models in the project |
| `app/Http/Middleware/EnsureActor.php` | new |
| `bootstrap/app.php` | register the `actor` middleware alias (placeholder from STEP 02 resolved) |
| `app/Http/Requests/Customer/{Register,Login}Request.php` | new |
| `app/Http/Requests/Staff/LoginRequest.php` | new |
| `app/Http/Controllers/Api/V1/Customer/AuthController.php` | new |
| `app/Http/Controllers/Api/V1/Staff/AuthController.php` | new |
| `routes/api.php` | fill in the `customer`/`staff`/`admin` groups left empty since STEP 02 |
| `database/seeders/TestEmployeeSeeder.php` | new, dev/test-only, not auto-run |
| `tests/Feature/{CustomerAuth,StaffAuth,AuthSecurity}Test.php` | new |

## 3. Route Design

Implementing exactly the routes specified, including the
`/admin/auth/login` + `/staff/auth/logout` + `/staff/me` split as given —
worth flagging once: login lives under `/admin/` while logout/me live
under `/staff/`, which is a URL-naming inconsistency (an employee of any
role logs in at the "admin" URL, not just admins — role-gating a login
endpoint isn't possible anyway, since role isn't known until after the
token resolves). Not a technical contradiction, so implementing as
specified rather than "fixing" it.

| Method | URL | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/customer/auth/register` | Public | |
| POST | `/api/v1/customer/auth/login` | Public | |
| POST | `/api/v1/customer/auth/logout` | `auth:sanctum` + `actor:customer` | |
| GET | `/api/v1/customer/me` | `auth:sanctum` + `actor:customer` | |
| POST | `/api/v1/admin/auth/login` | Public | employee login, any role |
| POST | `/api/v1/staff/auth/logout` | `auth:sanctum` + `actor:staff` | |
| GET | `/api/v1/staff/me` | `auth:sanctum` + `actor:staff` | |

One controller per actor (`Customer\AuthController`, `Staff\AuthController`)
— the URL-prefix split doesn't need two controllers per actor, since the
underlying logic doesn't change based on which prefix invoked it.

## 4. Authentication Flow

**Single guard, actor middleware — exactly as approved, not two Sanctum
guards.** `Laravel\Sanctum\PersonalAccessToken.tokenable` is polymorphic,
so one `auth:sanctum` middleware resolves `$request->user()` to whichever
model — `Customer` or `Employee` — issued the token. A single
parameterized `actor:customer` / `actor:staff` middleware (registered
under one alias, `actor`, taking the actor name as a route parameter —
matching the exact `actor:customer`/`actor:staff` syntax specified, not
the two-separate-middleware-classes shape I'd sketched as a placeholder
comment back in STEP 02) then checks `$request->user() instanceof Customer`
or `instanceof Employee` and throws `AuthorizationException` (→ 403,
via the exception handling already built in STEP 02) on mismatch.

**Registration → login → authenticated request → logout:**
1. Customer registers → row created in `customers`, password hashed via
   Laravel's automatic `hashed` cast (never handled manually)
2. Login (either actor) → look up by email → verify password → **check
   account `status = active` only after password is confirmed correct**
   (see §6 on why this order matters) → `$model->createToken('api')` →
   return the plain-text token once, in the login response only
3. Every subsequent request sends `Authorization: Bearer <token>` →
   `auth:sanctum` resolves it → `actor:*` confirms the right actor type
4. Logout → `$request->user()->currentAccessToken()->delete()` — revokes
   only the token in use, not every device, per the explicit instruction

**Token expiration:** read from `config('sanctum.expiration')`, which
reads `SANCTUM_EXPIRATION` (already `10080` = 7 days in `.env.example`
since STEP 02). Never hardcoded in a controller.

## 5. Error Case → Status Code Mapping

| Case | Status | Reasoning |
|---|---|---|
| Duplicate email at registration | 422 | Validation error (`unique:customers`) |
| Missing/malformed request fields | 422 | FormRequest validation |
| Wrong email OR wrong password | 401, generic "Invalid credentials" | Same message for both — revealing *which* one was wrong lets an attacker enumerate valid emails |
| Correct credentials, inactive account | 403 | Checked **only after** password verification succeeds — see below |
| No token / bad token | 401 | Sanctum's own `AuthenticationException`, already handled since STEP 02 |
| Right actor type, wrong token | 401 | Same as above |
| Wrong actor type (customer hitting `/staff/*`) | 403 | `actor:*` middleware |
| Logout with invalid/already-revoked token | 401 | Can't authenticate to revoke what you can't prove you own |

**Why status is checked after password, not before:** checking account
status first would let an unauthenticated caller learn "this email
belongs to an inactive account" without ever proving they know the
password — a real (if minor) information leak. Checking password first
means only someone who already knows the real password learns the
account is inactive, which is an appropriate thing to tell them.

## 6. Test Credentials (dev/testing only — explained per instruction)

There's no employee registration endpoint by design (staff accounts come
from later staff-management functionality). To exercise the employee
login flow locally, `database/seeders/TestEmployeeSeeder.php` creates
**one** account — **not** wired into `DatabaseSeeder::run()`, so it never
runs automatically:

```
email:    admin@test.local
password: ChangeMe123!
role:     admin
```

Run explicitly with `php artisan db:seed --class=TestEmployeeSeeder`.
Automated tests don't depend on this seeder at all — they create their
own employee records inline, scoped to each test's own transaction (same
pattern as STEP 03's schema tests), so nothing about the test suite
relies on this account existing.

## 7. Testing Plan

Real end-to-end token flow throughout (actual HTTP calls extracting the
real issued token, not Sanctum's `actingAs()` shortcut) — this is exactly
the step where proving the real mechanism works matters more than test
convenience.

- **Customer:** register success, duplicate email, login success, login
  failure (wrong password), `me`, logout (+ confirm the token is
  actually rejected afterward)
- **Employee:** login success, invalid login, `me`, logout
- **Security:** unauthenticated → 401, customer token on `/staff/me` →
  403, employee token on `/customer/me` → 403, revoked token → 401,
  inactive account → 403

---

Implementation follows below.
