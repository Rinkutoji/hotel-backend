# STEP 02 — Dependency Conflict Fix (Pest / PHPUnit)

Addendum to the STEP 02 implementation report. Only `composer.json` changed.
No architecture, no app code, no test code.

---

## 1. Current dependency conflict

`composer.json` required:
```
"phpunit/phpunit": "^11.0.1",
"pestphp/pest": "^2.34",
"pestphp/pest-plugin-laravel": "^2.3"
```

**Pest 2.x is built on PHPUnit 10** and requires `phpunit/phpunit: ^10.5.x`.
Laravel 11's own genuine skeleton pins `phpunit/phpunit: ^11.0.1` (confirmed
directly from the `11.x` branch I cloned in the previous step). Those two
constraints can never both be satisfied — that's exactly the conflict
Composer reported. This was my error: I carried over Pest 2's version
number without checking which PHPUnit line it's built on.

## 2. Recommended compatible versions

**Pest 3.x** — confirmed via Pest's own official upgrade guide
(pestphp.com/docs/upgrade-guide) and its `3.x` branch `composer.json` on
GitHub:

- Pest 3 requires PHP ^8.2.0 (matches this project's `"php": "^8.2"`)
- **Pest 3 is built on top of PHPUnit 11** — matches Laravel 11's
  `^11.0.1` exactly, no version bump needed on the PHPUnit line itself
- Pest 3 requires `nunomaduro/collision: ^8.0` if using Laravel — this
  project already has `^8.1` (inherited from the genuine Laravel 11
  skeleton), so nothing to change there either

I deliberately did **not** consider Pest 4 — it's built on PHPUnit 12/13
and requires PHP 8.3+, which would just recreate the same category of
conflict against Laravel 11's `phpunit ^11.0.1` pin, in the opposite
direction. That's a real, documented issue other Laravel-11 projects have
hit when jumping straight to Pest 4. Pest 3 is the version actually built
for this framework generation.

## 3. Exact composer.json changes

```diff
-        "pestphp/pest": "^2.34",
-        "pestphp/pest-plugin-laravel": "^2.3"
+        "pestphp/pest": "^3.0",
+        "pestphp/pest-plugin-laravel": "^3.0"
```

`phpunit/phpunit`, `laravel/framework`, `php`, and `nunomaduro/collision`
are all **unchanged** — this was a two-line fix, not a framework or
architecture change.

## 4. Why these versions are compatible

- Pest 3's own `composer.json` (`3.x` branch) requires `phpunit/phpunit`
  within the 11.x line and explicitly **conflicts** with any
  `phpunit/phpunit` above 11.5.x — i.e. it refuses to install alongside
  PHPUnit 12+. Our root `^11.0.1` constraint is fully inside that range
  (`^11.0.1` means `>=11.0.1 <12.0.0`), so Composer's solver has room to
  pick a mutually satisfying PHPUnit 11.x version automatically — I didn't
  need to touch the `phpunit/phpunit` line at all.
- `pestphp/pest-plugin-laravel`'s 3.x releases are the ones built to pair
  with `pestphp/pest` 3.x — using `^3.0` on both keeps the plugin and the
  core package on the same generation, which is what Pest's own upgrade
  guide instructs ("update the pestphp/pest dependency to ^3.0... All
  other Pest-maintained plugins should be updated to version ^3.0").

## 5. Does existing test code need changes?

**No.** I checked our two test files against Pest 3's documented
breaking changes from 2.x:
- `tap()` for high-order testing was removed in Pest 3 (replaced by
  `defer()`) — not used anywhere in this project
- `expect($object)->toHaveMethod(...)` now requires `$object::class`
  instead of `$object` — not used anywhere in this project
- The old `uses(TestCase::class)->in('Feature', 'Unit')` syntax in
  `tests/Pest.php` is **still fully supported** in Pest 3 (a newer
  `pest()->extends(...)` alternative was added, but the old form wasn't
  removed)

`tests/Pest.php` and `tests/Feature/FoundationTest.php` are unchanged.

---

## Verification status

| Item | Status |
|---|---|
| `composer.json` is valid JSON after the edit | **TESTED** — verified in this sandbox |
| Pest 3 / PHPUnit 11 compatibility (the actual fix) | **TESTED** against Pest's official documentation and its own `3.x` branch `composer.json` (not guessed) |
| Test files reviewed against Pest 3's documented breaking changes | **TESTED** — grepped for the two affected APIs, neither is present |
| `composer install` actually resolving this dependency graph | **NOT VERIFIED** — re-ran it in this sandbox; still blocked by the same `repo.packagist.org` HTTP 403 as every previous attempt. The failure is confirmed to be the network block only, not a dependency-graph error (Composer never got far enough to evaluate the graph) |
| `vendor/`, `composer.lock` produced | **NOT VERIFIED** — same reason |
| `php artisan --version` / `key:generate` / `route:list` / `serve` / `test` | **NOT VERIFIED** — all require `vendor/`, not yet produced anywhere but your machine |
| `GET /api/v1/health` | **NOT VERIFIED** — same reason |

Everything above the line is something I could actually check here.
Everything in "NOT VERIFIED" needs your `composer install` run to confirm.
Given this fix directly targets the exact conflict Composer reported, I
expect it to resolve cleanly — but "expect" isn't "verified," and I'm not
claiming otherwise.

STOPPING HERE. STEP 03 has not started.
