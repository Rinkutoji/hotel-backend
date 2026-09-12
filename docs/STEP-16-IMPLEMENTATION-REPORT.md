# STEP 16 — Invoices (billing records for verified payments) — Implementation Report

Plan is in `docs/STEP-16-IMPLEMENTATION-PLAN.md` (approved via decision table
D-S0…D-6, with one explicit override on D-3 — see §6). This covers what was
actually built. STEP 01–15 behavior is unchanged; the full suite stays green
(421 tests / 1232 assertions / 0 failures — baseline was 395 / 1082).

---

## 1. Files Created

- `database/migrations/2025_02_10_000001_create_invoices_table.php` — the
  `invoices` table (additive, §2).
- `database/migrations/2025_02_10_000002_create_invoice_items_table.php` —
  the `invoice_items` snapshot table (additive, §2). **D-3 override** of the
  plan's recommended design — see §6.
- `app/Models/Invoice.php` — immutable invoice record, `belongsTo(Payment)` +
  `hasMany(InvoiceItem)` (`items()`).
- `app/Models/InvoiceItem.php` — persisted billing line-item snapshot.
- `app/Services/InvoiceService.php` — `generateForPayment()` + `generateInvoiceNumber()`
  (INV- retry loop mirroring `generateBookingNumber()`).
- `app/Policies/InvoicePolicy.php` — two-actor read policy (`viewAny`/`view`).
- `app/Http/Requests/Invoice/FilterRequest.php` — shape-only index filters.
- `app/Http/Controllers/Api/V1/Staff/InvoiceController.php` — read-only
  index/show, any staff role.
- `app/Http/Controllers/Api/V1/Customer/InvoiceController.php` — read-only
  index/show, own invoices only.
- `tests/Feature/Invoice/InvoiceGenerationTest.php` — 11 tests (generation,
  snapshots, no-invoice paths, double-verify, atomic rollback, injection,
  refund survival, schema/UNIQUEs).
- `tests/Feature/Invoice/StaffInvoiceTest.php` — 8 tests (index/search/roles/
  filter/401-403/shape/refund/per_page).
- `tests/Feature/Invoice/CustomerInvoiceTest.php` — 7 tests (own-only, 404 on
  foreign, actor 403s both ways, refund before/after, empty list, guest 401).
- `docs/STEP-16-IMPLEMENTATION-REPORT.md` (this file).
- `docs/STEP-16-IMPLEMENTATION-PLAN.md` (written and approved before coding).

## 2. Migration / New Tables

Both tables applied to the XAMPP MySQL dev DB (`hotel_management_v2`) via
`php artisan migrate --force`; `migrate:status` shows both `Ran` (sequence [7]).

```php
// 2025_02_10_000001_create_invoices_table
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->string('invoice_number')->unique();          // INV-XXXXXXXX
    $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
    $table->decimal('total_amount', 10, 2);
    $table->timestamp('issued_at')->useCurrent();
    $table->timestamps();
    $table->unique('payment_id');                        // 1:1 invoice↔payment (BR-2)
});

// 2025_02_10_000002_create_invoice_items_table  (D-3 override — see §6)
Schema::create('invoice_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
    $table->string('room_number');                       // snapshot
    $table->string('room_type_name');                    // snapshot
    $table->decimal('price_per_night', 10, 2);          // booking_rooms snapshot
    $table->unsignedInteger('nights');
    $table->decimal('line_total', 10, 2);               // price_per_night × nights, int cents
    $table->timestamps();
});
```

Additive only: two new tables, nothing on existing tables changed, no
`deleted_at`. `DatabaseSchemaTest` does not enumerate the table set and stays
green with zero edits (same reasoning STEP 14/15 relied on for additive
columns).

## 3. Files Modified (all additive)

