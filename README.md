# Hotel Management System V2 — Backend (Laravel 11)

STEP 02 foundation only. No business modules (authentication, rooms,
bookings, payments, etc.) are implemented yet. See
`docs/STEP-02-IMPLEMENTATION-REPORT.md` for the full file-by-file
breakdown and honest verification status.

## What this project actually is

This is a genuine Laravel 11 skeleton — `artisan`, `public/index.php`,
`bootstrap/app.php`, and every default config file were pulled directly
from `github.com/laravel/laravel` (the `11.x` branch, confirmed
`laravel/framework: ^11.31` in `composer.json` — not Laravel 12) — with
STEP 02's approved customizations layered on top. It is not a hand-typed
approximation of a Laravel project.

## Important — read before running

`vendor/` (the actual framework code) is **not included**. Composer
couldn't install it in the sandboxed environment this was assembled in —
`repo.packagist.org` returned HTTP 403 there. That restriction is specific
to that sandbox, not to Composer or Packagist generally, and should not
affect your local machine.

**What WAS verified before delivery:**
- `artisan` and `public/index.php` are byte-identical to Laravel's official
  `11.x` skeleton branch (not hand-written)
- Every custom `.php` file passed `php -l` (PHP 8.3 syntax-only lint)
- `composer.json` is valid JSON, `phpunit.xml` is well-formed XML

**What was NOT verified — do this locally, first:**
- `composer install` actually resolving dependencies into `vendor/`
- The application actually booting (`php artisan serve`)
- The test suite actually passing (`php artisan test`)
- `php artisan route:list` actually showing the expected routes

## Setup

**Run these in exact order.** `composer install` does not create `.env`
for you on a plain install (only Composer's `create-project` flow does
that automatically, which doesn't apply here since you're installing
against an already-scaffolded project) — skipping the `cp` step is why
`php artisan key:generate` fails with `.env does not exist`. That error
means step 2 below was skipped, not that anything is broken.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Update `.env` with your MySQL credentials (XAMPP default: `root`, no
password — matches the reference project's setup). `DB_CONNECTION=mysql`
is already set as the real application database, per requirement.

```bash
php artisan route:list
php artisan serve
```

Visit `http://localhost:8000/api/v1/health` — expect a JSON response with
`"success": true` and `"database": {"connected": true, ...}`.

```bash
php artisan test
```

Expect all STEP 02 foundation tests to pass (7 tests, see
`tests/Feature/FoundationTest.php`).

## Deliberate differences from a fresh `laravel new`

- No `routes/web.php`, no Blade/Vite frontend assets — this is an API-only
  backend; React is a fully separate application (Architecture Baseline
  §6). `resources/views/` is kept empty as a placeholder.
- `SESSION_DRIVER`, `CACHE_STORE` are `file` and `QUEUE_CONNECTION` is
  `sync`, not the genuine skeleton's `database` defaults — this is a
  stateless bearer-token API (Architecture Baseline §7) with no queued
  jobs yet, so there's no need to migrate `sessions`/`cache`/`jobs` tables
  just to make those drivers work. See the implementation report for the
  full reasoning, including the tension this creates with the *unused*
  default `users` migration (left in place, flagged for a STEP 03/04
  decision, not removed here).
- Pest is added alongside the genuine skeleton's default PHPUnit, per the
  already-approved Architecture Baseline §15 Testing Strategy.

## What's NOT here yet (by design)

- No `laravel/sanctum` dependency (arrives STEP 04)
- No domain migrations — `customers`, `employees`, `rooms`, etc. (STEP 03+)
- No Eloquent models, Policies, or Services with real logic (structure/
  conventions only, documented in each directory's `README.md`)
- No scheduled jobs (cleaning buffer / hold-expiry sweeps arrive STEP 10/13)
