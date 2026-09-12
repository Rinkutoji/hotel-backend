# STEP 02 — Laravel 11 Backend Foundation — CORRECTED Implementation Report

This supersedes the first STEP 02 delivery. That delivery was not a real
Laravel scaffold — this section explains exactly why, and what changed.

---

## 1. Root Cause

The first delivery hand-wrote files that *looked* like a Laravel project
(`bootstrap/app.php`, `routes/api.php`, `config/*.php`, etc.) but never
included `artisan` or `public/index.php` — the two entry-point scripts
that make a Laravel directory an actually-runnable application. Those two
files are **not** Composer packages; they ship as plain files inside
Laravel's own skeleton repository (`github.com/laravel/laravel`), and I
never fetched that repository. I focused entirely on the application code
and configuration and missed the entry-point scaffold itself. That's a
real gap in the earlier delivery, not only a sandbox network limitation —
this part was fixable without any package manager at all, and I should
have done it the first time.

Separately, and still true now: this sandboxed tool environment cannot
reach `repo.packagist.org` (HTTP 403), so `composer install` cannot
populate `vendor/` here. That part genuinely is a sandbox-only network
restriction — it is not expected to affect your local machine.

---

## 2. Correct Laravel 11 Foundation

Fixed by cloning `github.com/laravel/laravel` at the **`11.x` branch**
directly (plain `git clone` — no Packagist involved) and layering STEP
02's approved customizations on top of that genuine base, instead of
hand-writing everything from scratch.

**Confirmed Laravel 11, not 12:**
```
$ git branch --show-current
11.x
$ grep laravel/framework composer.json
"laravel/framework": "^11.31"
```

**Confirmed `artisan` and `public/index.php` are byte-identical to the
official skeleton** (`diff` against the freshly-cloned `11.x` branch
produced no output — i.e. identical):
```
artisan: IDENTICAL to genuine 11.x skeleton
public/index.php: IDENTICAL to genuine 11.x skeleton
```

**Confirmed this fixes the exact failure you reported.** Before
`composer install`, running `php artisan --version` now fails with:
```
PHP Fatal error: Uncaught Error: Failed opening required
'.../vendor/autoload.php' ... in /.../artisan:10
```
instead of `Could not open input file: artisan`. This is the correct,
expected state of *any* freshly-cloned Laravel repository before
dependencies are installed — not a sign of a fake project. Once you run
`composer install` on your machine (where Packagist is reachable),
`vendor/autoload.php` will exist and this error goes away.

---

## 3. Complete Root Folder Structure

```
hotel-backend/
├── .editorconfig, .gitattributes, .gitignore     ← genuine, untouched
├── .env.example                                   ← genuine base, customized (§5)
├── README.md                                      ← rewritten (was laravel/laravel's own)
├── artisan                                         ← genuine, byte-identical
├── composer.json                                   ← genuine base, customized (§5)
├── phpunit.xml                                     ← genuine base, customized (§5)
├── app/
│   ├── Http/Controllers/Controller.php             ← genuine, untouched
│   ├── Http/Controllers/Api/V1/HealthController.php← NEW
│   ├── Http/Middleware/ForceJsonResponse.php       ← NEW
│   ├── Http/Requests/ApiFormRequest.php            ← NEW
│   ├── Models/User.php                             ← genuine, untouched (see §6 flag)
│   ├── Models/README.md                            ← NEW (convention note)
│   ├── Policies/README.md                          ← NEW (convention note, dir otherwise empty)
│   ├── Providers/AppServiceProvider.php            ← genuine, untouched
│   ├── Services/README.md                          ← NEW (convention note, dir otherwise empty)
│   └── Support/ApiResponse.php                     ← NEW
├── bootstrap/
│   ├── app.php                                     ← genuine base, customized (§5)
│   ├── cache/.gitignore                            ← genuine, untouched
│   └── providers.php                               ← genuine, untouched
├── config/
│   ├── app.php, auth.php, cache.php, database.php,
│   │   logging.php, mail.php, queue.php, services.php,
│   │   session.php                                 ← genuine, untouched
│   ├── booking.php                                 ← NEW
│   ├── cors.php                                    ← NEW
│   └── filesystems.php                             ← genuine base, customized (§5)
├── database/
│   ├── .gitignore                                  ← genuine, untouched
│   ├── factories/UserFactory.php                   ← genuine, untouched (see §6 flag)
│   ├── migrations/0001_..._create_users_table.php  ← genuine, untouched (see §6 flag)
│   ├── migrations/0001_..._create_cache_table.php  ← genuine, untouched (see §6 flag)
│   ├── migrations/0001_..._create_jobs_table.php   ← genuine, untouched (see §6 flag)
│   └── seeders/DatabaseSeeder.php                  ← genuine, untouched (see §6 flag)
├── public/
│   ├── .htaccess, favicon.ico, robots.txt, index.php ← genuine, byte-identical
├── resources/views/.gitkeep                        ← placeholder (welcome.blade.php removed, §4)
├── routes/
│   ├── api.php                                     ← NEW
│   └── console.php                                 ← genuine base, one comment added
├── storage/                                        ← genuine tree + gitignores, untouched
│   └── app/private/receipts/.gitignore             ← NEW (empty-dir placeholder)
└── tests/
    ├── Feature/FoundationTest.php                  ← NEW (replaces genuine ExampleTest.php)
    ├── Pest.php                                     ← NEW
    └── TestCase.php                                ← genuine, untouched
```

