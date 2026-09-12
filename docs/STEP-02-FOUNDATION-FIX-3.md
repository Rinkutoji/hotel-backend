# STEP 02 — Foundation Fix #3 (404 diagnosis, delivery fix, PHP version)

---

## 1. Root cause of the 404

**Confirmed correct:** the expected URL is `http://127.0.0.1:8000/api/v1/health`.
`bootstrap/app.php` calls `withRouting(api: routes/api.php, ...)` without
overriding the `apiPrefix` parameter, so Laravel applies its documented
default of `'api'` to every route in that file. `routes/api.php` then
wraps `/health` in `Route::prefix('v1')`. Result: `api/v1/health` — which
is exactly what your own `route:list` output shows. The URL isn't the
problem.

**I can't execute your machine, so I can't tell you with certainty which
of these it is** — but here's the ranked, honest list and how to tell
them apart:

1. **Most likely, given the pattern already confirmed below:** you're
   running `php artisan serve` (or had it started) against an older
   extracted copy of this project than the one your `composer install`
   / route:list output is from. This exact category of issue is
   confirmed to have happened with `composer.json` earlier, and — see
   Issue 2 below — has now also been confirmed for `bootstrap/app.php`.
   **Check:** run `sed -n '13p' bootstrap/app.php` in the *same* folder
   you're serving from. If it prints `use Throwable;`, you're not
   serving the code you think you are, and that alone would explain
   route weirdness alongside everything else.
2. **Stale route/config cache.** If `php artisan route:cache` or
   `optimize` was run at any point (even from a tutorial/habit, not
   something I asked you to run), Laravel serves routes from
   `bootstrap/cache/routes-v7.php` instead of live-parsing `routes/api.php`.
   This is the single most common cause of exactly this symptom
   (`route:list` correct, serving wrong) in Laravel generally. Cheap and
   safe to rule out regardless of cause #1.
3. **A second process already bound to port 8000** (e.g. an old `serve`
   process left running in another terminal from earlier troubleshooting),
   answering requests instead of your current one.

**Exact commands to isolate it, in order:**
```bash
# 1. Confirm you're on the current build (see BUILD-ID.txt / Issue 2)
sed -n '13p' bootstrap/app.php
grep -c "^test(" tests/Feature/FoundationTest.php

# 2. Clear every Laravel cache regardless of suspected cause - safe, free
php artisan optimize:clear

# 3. Confirm nothing else owns port 8000 (kill and restart serve if it does)
#    macOS/Linux:
lsof -i :8000
#    Windows (PowerShell):
#    Get-NetTCPConnection -LocalPort 8000

# 4. Restart serve fresh and hit it with verbose curl for real evidence
php artisan serve
# in a second terminal:
curl -v http://127.0.0.1:8000/api/v1/health
php artisan route:list --path=api/v1/health -v
```
The `-v` on both `curl` and `route:list` gives actual diagnostic output
(response headers, matched middleware) if it's still 404 after steps 1–3
— that output would tell us something concrete rather than guessing further.

## 2. Fix for the `Throwable` warning

**Already fixed — and I re-verified this before writing anything else in
this response**, since you reported the identical warning at the
identical line twice now. I extracted a completely fresh copy of the zip
currently at the download link and checked directly:

```
$ sed -n '13p' bootstrap/app.php

$ grep -rn "use Throwable" . --include="*.php"
./app/Http/Controllers/Api/V1/HealthController.php:9:use Throwable;
```

