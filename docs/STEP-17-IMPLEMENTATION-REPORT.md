# STEP 17 — Pricing Tiers / Discounts — Implementation Report

Plan is in `docs/STEP-17-IMPLEMENTATION-PLAN.md` (approved via decision table
D-S0…D-7). This covers what was actually built. STEP 01–16 behavior is
unchanged; the full suite stays green (447 tests / 1386 assertions / 0
failures — baseline was 421 / 1232).

A partial draft of STEP 17 existed from the prior session. It was incomplete
and broken in two places (§6); this report covers the finished state.

---

## 1. Files Created

- `database/migrations/2025_02_11_000001_create_discounts_table.php` — the
  `discounts` table (additive, §2).
- `app/Models/Discount.php` — `name`, `min_nights`, `percent_off`; casts
  `min_nights` → `int`, `percent_off` → `decimal:2`.
- `app/Services/DiscountService.php` — tier resolution + integer-cent math:
  `resolveForNights()` (largest `min_nights <= nights`), `discountCentsFor()`,
  `discountedNightlyCents()`, `paginate()`.
- `app/Policies/DiscountPolicy.php` — **admin + manager only** CRUD (D-5).
- `app/Http/Requests/Discount/FilterRequest.php` — shape-only index filters
  (`?search=`, `?per_page=`).
- `app/Http/Requests/Discount/StoreRequest.php` — validates `name:required`,
  `min_nights:required integer min:1`, `percent_off:required numeric 0.01–100`;
  `prohibited` `id`/`timestamps`; everything else (money fields) silently
  ignored by `$request->safe()`.
- `app/Http/Requests/Discount/UpdateRequest.php` — same, with an `id != id`
  unique rule on `min_nights`.
- `app/Http/Controllers/Api/V1/Staff/DiscountController.php` — CRUD, two-layer
  `role:admin,manager` route + `DiscountPolicy::before` for every action.
- `tests/Feature/Discount/DiscountTierAdminTest.php` — 12 tests (CRUD, manager
  parity, receptionist 403, customer 403, guest 401, validation, unique
  `min_nights`, update immovable-onto-occupied, money-field injection ignored,
  additive table shape, DB-level UNIQUE).
- `tests/Feature/Discount/DiscountBookingPricingTest.php` — 10 tests (qualifying
  discount + snapshot math, largest-tier-no-stacking, below-min full price,
  fractional half-cent math, payment/invoice invariants, STEP 15 late-fee
  formula on discounted snapshot, edit/delete isolation, re-price after edit,
  booking-time injection, staff front-desk disclosure).
- `tests/Feature/Discount/DiscountAvailabilityTest.php` — 4 tests (D-4
  availability disclosure, below-min no `discount` key, multi-room per-room
  maths, availability)matches-booking consistency).
- `docs/STEP-17-IMPLEMENTATION-REPORT.md` (this file).

## 2. Migration / New Table

Applied to the XAMPP MySQL dev DB (`hotel_management_v2`) via
`php artisan migrate --force`; `migrate:status` shows it `Ran` (batch [8]).

```php
// 2025_02_11_000001_create_discounts_table
Schema::create('discounts', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('min_nights')->unique(); // largest <= stay nights wins (D-3)
    $table->decimal('percent_off', 5, 2);            // 0.01 … 100.00
    $table->timestamps();
});
```

Additive only: one new table, nothing on existing tables changed, no
`deleted_at`. `DatabaseSchemaTest` does not enumerate the table set and stays
green with zero edits (same reasoning STEP 14/15/16 relied on for additive
columns). There is deliberately **no** `code` column on the table — client
discount codes were approved out of scope (D-7).

## 3. Files Modified (all additive)

