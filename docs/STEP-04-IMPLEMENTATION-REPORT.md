# STEP 04 — Authentication with Sanctum — Implementation Report

Plan is in `docs/STEP-04-AUTH-PLAN.md` (written first, per instruction).
This covers what was actually built on top of it.

---

## 1. Files Created / Modified

**Created:**
- `config/sanctum.php`
- `database/migrations/2025_02_02_000001_create_personal_access_tokens_table.php`
- `app/Models/Customer.php`, `app/Models/Employee.php` — first real models
- `app/Http/Middleware/EnsureActor.php`
- `app/Http/Requests/Customer/RegisterRequest.php`, `LoginRequest.php`
- `app/Http/Requests/Staff/LoginRequest.php`
- `app/Http/Controllers/Api/V1/Customer/AuthController.php`
- `app/Http/Controllers/Api/V1/Staff/AuthController.php`
- `database/seeders/TestEmployeeSeeder.php` (dev/test-only, not auto-run)
- `tests/Feature/CustomerAuthTest.php` (10), `StaffAuthTest.php` (5),
  `AuthSecurityTest.php` (6) — 21 new tests

**Modified:**
- `composer.json` — added `laravel/sanctum: ^4.0`
- `bootstrap/app.php` — registered the `actor` middleware alias
  (resolves the STEP 02 placeholder); **also fixed the
  `AuthenticationException` handler**, which hardcoded `'Unauthenticated.'`
  regardless of the exception's actual message. That was fine when
  nothing threw this exception with a specific message (STEP 02), but
  STEP 04's login flow needs `'Invalid credentials.'` to actually reach
  the client. Now uses `$e->getMessage() ?: 'Unauthenticated.'` — same
  pattern the `AuthorizationException` handler already used correctly.
  Sanctum's own default `AuthenticationException` message is itself
  `'Unauthenticated.'`, so the no-token case is unaffected.
- `routes/api.php` — filled in the `customer`/`staff`/`admin` groups left
  empty since STEP 02
- `.env.example` — added `SANCTUM_TOKEN_PREFIX` alongside the
  already-reserved `SANCTUM_EXPIRATION`

## 2. Routes

| Method | URL | Auth |
|---|---|---|
| POST | `/api/v1/customer/auth/register` | Public |
| POST | `/api/v1/customer/auth/login` | Public |
| POST | `/api/v1/customer/auth/logout` | `auth:sanctum` + `actor:customer` |
| GET | `/api/v1/customer/me` | `auth:sanctum` + `actor:customer` |
| POST | `/api/v1/admin/auth/login` | Public |
| POST | `/api/v1/staff/auth/logout` | `auth:sanctum` + `actor:staff` |
| GET | `/api/v1/staff/me` | `auth:sanctum` + `actor:staff` |

Exactly as specified, including the login-under-`/admin/` /
logout-and-me-under-`/staff/` split — flagged once in the plan as a
naming inconsistency, implemented as given since it isn't a technical
contradiction.

## 3. Setup Commands

```bash
composer install
php artisan migrate
```

## 4. Seed / Test Credentials

**Automated tests don't need this** — every test creates its own
`Customer`/`Employee` rows inline. This is purely for **manual** local
testing of the employee login flow, since there's no registration
endpoint for employees:

```bash
php artisan db:seed --class=TestEmployeeSeeder
```
```
email:    admin@test.local
password: ChangeMe123!
role:     admin
```
Not wired into `DatabaseSeeder::run()` — never runs unless explicitly
invoked. Don't run this against a production database; the password is
an obvious placeholder.

## 5. Test Commands

```bash
php artisan test --filter=CustomerAuthTest
php artisan test --filter=StaffAuthTest
php artisan test --filter=AuthSecurityTest
php artisan test
```

## 6. Expected Results

- `php artisan migrate` — 11 migrations run (10 from STEP 03 + Sanctum's),
  no errors
- `php artisan test` (full suite) — **48 passed** (7 STEP 02 + 20 STEP 03
  + 21 STEP 04)

## 7. Manual Verification (beyond the automated suite)

```bash
# Register
curl -X POST http://127.0.0.1:8000/api/v1/customer/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"Password123!","password_confirmation":"Password123!"}'

# Login (copy the token from the response)
curl -X POST http://127.0.0.1:8000/api/v1/customer/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Password123!"}'

# Authenticated request
curl http://127.0.0.1:8000/api/v1/customer/me \
  -H "Authorization: Bearer <token>"

# Logout, then confirm the same token now fails
curl -X POST http://127.0.0.1:8000/api/v1/customer/auth/logout \
  -H "Authorization: Bearer <token>"
curl http://127.0.0.1:8000/api/v1/customer/me \
  -H "Authorization: Bearer <token>"   # expect 401
```

## 8. Verification Checklist

- [ ] `php artisan migrate` includes `personal_access_tokens` and completes cleanly
- [ ] `php artisan test` → 48 passed, 0 failed
- [ ] Register a real customer via curl/Postman, confirm the password
      column in MySQL is a bcrypt hash, never plaintext
- [ ] Confirm a login response never includes a `password` field anywhere
      in the JSON
- [ ] `php artisan db:seed --class=TestEmployeeSeeder` then log in at
      `/api/v1/admin/auth/login` with the documented test credentials
- [ ] Manually hit `/api/v1/staff/me` with a customer's token → confirm 403
- [ ] Manually hit `/api/v1/customer/me` with no `Authorization` header → confirm 401

## 9. Known Limitations

- **Not executed against real MySQL/PHP here** — same sandbox limitation
  as every prior step. All 21 new tests were written to run for real
  (they will execute against SQLite via `php artisan test` on your
  machine), but I could only syntax-check them here, not watch them pass.
- **Password strength policy is minimal** — `Password::min(8)` only, no
  required symbol/mixed-case/uncompromised-password checks. Laravel
  supports stricter rules if wanted later; not added preemptively to
  avoid guessing at a policy that wasn't specified.
- **No rate limiting added specifically to the auth endpoints** in this
  step — Laravel's default `throttle:api` (60/min) already applies
  globally via the `api` middleware group from STEP 02, but a tighter,
  auth-specific limit (e.g. 5 login attempts/min) wasn't added, since
  Authorization/hardening passes were flagged as later-step territory in
  the Architecture Baseline (§14/§21). Worth a look before production.
- **Authorization (role-based permissions) is explicitly not here** — an
  authenticated receptionist can currently call any `/staff/*` route a
  manager or admin could. That's correct for STEP 04's stated scope, not
  an oversight; STEP 05 owns it.

STOPPING HERE. STEP 05 has not started. Waiting for your migration/test
results before anything further.