Line 13 of `bootstrap/app.php` is genuinely blank. The only remaining
`use Throwable;` in the whole project is in `HealthController.php` —
which is correct and necessary there (that file *does* declare a
namespace, so importing a global-namespace type like `Throwable` is
meaningful, not redundant — the warning only fires for non-namespaced
files importing non-namespaced names, which was specifically
`bootstrap/app.php`'s situation, not this one).

Given this is the second issue in this round that traces back to a
local/delivered mismatch, **I've stopped relying on you re-downloading a
same-named zip** (see below) and I'm also giving you the direct fix
in case anything is still out of sync:

```bash
# Run this from your project root - it's idempotent, safe to run even
# if the file is already correct
sed -i '' '/^use Throwable;$/d' bootstrap/app.php    # macOS
# sed -i '/^use Throwable;$/d' bootstrap/app.php      # Linux
grep -n "Throwable" bootstrap/app.php   # should show only the two usages, no import
```

**Delivery fix:** I've packaged this round under a **new filename**
(`hotel-backend-step02-fix3.zip`, not `-corrected.zip` again) and removed
the old filename from the outputs folder entirely, specifically to break
whatever caused the same-filename download to go stale. I also added
`docs/BUILD-ID.txt` with three fingerprint checks — run those first, any
time something seems out of sync with what a report says, before
troubleshooting the actual issue.

## 3. PHP version recommendation

**Recommend PHP 8.4** for local development, not 8.5. This isn't just
about the PDO warning - it's the officially supported range. Confirmed
directly (not assumed):

> Laravel 11 requires PHP 8.2 as the minimum version and supports PHP
> 8.2, 8.3, and 8.4.

PHP 8.5 was never added to Laravel 11's supported matrix. Laravel 12 and
13 did add 8.5 support later, but that update never came to the 11.x
line.

**One more thing worth flagging, since you specifically asked me to
evaluate the PHP/Laravel environment question — not proposing to change
anything, just making sure this is visible:** Laravel 11's security
support ended **March 12, 2026** (confirmed via multiple current sources,
including Laravel's own EOL tracking) — about five months before today.
It's no longer receiving any patches, security or otherwise. That's a
fact about the framework version itself, separate from the PHP version
question. Architecture Baseline already locks in Laravel 11 as an
approved decision, and I'm not reopening that here — just surfacing it,
since "which PHP version should we standardize on" and "is the framework
we're standardizing it for still maintained" are adjacent questions, and
you should have this in view even if the answer is "continue on 11
anyway" for course/scope reasons.

## 4. Does `config/database.php` need modification?

**No further change** — keep the version-guarded fix from the previous
round. Reasoning:
- On the now-recommended PHP 8.4, the deprecation doesn't fire at all
  (it starts at 8.5), so the guard is simply inert there — zero cost.
- If this project (or a future grader's environment, or a later hosting
  provider) ever does run PHP 8.5+, the guard still correctly avoids the
  warning without behavior change.
- It's fully backward-compatible with PHP 8.2/8.3/8.4 (tested directly
  in this sandbox on 8.3 last round).

Standardizing on 8.4 and keeping the defensive guard aren't
alternatives to each other - one is an environment recommendation, the
other is a cheap, permanent safety net in code we control. No reason to
give up the second just because we're recommending against needing it
right now.

---

## Verification status

| Item | Status |
|---|---|
| Expected URL confirmed via route wiring in `bootstrap/app.php`/`routes/api.php` | **TESTED** — traced the actual code path, matches your `route:list` output |
| `Throwable` import genuinely absent from `bootstrap/app.php` in the current package | **TESTED** — fresh re-extraction, checked directly, just now |
| Root cause of the 404 | **NOT VERIFIED** — I can't execute your machine; three ranked hypotheses given with commands to actually isolate it |
| PHP 11 supported range = 8.2–8.4, not 8.5 | **TESTED** against current sources, not assumed |
| Laravel 11 security support ended Mar 12, 2026 | **TESTED** against current sources |
| `config/database.php` guard still correct, no further change needed | **DESIGNED** — reasoning given, not independently re-tested this round since nothing changed |
| Whether `/api/v1/health` actually responds once caches are cleared / correct build confirmed | **NOT VERIFIED** — needs your run |

STOPPING HERE. STEP 03 has not started. Waiting for the diagnostic
command output before claiming anything about the health endpoint works.