- `app/Services/BookingService.php` — refreshes the `DiscountService` on its
  create path: after the nightly-pricing pass it resolves the tier, recomputes
  each `price_per_night` in integer cents (`base - round(base × %/100)`),
  rewrites `total_amount`, and attaches a transient `discount` summary onto the
  created booking (`discount_id`, `name`, `min_nights`, `percent_off`, `nights`,
  `saved_total`, `total_after_discount`; `discountSummary()` also exposed via
  the helper). **This is the only STEP 17 change to booking creation, and it
  sits strictly inside the existing server-side pricing flow — the STEP 09
  architecture, held-room semantics, and confirmation/status engine are
  untouched.** Also fixed a fatal draft bug here: a duplicate
  `private const BOOKING_NUMBER_MAX_RETRIES = 3;` (see §6).
- `app/Services/BookingService.php` — `searchAvailable()` (D-4): each returned
  room now carries a transient `discount` attribute — `discount_id`, `name`,
  `min_nights`, `percent_off`, `nights`, `price_per_night` (base),
  `discounted_price_per_night`, `total_for_stay`, `total_after_discount`,
  `saved_total` — so search output shows the discounted totals the booking will
  actually charge. Attribute is transient (`setAttribute` on the model), never
  persisted, and absent when no tier qualifies.
- `app/Http/Controllers/Api/V1/Public/BookingController.php` — `availability`
  response includes a per-room `discount` key **only** when the transient
  attribute is set (contract unchanged for non-discounted stays).
- `app/Http/Controllers/Api/V1/Customer/BookingController.php` and
  `app/Http/Controllers/Api/V1/Staff/BookingController.php` — the booking
  create `data` payload carries the same transient `discount` summary (shared
  path, so staff front-desk and customer self-service both disclose it).
- `app/Policies/DiscountPolicy.php` — draft was admin-only; corrected to
  **admin + manager** per approved D-5 (§6).
- `routes/api.php` — `api/v1/staff/discounts` resource group
  `['auth:sanctum', 'actor:staff', 'role:admin,manager']` (draft was
  `role:admin`; corrected per D-5).
- `tests/Pest.php` — added one additive helper, `makeDiscount()`
  (factory-style model creation), used by the three STEP 17 test files.

## 4. Endpoint Behavior

| Endpoint | Behavior |
|---|---|
| `GET /staff/discounts` | paginated 15, `?search=` (name / min_nights / percent_off), `?per_page=` (1–50); **admin/manager** |
| `POST /staff/discounts` | create with `name`, `min_nights` (unique), `percent_off`; **admin/manager** |
| `GET /staff/discounts/{id}` | single tier; **admin/manager** |
| `PUT /staff/discounts/{id}` | update; `min_nights` must stay unique; **admin/manager** |
| `DELETE /staff/discounts/{id}` | soft-free delete of the definition; **admin/manager** |

Response `data` shape: `id`, `name`, `min_nights`, `percent_off`
(`decimal:2`). 401 guest / 403 wrong actor / 403 wrong role (receptionist) /
404 missing tier / 422 validation (incl. duplicate `min_nights`). Editing or
deleting a definition **never** mutates existing bookings — rooms keep their
persisted `price_per_night` snapshot (§5). No customer-facing surface exists
(D-7).

## 5. Pricing Rules (implemented)

- D-3: server-side integer cents. For each room the nightly charge is
  `base_price - round(base_price × percent_off / 100)` in cents, applied
  **before** the `booking_rooms.price_per_night` snapshot is written, so the
  snapshot stores the final charged nightly rate (90.00 for 100.00 @ 10%) and
  `bookings.total_amount` is the discounted total. Fractional bases stay exact
  (99.99 @ 50% → 49.99/night, 2 nights = 99.98; pinned by test).
- D-3: single discount per booking, no stacking — the tier with the **largest
  `min_nights <= stay nights`** applies once. Below every minimum → full price,
  no `discount` key anywhere.
- D-3: the discount is resolved and locked at booking time; the snapshot is
  the immutable source for all later money. `payments.amount` equals the
  discounted `bookings.total_amount`, invoice `total_amount` equals
  `payments.amount` (existing STEP 16 pipeline unmodified — see §9), and the
  STEP 15 late-fee formula computes from the discounted `price_per_night`
  snapshot (fee = 90.00 on a 90.00/night booking, tested).