- `app/Services/PaymentService.php` — constructor gains `InvoiceService`; the
  **existing** `verify()` `DB::transaction` gains exactly one appended call
  (`$this->invoices->generateForPayment($lockedPayment);`) after
  `confirmWithinTransaction()`. Every pre-check, lock, message and the returned
  `fresh(['booking', 'verifiedBy'])` are byte-identical. `create/uploadReceipt/
  reject/refund` completely untouched.
- `app/Models/Payment.php` — `invoice(): hasOne` relation (not eager-loaded by
  any existing controller → existing payment/booking response JSON unchanged).
- `routes/api.php` — two read-only route groups, additive only:
  - `GET /api/v1/customer/invoices`, `GET /api/v1/customer/invoices/{invoice}`
    — `['auth:sanctum', 'actor:customer']`, own-only.
  - `GET /api/v1/staff/invoices`, `GET /api/v1/staff/invoices/{invoice}`
    — `['auth:sanctum', 'actor:staff']`, any staff role, no `role:*`.
- `tests/Pest.php` — **not modified** (the plan's §8 "additive helper, if
  useful" was not needed; helpers were kept file-local in `tests/Feature/Invoice/`).

## 4. Endpoint Behavior

| Endpoint | Behavior |
|---|---|
| `GET /customer/invoices` | own only (scoped `whereHas('payment.booking', customer_id)`), paginated 15, ordered `issued_at` desc |
| `GET /customer/invoices/{id}` | **404** unless the invoice belongs to the caller (`scopedOwnedInvoice` + `InvoicePolicy::view`) |
| `GET /staff/invoices` | all invoices, paginated 15, `issued_at` desc, `?search=` (booking_number / customer name / email), `?per_page=` (1..50) |
| `GET /staff/invoices/{id}` | any invoice, any staff role |

Response `data` shape (both actors): `id`, `invoice_number`, `total_amount`
(`decimal:2`), `issued_at`, embedded `payment` (`id`, `amount`, `method`,
`status`), its `booking` (`id`, `booking_number`, `check_in_date`,
`check_out_date`, `status`) with nested `customer`, and `items` — the persisted
line-item snapshots (`room_number`, `room_type_name`, `price_per_night`,
`nights`, `line_total`). 401 guest / 403 wrong actor / 404 foreign or missing;
no 409 (no state-changing surface). No write route exists anywhere (405 on a
POST attempt).

## 5. Generation Rules (implemented)

- BR-1: an invoice exists **iff** its payment was verified — generation runs
  only inside `PaymentService::verify()`. `pending / receipt_uploaded /
  rejected` never generate; hold-expiry, no-show, staff cancel and customer
  self-cancel of unpaid bookings generate nothing (tested).
- BR-2/BR-6: one invoice per payment (DB `UNIQUE(payment_id)`); a second
  verify → 409 before any write, backstopped by the UNIQUE. A refund leaves the
  invoice in place as the historical document (`PaymentService::refund` is
  untouched; `invoices.payment_id` = `payments.id` still holds after refund).
- BR-3: `total_amount = payments.amount = bookings.total_amount`, read from the
  locked rows inside the verify transaction; integer-cent line totals summed
  into the persisted item table (`Σ line_total = total_amount` by construction;
  fractional-snapshot accumulation pinned by test).
- BR-4: `invoice_number = 'INV-' + 8 uppercase random`, unique retry loop
  (max 3), `unique()` backstop — byte-for-byte the `BK-` generator convention.
- BR-8: a confirmed→checked_in→checked_out booking with no verified payment
  has no invoice (tested).
- D-3: **line-item snapshots are persisted at issuance** — invoice items never
  re-read mutable `rooms.room_number` / `room_types.name` / prices; historical
  invoices are stable even if room/type/price change later.

## 6. Approved Decisions and Deliberate Deviations from the Plan Document

Every §B decision was approved D-S0 = Invoices, D-1…D-6. Two implementation
notes apply:

1. **D-3 override (explicitly approved by the user):** *persist billing
   line-item snapshots; never recalculate historical prices*. This supersedes
   the plan's *recommended* D-3 ("no invoice_items table; itemization derives
   read-only from `booking_rooms`") and its BR-7. Implemented exactly as the
   override states: an `invoice_items` snapshot table + `InvoiceItem` model,
   written inside the same generation transaction, exposed as `items` on every
   invoice response. Rationale honoured: `booking_rooms` rows are structurally
   immutable but the *displayed* room number, type name and price can change
   (room/type update endpoints exist), so persisting the snapshot makes the
   invoice fully self-contained.
2. **FilterRequest `status => ['prohibited']`:** plan §5 said the filter
   mirrors `Payment/FilterRequest` (search/per_page only), but plan §13
   scenario 14 requires `?status=` → 422. Laravel ignores unknown query fields
   unless the rule exists, so a `prohibited` rule was added to satisfy the
   plan's own scenario.

Notes (not deviations, clarifying §3 vs §8 of the plan): the response nests
`booking`/`customer` under `payment` (the plan's §8 controller specification —
`with('payment.booking.customer')`); §3's JSON illustration shows them as
siblings, so the test asserts the implemented nested shape. New-test count is
26 (plan estimated ~28–32) — every §13 scenario is covered, and the suite's
assertion delta is +150.

## 7. Authorization Behavior

- Staff list/view: any role (`actor:staff` middleware + `InvoicePolicy::viewAny/
  view` returning true for every `Employee`) — admin, manager and receptionist
  each verified with a matrix test. No `role:*` on the read routes.
- Customer list/view: `actor:customer` + ownership enforced twice — scoped
  `findOrFail` (404 for another customer, never 200/403) **and**
  `InvoicePolicy::view` (customer branch compares `payment.booking.customer_id`).
- Actors are separated exactly like STEP 10 (`PaymentPolicy` shape): customer
  on staff routes → 403; staff on customer routes → 403; guest → 401.
- Generation is not an authorization surface: it runs inside verify, which is
  already gated by `role:admin,manager,receptionist` + `PaymentPolicy::verify`.

## 8. Transaction / Concurrency Behavior

- Invoice + line items are written **inside `PaymentService::verify()`'s
  existing single `DB::transaction`**. The rollback test forces a failure on the
  first `InvoiceItem` insert (after the invoice row is already written) and
  asserts the invoice, items, payment status, booking status and room status all
  roll back together. `InvoiceService` starts no transaction of its own —
  callers own the boundary (same convention as
  `confirmWithinTransaction`/`refundBooking`).
- Two concurrent verifies serialize on `lockForUpdate`; the loser re-reads a
  non-`receipt_uploaded` status → 409; the `UNIQUE(payment_id)` is the DB
  backstop. Number collisions are bounded-retry + `unique()` (aborts + rolls
  back cleanly on the rare miss). No new cross-payment locking.
- Idempotency: verify's existing `receipt_uploaded`-only guard means
  generation is reachable at most once per payment; invoice `issued_at` is set
  server-side at issuance and never updated.

## 9. Verification

- `php -l` clean on all 15 files touched.
- New-only run: `tests/Feature/Invoice` → **26 passed / 150 assertions**.
- Full suite: **421 passed / 1232 assertions / 0 failures** (baseline
  395 / 1082 — every pre-existing test green, zero edits to them).
- Dev DB: `php artisan migrate --force` applied both migrations; `migrate:status`
  shows them `Ran` (sequence [7]).

Test-design note: the Sanctum guard caches the first resolved user for the
whole test, so customer tests that need a real customer token to resolve seed
their invoices via direct `PaymentService::verify` calls (no HTTP auth request)
instead of HTTP — the same pattern the existing suite uses `Sanctum::actingAs`
to work around.

## 10. Explicitly Not Touched

`BookingService`, `RoomService`, `RoomImageService`, `RoomTypeService`,
`PaymentService::create/uploadReceipt/reject/refund`, every existing
controller/policy/request, `config/*`, `.env`/`.env.example` (no new env keys),
`routes/console.php`, every scheduled command, and every existing test.