---

## 4. Files Removed From the Genuine Skeleton (and why)

| Removed | Reason |
|---|---|
| `routes/web.php` | API-only backend; React is a fully separate app (Architecture Baseline §6). No Blade frontend is served by Laravel here. |
| `resources/views/welcome.blade.php`, `resources/css/`, `resources/js/`, `vite.config.js`, `tailwind.config.js`, `postcss.config.js`, `package.json` | Frontend build tooling for a Blade/Vite frontend this project doesn't use. |
| `tests/Feature/ExampleTest.php` | Asserts `GET /` returns 200 — that route no longer exists once `routes/web.php` is removed. It would fail for a reason unrelated to anything real. Replaced by `tests/Feature/FoundationTest.php`. |
| `tests/Unit/ExampleTest.php` | Laravel's placeholder unit test, no project-specific value. |
| `.styleci.yml`, `CHANGELOG.md`, `.github/workflows/*` | Belong to the `laravel/laravel` repository itself (its own CI/style-check config), not to this project. |

## 5. Files Added or Modified On Top of the Genuine Base

| File | Genuine base? | What changed |
|---|---|---|
| `bootstrap/app.php` | Yes | `web:` → `api:` routing param; filled in `withMiddleware()`/`withExceptions()` closures (both empty in genuine skeleton) with `ForceJsonResponse` + the 6-category exception→JSON handling |
| `config/filesystems.php` | Yes | Added the `receipts` disk (private, no `url` key) alongside genuine `local`/`public`/`s3` |
| `.env.example` | Yes | `DB_CONNECTION` sqlite→mysql (explicit requirement); `SESSION_DRIVER`/`CACHE_STORE` database→file, `QUEUE_CONNECTION` database→sync (see §6); added `CORS_ALLOWED_ORIGINS`, `UNPAID_BOOKING_HOLD_HOURS`, `CLEANING_BUFFER_MINUTES`, `SANCTUM_EXPIRATION`; removed unused `REDIS_*`/`VITE_APP_NAME` |
| `phpunit.xml` | Yes | Uncommented the sqlite test-DB override (genuine leaves it commented because *its* default connection is already sqlite; ours is now mysql) |
| `composer.json` | Yes | Renamed project; added Pest on top of genuine PHPUnit (already-approved Testing Strategy, Architecture Baseline §15); removed the `dev` script (npm/vite) and `post-create-project-cmd` (irrelevant — we're not running `composer create-project` again) |
| `routes/console.php` | Yes | Added a comment noting where STEP 10/13 scheduled jobs will register — no functional change |
| `app/Http/Controllers/Api/V1/HealthController.php` | No | New |
| `app/Http/Middleware/ForceJsonResponse.php` | No | New |
| `app/Http/Requests/ApiFormRequest.php` | No | New |
| `app/Support/ApiResponse.php` | No | New |
| `config/booking.php`, `config/cors.php` | No | New — neither is published by Laravel's skeleton by default |
| `routes/api.php` | No | New — skeleton has no API routes by default in Laravel 11 unless `install:api` is run (which also pulls in Sanctum — deliberately not run, per the "no auth yet" scope) |
| `tests/Pest.php`, `tests/Feature/FoundationTest.php` | No | New |

## 6. Left Genuine and Untouched — Flagged for a Future Step, Not Decided Here

The default skeleton ships a generic `users` table (migration + model +
factory + seeder) and `config/auth.php` wired to a `web` session guard.
Your Architecture Baseline uses separate `customers`/`employees` tables
instead, and bearer-token auth instead of session guards — so this
default scaffolding is inert and will end up unused. STEP 02's brief was
"foundation only, don't touch auth" — removing or rewriting these felt
like it would exceed that scope on my own judgment, so I left them
genuinely untouched rather than deciding unilaterally. **This needs a
decision at STEP 03/04:** delete them outright, or leave them dormant
until the real schema replaces them. Not blocking — just flagging.

---

## 7. Exact Commands To Run Locally

```bash
composer install
cp .env.example .env
php artisan key:generate
# edit .env with your MySQL credentials
php artisan route:list
php artisan serve
```
In a second terminal:
```bash
curl http://localhost:8000/api/v1/health
```
```bash
php artisan test
```

## 8. Verification Results

| Check | Result |
|---|---|
| `git branch --show-current` on the source skeleton | `11.x` — **TESTED**, genuinely Laravel 11 |
| `composer.json` framework constraint | `^11.31` — **TESTED**, confirmed not silently upgraded to 12 |
| `artisan` byte-identical to genuine skeleton | **TESTED** — `diff` produced no output |
| `public/index.php` byte-identical to genuine skeleton | **TESTED** — `diff` produced no output |
| Every custom/modified `.php` file — syntax valid | **TESTED** — `php -l`, 26 files, all pass |
| `composer.json` valid JSON / `phpunit.xml` well-formed | **TESTED** |
| `composer install` | **NOT VERIFIED** here — `repo.packagist.org` returns HTTP 403 in this sandbox (confirmed by directly re-attempting it, see below); this is a sandbox network restriction, not expected to occur on your machine |
| `php artisan --version` / `key:generate` / `route:list` / `serve` / `test` | **NOT VERIFIED** here — all require `vendor/autoload.php`, which doesn't exist until `composer install` succeeds. Confirmed the failure is now the *correct* one (`Failed opening required vendor/autoload.php`), not the missing-file error you reported. |
| `GET /api/v1/health` | **NOT VERIFIED** — same reason, needs a booted app |

**Direct re-attempt of `composer install` in this sandbox, for the record:**
```
In AuthHelper.php line 205:
  The 'https://repo.packagist.org/packages.json' URL could not be
  accessed (HTTP 403): HTTP/2 403
```

## 9. Remaining Issues

1. **`vendor/` cannot be produced in this sandbox.** No further action
   possible here — this needs to run on your machine. If your machine
   *also* can't reach Packagist, that would be a genuinely different and
   more serious problem worth investigating separately; ordinary internet
   access should not have this restriction.
2. **The `users`/`cache`/`jobs` default migrations and `User` model are
   still present and unused** (§6) — needs an explicit decision at
   STEP 03/04, not resolved here.
3. Everything else from the first report's "remaining limitations" still
   applies unchanged: `pdo_sqlite` must be present locally for the test
   suite to run as configured (near-universal on XAMPP/standard PHP
   builds); no CI pipeline (out of STEP 02 scope).

STOPPING HERE. STEP 03 has not started. Waiting for your review of this
correction before anything further.