- D-7: money/discount fields are never accepted from request bodies — injection
  attempts (`price_per_night`, `total_amount`, `discount_id`, `percent_off`,
  `min_nights` on the booking payload) are ignored by the existing form
  requests / safe-only writes and assertions pin the server-computed totals.

## 6. Approved Decisions and Deliberate Deviations

Every §B decision was approved D-S0 = Pricing tiers / discounts, D-1…D-7, and
was implemented **as approved**. The draft found mid-session broke one of those
(plus a fatal syntax bug), and two test-harness artifacts are worth noting:

1. **D-5 draft deviation — fixed:** the draft wired tier management as
   admin-only. Approved D-5 is **admin + manager** (`role:admin,manager` route
   + `DiscountPolicy::before` allowing both). `DiscountPolicy`,
   `DiscountController` docblock, `routes/api.php`, and the role matrix test
   all reflect the approved value.
2. **Fatal draft bug — fixed:** `BookingService.php` had a duplicated
   `private const BOOKING_NUMBER_MAX_RETRIES = 3;`. Removed the duplicate; the
   file now lints clean.
3. **Test-harness actor leak (no production impact):** the Sanctum guard caches
   the first resolved user for the life of a Pest test process, so a
   bearer-authenticated customer request followed by a bearer-authenticated
   staff request **in the same test** returns 403 on the staff call. This is
   the exact artifact STEP 16's report documents in §9. STEP 17 tests that must
   touch both actor surfaces switch actors with `Sanctum::actingAs()`
   immediately before each request (the guard's `setUser` override) and never
   mix bearer tokens — same usage as the existing suite. No production code,
   middleware, or route change was made or implied.
4. **D-4 search disclosure:** no decision change — the approved D-4 ("search
   output shows discounted totals") maps to the transient `discount` attribute
   attached inside `searchAvailable()` and rendered by the public
   availability endpoint; non-discounted output is byte-for-byte unchanged.

## 7. Authorization Behavior

- Two-layer enforcement: `role:admin,manager` middleware **and**
  `DiscountPolicy::before` granting both roles every tier action — belt and
  braces exactly like STEP 12's approval-gated surfaces.
- Verified matrix: admin OK / manager OK / receptionist 403 / customer 403 /
  guest 401, for every endpoint.
- Only staff may manage tiers (D-5); customers have no discount surface and no
  discount codes exist (D-7).

## 8. Transaction / Concurrency Behavior

- Tier reads and the booking-creation discount write happen inside the
  existing booking create path; the definition lookup is the only read, pricing
  is computed by `DiscountService` in pure integer cents, and the snapshot
  write is part of the booking's existing transaction. No new locks, no cross-
  booking coupling.
- Editing/deleting a definition touches only `discounts` — bookings keep their
  frozen `booking_rooms.price_per_night`/`total_amount` snapshots (isolation
  asserts edit AND delete against an already-booked stay, then a fresh booking
  picks up the new value).

## 9. Verification

- `php -l` clean on all 17 touched files.
- New-only run: `tests/Feature/Discount` → **26 passed / 154 assertions**.
- Full suite: **447 passed / 1386 assertions / 0 failures** (baseline 421 /
  1232 — every pre-existing test green, zero edits to them).
- Dev DB: `php artisan migrate --force` applied the discounts migration;
  `migrate:status` shows it `Ran` (batch [8]).

## 10. Explicitly Not Touched

`RoomService`, `RoomImageService`, `RoomTypeService`,
`PaymentService::create/uploadReceipt/reject/refund/verify` (STEP 16 invoice
pipeline runs verbatim on the discounted `payments.amount`), refunds (STEP 14),
notifications (STEP 04), every existing controller/policy/request except the
three booking controllers' additive disclosure above, `config/*`,
`.env`/`.env.example` (no new env keys), `routes/console.php`, every scheduled
command, and every existing test.