# STEP 02 — Foundation Fix #2 (4 issues from local verification)

Addendum to the STEP 02 report and the Pest/PHPUnit fix. Four small,
surgical changes. No architecture, no auth, no new modules.

---

## Issue 1 — Missing `.env`

**Not an application bug**, confirmed. `composer install` only
auto-copies `.env.example` → `.env` via Composer's `post-root-package-install`
script when running `composer create-project` for a brand-new project.
You installed against an already-scaffolded project (extracted from the
zip), so that script event never fires — this is expected, standard
Laravel behavior, not something specific to this project.

**Fix:** README.md now states this explicitly, in bold, before the setup
commands, so `.env does not exist` is immediately recognizable as "step 2
was skipped" rather than a foundation defect.

## Issue 2 — Unused `Throwable` import

**Root cause:** `bootstrap/app.php` declares no `namespace` — it's a
plain top-level script in the global namespace. `Throwable` is a built-in
PHP interface that also lives in the global namespace. `use`-importing a
global-namespace name from within global-namespace code is a no-op, and
PHP correctly warns about it.

**Fix:** removed `use Throwable;` (line 13). The two actual usages —
`Throwable $e` in the two exception-handling closures — are untouched and
still resolve correctly without the import, since `Throwable` doesn't
need qualifying in the global namespace. No behavior change, confirmed
by `php -l` and by re-reading both call sites.

## Issue 3 — PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` deprecation

**What comes from where:**
- The line in question — `PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')`
  — is **verbatim from Laravel 11's own official skeleton**, present in
  both the `mysql` and `mariadb` connection blocks. I confirmed this by
  diffing our file against the pristine clone of `laravel/laravel`'s
  `11.x` branch — identical. This is not something introduced by this
  project.
- The **vendor-side** warning (inside `vendor/laravel/framework/...`)
  is Laravel's own internal code and is correctly out of reach —
  per your instruction, not touched, and not something we're
  responsible for. As of Framework 11.55.1, Laravel hasn't patched
  this upstream yet; that's on Laravel's release schedule, not ours.
- The **project-side** warning came from `config/database.php`, which
  — despite matching Laravel's own default — lives in our project, not
  `vendor/`, so it's fully within scope to fix.

**Why not just swap the constant outright:** the new `Pdo\Mysql` class
(and its `ATTR_SSL_CA` constant) was introduced in **PHP 8.4**. This
project's `composer.json` requires `"php": "^8.2"`. Hardcoding
`\Pdo\Mysql::ATTR_SSL_CA` directly would fatal-error with "class not
found" on PHP 8.2/8.3 — trading one platform's warning for another
platform's crash. That would be a real regression, not a fix.

**What we actually changed** (both occurrences, mysql + mariadb blocks):
```diff
-                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
+                (class_exists('Pdo\Mysql') ? \Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
```
A runtime-guarded constant: on PHP 8.4+ (where the new class exists),
PHP's ternary short-circuit evaluation means the deprecated constant is
never touched at all — the warning simply can't fire. On PHP 8.2/8.3
(where the new class doesn't exist yet), it falls back to the classic
constant, which isn't deprecated on those versions. Same resulting value
either way; behavior is identical to before on every PHP version this
project claims to support.

**Tested directly in this sandbox (PHP 8.3.6, with `pdo_mysql`
installed):** the guarded expression executes with no error and produces
the exact same empty-options-array result as before (since
`MYSQL_ATTR_SSL_CA` isn't set in your `.env`). I could not directly test
the PHP 8.4+/8.5 branch here — this sandbox only has PHP 8.3 available —
so that half relies on PHP's documented ternary short-circuit semantics
rather than a live run. That's a well-established language guarantee,
not a framework-specific assumption, but it's still the one part of this
fix I'm not claiming to have executed on your exact PHP version.

## Issue 4 — Test count (6 instead of 7)

**Root cause found:** genuinely mine, and genuinely just a stale number,
not a silently-broken test. When the test suite was rewritten during the
"genuine scaffold" rebuild, I simplified away a near-duplicate test
(`'the api is versioned under v1'`, which just repeated the same
assertion as the health-check-succeeds test) — but never went back and
corrected the "7 tests" claim in the docs to match the simplified file.
Nothing was silently dropped by a bug; I just failed to reconcile the
count after editing.

Checked against STEP 02's original 6-item required test coverage:
1. Application starts ✓
2. Health endpoint returns success ✓
3. Health endpoint returns JSON ✓
4. Database connection works ✓
5. **API version prefix works** — this was the one that lost its
   dedicated test
6. Error responses follow the defined envelope ✓ (2 tests: 404 + 405)

**Fix:** restored a test for item 5 — but a stronger one than the
original, not just a duplicate. Instead of re-asserting that `/api/v1/health`
works (already covered by test 2), it proves the prefix is actually
*enforced*: the same route without `/v1/` returns 404.

```php
test('the v1 prefix is required - the same route without it does not exist', function () {
    $this->getJson('/api/v1/health')->assertStatus(200);
    $this->getJson('/api/health')->assertStatus(404);
});
```

Test count is now **7**, each mapped 1:1 to one of the 6 required
categories (category 6 gets two tests — 404 and 405 — as it did before).

---

## Verification status

| Item | Status |
|---|---|
| `.env` root cause identified, README updated | **TESTED** — confirmed the `post-root-package-install` script only fires on `create-project`, not plain `install` |
| `Throwable` import removed, `php -l` clean | **TESTED** — in this sandbox |
| PDO fix — no namespace collision, valid syntax | **TESTED** — `php -l` clean |
| PDO fix — PHP 8.2/8.3 fallback branch actually executes correctly | **TESTED** — ran directly in this sandbox (PHP 8.3.6 + pdo_mysql), correct empty-array result, no error |
| PDO fix — PHP 8.4+/8.5 branch takes the new constant and never touches the old one | **NOT VERIFIED here** — this sandbox has no PHP 8.4/8.5 to test against; relies on PHP's documented ternary short-circuit behavior, not an assumption about Laravel specifically |
| Test file restored to 7 tests, `php -l` clean | **TESTED** — in this sandbox |
| Whether all 7 tests actually pass together | **NOT VERIFIED** — needs your `php artisan test` run |
| Whether the PDO fix actually silences the warning on your real PHP 8.5.6 | **NOT VERIFIED** — needs your local run; this is the one claim in this whole fix I most want you to specifically check |

---

## Exact commands to run, in order

```bash
cp .env.example .env
php artisan key:generate
php artisan --version
php artisan route:list
php artisan test
php artisan serve
```
In a second terminal, while `serve` is running:
```bash
curl http://127.0.0.1:8000/api/v1/health
```

Specifically worth checking against the issues above:
- `php artisan test` output should say **7 passed**, not 6
- No `Throwable` warning should appear anywhere in the command output
- No `PDO::MYSQL_ATTR_SSL_CA` deprecation warning attributed to
  `config/database.php` specifically (a warning still coming from
  somewhere inside `vendor/` is expected and out of scope — that's
  Laravel's own unpatched code, not this project's)

STOPPING HERE. STEP 03 has not started. Waiting for your verification
results before anything further.
