# STEP 16 — Invoices (billing records for verified payments) — Implementation Plan

> **Status: plan-only. Nothing is implemented, migrated, tested, or routed.**
> STEP 16's scope has **no pre-existing plan file** anywhere in the repo, the
> reference project, or the sibling phase folders (verified by listing
> `docs/`, grepping the whole htdocs tree for `STEP 16`/`Step 16`, and checking
> the reference `hotel-management-system` docs — zero matches). This plan
> therefore **derives the STEP 16 scope from the documented deferred/out-of-scope
> items** in STEP 09–15 (§0.1), follows the exact decision-point discipline of
> STEP 14/15 (flag, don't assume — every decision is a §B approval item), and
> stops for approval.
>
> **Recommended scope (approval item D-S0): Invoices — an immutable billing
> record generated *atomically* when a payment is verified**, closing the
> longest-standing money-domain deferral left in this project. Rationale and
> alternatives in §0.2/§B.

---

## 0. Pre-Coding Review — Read the Actual Repository First

### 0.1 Where STEP 16 came from — the complete deferred-items catalog

Everything below is **verified against the current codebase**, not assumed.
`docs/` contains plans/reports for STEP 02–15 only. Every STEP 09–15 plan
carries the same group of "deliberately out of scope / deferred" lines, and
each STEP that landed consumed one of them. After STEP 11 (refunds), STEP 12
(staff booking), STEP 13 (cleaning), STEP 14 (no-show) and STEP 15 (late-fee),
the remaining deferrals are:

| # | Deferred item | Documented in | Status today |
|---|---|---|---|
| 1 | **Invoices (billing records)** | STEP-10-PAYMENT-PLAN.md:56 (`"invoices"`), STEP-12-IMPLEMENTATION-PLAN.md:86, STEP-13-IMPLEMENTATION-PLAN.md:106, STEP-15-IMPLEMENTATION-PLAN.md:150/667 | **Never implemented — zero mentions beyond the out-lists** |
| 2 | Pricing tiers / discounts | STEP-09-BOOKING-PLAN.md:49, STEP-12-IMPLEMENTATION-PLAN.md:83/436, STEP-15-IMPLEMENTATION-PLAN.md:667 | Never implemented (STEP 15 only did *fees*, a separate item) |
| 3 | Notifications / emails | STEP-10-PAYMENT-PLAN.md:56, STEP-11-IMPLEMENTATION-PLAN.md:83, STEP-12-87, STEP-13-106, STEP-14-PLAN.md:115/467, STEP-15-150/668 | Never implemented |
| 4 | Idempotency keys | STEP-09-BOOKING-PLAN.md:50, STEP-12-86, STEP-15-668 | Never implemented |
| 5 | Staff creating payments for a booking; editing/deleting payments | STEP-10-PAYMENT-PLAN.md:56–57 | Never implemented |
| 6 | Partial refunds / customer-initiated refunds / refund approval workflows | STEP-11-IMPLEMENTATION-PLAN.md:78–83, STEP-15-150 | Never implemented |
| 7 | Payment gateways / 3rd-party providers / webhooks / real money movement | STEP-10-PAYMENT-PLAN.md:55–56, STEP-11-78, STEP-15-665 | Never implemented (out by design) |
| 8 | Dashboard / reporting / UI | STEP-12-87, STEP-13-106/588, STEP-14-115/467, STEP-15-668 | Never implemented (pure-API backend) |

### 0.2 Why Invoices is the recommended STEP 16 (and the alternatives)

**Evidence for recommending invoices:**

1. It is the **only remaining *money-domain* deferral that recurs in every
   out-list** (#1) and is *not* a platform/engineering hardening item.
2. The reference roadmap makes "payment records ... and generates its invoice
   **atomically**" a named milestone for the money module
   (`10_DEVELOPMENT_ROADMAP.md` §2 M5 and §5 task 8). The atomic-transaction
   machinery it needs already exists and is battle-tested here:
   `PaymentService::verify()` already commits `payment → verified` +
   `booking → confirmed` in one `DB::transaction` with `lockForUpdate`
   (app/Services/PaymentService.php:107–150).
3. It is a clean vertical: **one new table, one model, one service, two
   controllers, one policy, one request, tests** — the exact shape of the
   modules the repo has shipped repeatedly (STEP 10/11 as the closest siblings).
4. It deliberately does **not** touch the project's most-guarded invariants
   (server-side pricing, `bookings.total_amount`, `payments.amount` = the
   booking total, and the late-fee snapshot formula) — unlike pricing-tier
   work, regression risk is contained.

**Strongest alternatives (any is approvable; the whole §1–§9 design below is
written against Invoices):**

- **D-S0(b) Pricing tiers / discounts** — the item listed *first* in the STEP 09
  money out-list, but it modifies `BookingService::create()`,
  `searchAvailable()` pricing, the `booking_rooms.price_per_night` snapshot,
  the `payments.amount = total` invariant **and** STEP 15's late-fee snapshot
  formula — the highest-regression-risk feature in the remaining catalog.
- **D-S0(c) Notifications (in-app)** — DB-backed notification records with
  staff/customer list + mark-read endpoints and additive hooks into booking /
  payment / verification events. Lower risk, but it is a *platform* feature
  with no money-domain completion value.
- **D-S0(d) Staff payment creation & payment editing/deletion** (STEP 10
  out-list) — fills the gap that no `cash` payment can be recorded today
  (the only creation route is customer-side).

### 0.3 Verified facts about the current state STEP 16 builds on

All checked against the actual code, green suite **395 tests / 1082 assertions /
0 failures** (STEP 15 report §8).

| Surface | Current state (verified) |
|---|---|
| Payments | 1:1 with bookings — `payments.booking_id` UNIQUE (STEP 03 migration `…000009`). Status enum `pending, receipt_uploaded, verified, rejected, refunded`. `create()` locks `amount = bookings.total_amount` (PaymentService:53). Money/status/verified fields never in `$fillable`, never accepted on any request (Payment.php:20–22). |
| Verify | `verify()` (PaymentService:107–150): one transaction — payment `receipt_uploaded→verified` (+`verified_at`/`verified_by`), then `BookingService::confirmWithinTransaction()` (`pending_payment→confirmed` + room `available→reserved` + audit row). Re-reads both rows with `lockForUpdate` and re-checks status under the lock. |
| Refund | `refund()` (PaymentService:193–231), admin/manager only via `process-refund` Gate; refunds full `payments.amount`, cancels the booking, releases rooms. **STEP 11/15 kept this engine untouched**; STEP 16 (recommended D-2) keeps it untouched too. |
| Pricing | Server-side integer cents, snapshot on `booking_rooms.price_per_night` at booking time (BookingService:116–137), `payments.amount` = booking `total_amount`. No float drift. |
| Numbering | `booking_number = 'BK-' + 8 uppercase random`, unique retry loop, max 3 attempts (BookingService:558–573) — the template for `INV-`. |
| Auth envelope | `auth:sanctum` + `actor:customer`/`actor:staff` middleware; staff routes two-layer (`role:*` route middleware **and** `$this->authorize()`); customer ownership enforced twice (policy + scoped `findOrFail` → 404). `ApiResponse` envelope; 401/403/404/409 mapped in `bootstrap/app.php`. |
| Schema test | `DatabaseSchemaTest` asserts existence of the 10 approved tables and removal of `users/cache/jobs` — it does **not** enumerate the table *set* or the bookings/payments column set (verified: only per-table positive checks). Adding an `invoices` table needs **zero** edits there — same reasoning STEP 14/15 relied on for additive columns. |
| Response shapes | No `assertExactJson` anywhere in `tests/Feature/Payment/` (grep verified) — but STEP 16 still keeps every existing payment/booking response byte-identical (D-4). |
| No tax layer | STEP 03 deliberately created no `tax_types`/`booking_taxes`/`invoices`. An invoice in this schema is a **non-tax invoice** = the server-computed stay total. |

### 0.4 Gaps STEP 16 closes

1. Today there is **no billable document at all**: a verified payment is
   recorded (receipt, verification, audit), the booking confirms, and that is
   the entire paper trail. No `INV-` record, no per-booking billing record a
   customer or staff member can pull.
2. The customer/staff surfaces can show payments but nothing that says "this
   is what you were billed for that payment" as an immutable document.

---

## 1. Exact Objective and Scope

**Objective:** generate an **immutable invoice record** for every verified
payment, atomically inside the existing payment-verification transaction, and
make it readable through dedicated staff and customer endpoints.

**In (working MVP):**
- One new additive table `invoices` (1:1 with `payments`, enforced by a UNIQUE
  constraint — same 1:1 pattern STEP 03 uses for `payments.booking_id`).
- Invoice generated **inside `PaymentService::verify()`'s existing
  `DB::transaction`**, so `payment → verified`, `booking → confirmed`, room
  reservation **and invoice issuance commit or roll back together**.
- Server-computed `total_amount` = `payments.amount` = `bookings.total_amount`
  (integer-cents snapshot; never from request input).
- `INV-XXXXXXXX` numbering mirroring the booking-number generator
  (unique, bounded retry).
- Dedicated read-only endpoints: staff list/show (any staff role), customer
  list/show (own only).
- Immutable by construction: **no update/delete/void endpoints**, no status
  column (D-2), no soft-delete (consistent with `payments`/`bookings`).
- Refund (STEP 11) leaves an already-issued invoice in place as the historical
  billing document (D-2).

**Out (deliberately — every line is a documented deferral, not an omission):**
- ❌ No taxes/TVA (no tax schema exists in this project; invoice = non-tax).
- ❌ No PDF/file generation, no emailing — a JSON/DB billing record only (no
  dompdf/mpdf dependency; `QUEUE_CONNECTION=sync`, mail not configured).
- ❌ No invoices for unpaid stays, hold-expiry, no-show, or cancelled-without-
  payment bookings (an invoice exists only for a **verified** payment — D-1).
- ❌ No editing, deleting, voiding, or reversing invoices; no `status` column
  unless D-2(b) is approved.
- ❌ No change to the refund engine (`PaymentService::refund`,
  `BookingService::refundBooking`) unless D-2(b) is approved.
- ❌ No change to pricing, discounts, fees, payment amounts, any booking
  lifecycle step, or the late-fee snapshot formula.
- ❌ No change to existing payment/booking response shapes (D-4).
- ❌ No email/notification, no dashboard/reporting/UI, no idempotency keys
  (all still in the remaining deferred catalog).

---

## 2. What the Repository Already Says (evidence)

| Topic | Finding | Source (verified) |
|---|---|---|
| Invoices | "Invoices" appears **only** in out-of-scope lines; zero definition | STEP-10-PAYMENT-PLAN.md:56, STEP-12-…:86, STEP-13-…:106, STEP-15-…:150/667 |
| Atomic generation precedent | Payment verify already atomically confirms the booking | `PaymentService::verify()` PaymentService.php:107–150 |
| 1:1 record precedent | `payments.booking_id` UNIQUE enforces booking↔payment 1:1 at the DB level | STEP-03-DATABASE-PLAN.md §2/§4; migration `…000009` |
| Money convention | `decimal(10,2)` everywhere; server-side integer cents; never client money | STEP-03-DATABASE-PLAN.md §4; BookingService:116–137; PaymentService:53 |
| Numbering precedent | `BK-` + 8 uppercase random, unique retry loop | `BookingService::generateBookingNumber()` :558–573 |
| Immutable-record precedent | `payments`/`bookings` have no `deleted_at`; lifecycle = status | STEP-03-DATABASE-PLAN.md §6; `DatabaseSchemaTest` :284–287 |
| Audit-record precedent | `cancelled_at`/`no_show_at`/`late_fee_*` are point-in-time booking-row records | Booking.php casts/fillable; STEP 14 §3; STEP 15 §16 |
| No tax schema | STEP 03 created exactly 10 tables, no tax tables | STEP-03-DATABASE-PLAN.md §1 (verified migration list, 15 files = 10 core + 4 fix + sanctum) |

**Conclusion:** the *mechanism* is fully precedented (additive table + 1:1
UNIQUE + server-side amounts + number generator + inside-verify transaction +
two-actor policy). The only genuinely open calls are the business-shape
decisions in §B.

---

## 3. API Contract

**No client-write endpoint exists.** Invoices are created only by the server
inside verify; the only API surfaces are read-only.

### Customer — `/api/v1/customer/invoices` — middleware `['auth:sanctum', 'actor:customer']`

| Method | URL | Request | Notes |
|---|---|---|---|
| GET | `/invoices` | `?per_page=` | own only, paginated (15); eager `payment.booking.customer`; orderByDesc `issued_at` |
| GET | `/invoices/{invoice}` | – | **404** unless the invoice's payment's booking belongs to the caller (scoped `findOrFail`, both policy + query scoping) |

### Staff — `/api/v1/staff/invoices` — middleware `['auth:sanctum', 'actor:staff']`

| Method | URL | Route middleware | Notes |
|---|---|---|---|
| GET | `/invoices` | none (any staff) | all, paginated (15); `?search=&per_page=`; search = booking_number / customer name / email (same shape as staff payments `PaymentController::index`) |
| GET | `/invoices/{invoice}` | none (any staff) | any invoice; eager `payment.booking.customer` |

**Invoice response shape** (both actors; `data` object, `{success, message,
data}` envelope):

```json
{
  "id": 1,
  "invoice_number": "INV-A1B2C3D4",
  "total_amount": "200.00",
  "issued_at": "2026-09-09T10:00:00.000000Z",
  "created_at": "2026-09-09T10:00:00.000000Z",
  "payment": { "id": 1, "amount": "200.00", "method": "bank_transfer", "status": "verified" },
  "booking": { "id": 1, "booking_number": "BK-XXXX", "check_in_date": "2026-09-10", "check_out_date": "2026-09-12", "status": "confirmed", "customer": { "id": 1, "name": "Guest", "email": "guest@example.com" } }
}
```

HTTP statuses: **200** success; **401** guest; **403** customer on staff route /
staff on customer route (actor middleware); **404** another customer's invoice
(scoped `findOrFail` / `ModelNotFoundException` mapping in `bootstrap/app.php`).
No 409 (no state-changing surface).

---

## 4. Authorization / RBAC

| | Admin | Manager | Receptionist | Customer (own) | Guest |
|---|:---:|:---:|:---:|:---:|:---:|
| Staff list/view any invoice | ✅ | ✅ | ✅ | – | ❌ |
| Customer list/view own invoice | – | – | – | ✅ | ❌ |
| Generate invoice | server-only (no route) | server-only | server-only | – | – |
| Update / delete / void | ❌ no route | ❌ | ❌ | ❌ | ❌ |

- `InvoicePolicy` follows the two-actor pattern (`PaymentPolicy` verbatim):
  `viewAny` → `Customer || Employee`; `view` → Customer must own the
  invoice's `payment.booking.customer_id`, Employee → any staff.
- Read visibility matches `PaymentPolicy` exactly (customer owns through the
  booking; staff see everything) — no new role tiering, consistent with the
  STEP 10/11 matrices.
- Generation is **not** an authorization surface: it happens inside
  `PaymentService::verify()`'s transaction, which is already gated by the
  verify route (`role:admin,manager,receptionist` + `PaymentPolicy::verify`).
  No actor/role decision is possible at generation time — the acting employee
  is the verifier, already recorded on the payment (`verified_by`).

---

## 5. Validation

- **No new client input to validate beyond one filter request.** Invoices have
  no create/update payload — nothing a client can send to influence `amount`,
  `invoice_number`, or `issued_at`.
- `app/Http/Requests/Invoice/FilterRequest.php` mirrors `Payment/FilterRequest`:
  `search` nullable string `max:255`; `per_page` nullable integer `min:1`
  `max:50` (the room/payment index allowlist convention, STEP 10-…:§8).
- Request validate **shape**; the service/policy decide **state** — same stance
  as STEP 14 §8 / STEP 15 §21. The only protected "fields" are server-side
  writes, exactly as `cancelled_at`/`no_show_at`/`late_fee_amount` already are
  (not fillable from any request; a client-sent invoice key is ignored).

---

## 6. Business Rules (explicit, for review)

| # | Rule | Rationale / Source |
|---|---|---|
| BR-1 | An invoice exists **iff** its payment was verified. Generated inside the verify transaction, never on any other path. | Only verified payments are real money events; `pending/receipt_uploaded/rejected` never generate; `refunded` is not a generate trigger (a refunded payment had previously verified ⇒ its invoice already exists and persists, BR-6). |
| BR-2 | **1:1 invoice↔payment**, enforced by `invoices.payment_id` UNIQUE + `hasOne`. | Mirrors `payments.booking_id` UNIQUE (STEP 03 §4). Also the idempotency guard: verify's existing `receipt_uploaded`-only guard + UNIQUE mean at most one invoice per payment. |
| BR-3 | `total_amount` = `payments.amount` = `bookings.total_amount`, captured from the locked rows inside the verify transaction. | The project's money invariant (STEP 09 §5, STEP 10 §5). Server-side only. |
| BR-4 | `invoice_number` = `INV-` + 8 uppercase random, unique via bounded retry + UNIQUE constraint. | Byte-for-byte the `generateBookingNumber()` convention (BookingService:558–573). |
| BR-5 | Invoices are **immutable**: no update/delete/void surface, no `deleted_at`, at most an `issued_at` timestamp (D-2). | Same lifecycle-by-record philosophy as `payments`/`bookings` (STEP 03 §6) and `late_fee_*` (STEP 15 §16). |
| BR-6 | Refund does **not** touch the invoice. An invoice survives a later refund as the historical billing document; the refund is recorded on the payment/booking as today. | Recommended (D-2). STEP 11/15 explicitly kept `PaymentService::refund` untouched; this keeps the refund engine 100% intact. |
| BR-7 | Line items are **not** persisted; they derive read-only from `booking_rooms.price_per_night` snapshots × nights (existing immutable rows, no update/delete path). | STEP 03 §6 "no unnecessary fields" (D-3). |
| BR-8 | No invoice for unpaid stays: a `confirmed→checked_in→checked_out` booking with no verified payment has no invoice. | Payments are optional in this system today (staff can confirm/check-in without one); an invoice documents a billing event, not a stay. D-1 flags this explicitly. |
| BR-9 | Customer can read **own** invoices only (policy + scoped `findOrFail`); staff any-role read any. | Exactly the `PaymentPolicy` ownership model (STEP 10 §3). |
| BR-10 | No change to any existing booking/payment/room transition, amount, message, or response shape. | Regression contract, §10. |

---

## 7. Database Impact

**One new table, nothing else touched.** Additive only — existing rows
unaffected; no column/enum/index changes on existing tables; no `deleted_at`.

`database/migrations/2025_02_10_000001_create_invoices_table.php`

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->string('invoice_number')->unique();
    $table->foreignId('payment_id')
        ->constrained('payments')
        ->cascadeOnDelete();
    $table->decimal('total_amount', 10, 2);
    $table->timestamp('issued_at')->useCurrent();
    $table->timestamps();
    $table->unique('payment_id');
});
```

Why safe (`DatabaseSchemaTest` stays green with **zero edits** — verified):
it only asserts the 10 approved tables *exist* and that `users/cache/jobs` are
absent; it does not enumerate the table set or the payments column set. The
`invoices` table is 100% additive. (Same reliance STEP 14/15 used for additive
bookings columns.)

Notes:
- `issued_at` is an explicit business timestamp (not relying on `created_at`)
  so the moment of issuance is a first-class field — the `no_show_at` /
  `cancelled_at` convention (STEP 14 §3). `$table->timestamps()` additionally
  keeps the standard Eloquent pair, matching every business table except
  append-only `room_status_logs`.
- `cascadeOnDelete` on `payment_id` mirrors `payments.booking_id → cascadeOnDelete`
  (STEP 03 §4): an invoice has no independent meaning without its payment.
- No extra index: UNIQUE on `invoice_number`, UNIQUE on `payment_id`, and the
  FK auto-index are sufficient for the two list queries (both order by
  `issued_at`, small-volume; payments list does the same with no index).

---

## 8. Models / Services / Controllers / Requests

### New files

| File | Content |
|---|---|
| `app/Models/Invoice.php` | `$fillable = ['invoice_number','payment_id','total_amount','issued_at']`; casts `'total_amount' => 'decimal:2'`, `'issued_at' => 'datetime'`; `belongsTo(Payment::class)`. No SoftDeletes (BR-5), same as `Payment`. |
| `app/Services/InvoiceService.php` | `generateForPayment(Payment $payment): Invoice` — computes `total_amount` from the payment's locked amount, sets fields directly (not from input), number via private `generateInvoiceNumber()` (INV- retry loop mirroring `BookingService::generateBookingNumber`), saves, returns `fresh(['payment.booking.customer'])`. |
| `app/Policies/InvoicePolicy.php` | Two-actor pattern (verbatim `PaymentPolicy` shape): `viewAny` → `Customer || Employee`; `view` → Customer owns via `payment.booking.customer_id === user->id`, Employee → any staff. |
| `app/Http/Controllers/Api/V1/Staff/InvoiceController.php` | `index` (FilterRequest, `with('payment.booking.customer')`, optional `search` across booking_number / customer name / email, orderByDesc `issued_at`, paginate 15) + `show` (eager same relations). Both call `$this->authorize()`. |
| `app/Http/Controllers/Api/V1/Customer/InvoiceController.php` | `index` (scoped `whereHas('payment.booking', customer_id = user)` + `authorize`) + `show` (scoped `findOrFail` → 404 unless own, plus `authorize('view', $invoice)`). |
| `app/Http/Requests/Invoice/FilterRequest.php` | `search` nullable string max 255; `per_page` nullable integer 1..50. |

### Modified files (all additive)

| File | Change |
|---|---|
| `app/Services/PaymentService.php` | Inject `InvoiceService` via the constructor (next to the existing `BookingService`); inside the **existing** `verify()` `DB::transaction`, after `confirmWithinTransaction($lockedBooking)`, call `$this->invoices->generateForPayment($lockedPayment);`. Pre-checks, locks, booking transition and the returned `fresh()` all stay byte-identical. |
| `app/Models/Payment.php` | Add `invoice(): hasOne` relation (additive; **not** eager-loaded on any existing controller → no JSON change, D-4). |
| `tests/Pest.php` | Additive helper `makeVerifiedBookingAndPayment(...)` (booking + booking_rooms + payment `verified`), if a single helper proves useful across the invoice test files. No existing helper edited. |

### Explicitly NOT modified

`BookingService`, `RoomService`, `RoomImageService`, `RoomTypeService`, any
request other than the new one, all 6 existing policies, `config/booking.php`,
`.env`/`.env.example`, `routes/console.php`, every existing controller, and
every existing command.

---

## 9. Routes (additive only — `routes/api.php`)

Inside the existing `customer` group, after the `payments` block:

```php
// STEP 16 - customer invoices (read-only, own only). Generated server-side
// inside PaymentService::verify; customers can never write.
Route::prefix('invoices')->middleware(['auth:sanctum', 'actor:customer'])->group(function () {
    Route::get('/', [CustomerInvoiceController::class, 'index']);
    Route::get('/{invoice}', [CustomerInvoiceController::class, 'show']);
});
```

Inside the existing `staff` group, after the `payments` block (any staff read —
same tiering as the staff payments read routes, no `role:*` needed):

```php
// STEP 16 - staff invoices (read-only). Visible to any staff role; generated
// only by payment verification (verify route stays role:admin,manager,receptionist).
Route::prefix('invoices')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
    Route::get('/', [StaffInvoiceController::class, 'index']);
    Route::get('/{invoice}', [StaffInvoiceController::class, 'show']);
});
```

No route registration under `routes/console.php`.

---

## 10. Scheduled Commands

**None.** Invoice generation is event-driven (payment verification), not
time-driven. No new Artisan command, no scheduler entry — consistent with the
hold/cleaning/no-show sweeps being the only scheduled machinery, and invoices
having no timed lifecycle (no expiry, no cleanup; immutable records).

---

## 11. Concurrency / Transactions

- Generation runs **inside the existing single `DB::transaction` in
  `PaymentService::verify()`** — one transaction already covers payment
  status, booking confirmation, room reservation and the audit row; STEP 16
  adds the invoice row to that same all-or-nothing unit. If any step throws,
  the invoice is not persisted (tested).
- The payment and booking rows are re-read with `lockForUpdate()` and the
  payment status re-checked under the lock **before** generation (existing
  behavior, unchanged). Two concurrent verifies on one payment serialize;
  the loser re-reads a non-`receipt_uploaded` status and gets its 409 — exactly
  one invoice can ever exist (double-verify test asserts this).
- Invoice-number uniqueness: bounded retry + `unique` facet (BR-4). A rare
  collision inside the transaction aborts and rolls back cleanly.
- `InvoiceService` starts **no** transaction of its own — callers own the
  boundary (same ownership convention as
  `confirmWithinTransaction`/`refundBooking`).
- No cross-payment locking is introduced; no new deadlock ordering.

---

## 12. Idempotency / Duplicate Behavior

- Verify's existing guard (`status === 'receipt_uploaded'`) means `generateForPayment`
  is only reachable once per payment; a second verify → 409 before any write.
- `invoices.payment_id` UNIQUE is the DB-level backstop (BR-2).
- Invoices are never *updated* — re-issuance doesn't exist. No partial-write risk
  (transactional, BR-5).
- Refund of a verified payment is a *different* transition that never re-enters
  generation (BR-6).

---

## 13. Test Strategy — Exact Scenarios

New `tests/Feature/Invoice/` — three files, ~28–32 tests, reusing existing
helpers (`makeBookingCustomer`, `makeBookingRoom`, `makeHoldingBooking`,
`makePaymentFor`, `loginAsEmployee`, `loginAsCustomer`) and, where useful, the
additive helper from §8.

**`InvoiceGenerationTest.php` (~10)**
1. Verify a receipted payment → invoice created: `INV-`+8, `total_amount` =
   `payments.amount` = `bookings.total_amount`, `issued_at` set; payment
   `verified`, booking `confirmed` — all in the one verify response.
2. Multi-room + fractional snapshot (`99.99`/night): integer-cent accumulation,
   `decimal:2` on the invoice total (STEP 09 §5 convention).
3. No invoice for `pending` / `receipt_uploaded` / `rejected` payments; invoice
   exists only after the verify transition.
4. Double-verify → 409; exactly **one** invoice row (UNIQUE backstop).
5. Atomic rollback: force a failure inside the verify transaction (e.g. a room
   whose status forbids `available→reserved`) → booking **not** confirmed,
   payment **not** verified, invoice **absent** (all-or-nothing).
6. Client cannot inject: a request body carrying `invoice_number`/`total_amount`/
   `issued_at` keys is ignored; no route accepts invoice writes.
7. Refund of the verified payment leaves the invoice **and** its totals intact;
   payment shows `refunded`, invoice still listed.
8. Hold-expiry cancellation, no-show (STEP 14), staff cancel, and customer
   self-cancel of *unpaid* bookings → no invoice anywhere.
9. A staff-confirmed (never-paid) booking that runs check-in→check-out → no
   invoice (BR-8).
10. `Schema::hasTable('invoices')` + UNIQUE(`payment_id`) + UNIQUE
    (`invoice_number`) + no `deleted_at` column (additive-style addition to
    the invoice test file, or a new schema test — not touching
    `DatabaseSchemaTest`).

**`StaffInvoiceTest.php` (~10–11)**
11. Index returns all invoices, paginated, ordered by `issued_at` desc.
12. Search by `booking_number`, customer `name`, and customer `email`.
13. Show any invoice — admin, manager, **and receptionist** each 200 (read
    tiering).
14. `?status=` is **not** accepted (no invoice status) → 422 if sent
    (FilterRequest shape).
15. Guest → 401; customer on the staff route → 403.
16. Response shape: `invoice_number`, `total_amount`, `issued_at`, embedded
    `payment`, `booking`, `customer` (no `assertExactJson` — assert keys).
17. Refunded payment's invoice still visible to staff (BR-6).

**`CustomerInvoiceTest.php` (~8)**
18. Index → own invoices only (a booking for a second customer is excluded).
19. Show own → 200; another customer's invoice → **404** (scoped `findOrFail`).
20. Customer on staff route → 403 (actor middleware).
21. Invoice visible for own verified payment before and after a staff refund
    (invoice is the historical document).

**Baseline to protect:** 395 tests / 1082 assertions must stay green with
**zero edits** to existing tests; expected finish ≈ **423–427 tests /
1150+ assertions**.

---

## 14. Regression Considerations (STEP 01–15)

| Area | Protecting tests (unchanged, must stay green) | Why it stays green |
|---|---|---|
| Payment verify | `PaymentVerifyBookingTest`, `StaffPaymentReviewTest`, `ReceiptUploadTest` | `verify()` pre-checks, locks, transaction, returned `fresh(['booking','verifiedBy'])` and the message `'Payment verified.'` are **unchanged**; invoice generation is appended inside the transaction and serializes nothing new into the response (invoice not eager-loaded). |
| Payment create / amounts | `PaymentCreateTest`, `BookingPricingTest` | `create()`, `booking.total_amount`, `payments.amount` untouched. |
| Refund | `RefundTest` (18) | With D-2 recommended, `PaymentService::refund`/`refundBooking` are **not modified at all**. An invoice surviving a refund adds a row, does not change any asserted field. |
| No-show / hold-expiry / late-fee | `NoShowSweepTest`, `HoldExpiryTest`, `LateCancellationFeeTest` | These paths never call verify → never generate; zero code touched. |
| Schema | `DatabaseSchemaTest` | Only positive per-table checks; new table is additive (§7). |
| Auth / actors / ownership | `AuthorizationTest`, `AuthSecurityTest`, `CustomerAuthTest`, `StaffAuthTest` | No middleware, gate, policy (other than the new `InvoicePolicy`) or existing route touched. |
| Response envelopes | All of the above | `ApiResponse` untouched; existing controller responses byte-identical (D-4). |

**Hard invariants STEP 16 must respect (else regression):**
1. `PaymentService::verify`, `create`, `uploadReceipt`, `reject`, `refund` keep
   every existing pre-check, lock and message; only an appended
   `generateForPayment($lockedPayment)` call inside the verify transaction.
2. `BookingService` (all 20+ public/private methods), `RoomService`,
   `RoomTypeService`, `RoomImageService` — untouched.
3. No change to `payments.amount`, `bookings.total_amount`, the late-fee
   snapshot formula, or any request rule that exists today.
4. No new middleware/gate/role semantics; actor/role middleware untouched.
5. Existing payment/booking responses do not gain the `invoice` relation in
   their JSON (D-4) — the controller changes are confined to the two new
   invoice controllers.
6. Migration purely additive; run only `php artisan migrate --force` on the
   XAMPP MySQL dev DB (already `Ran` 6 batches today).

---

## 15. Interaction with Every Other Module (verified non-regression)

| Module | Interaction |
|---|---|
| Bookings (STEP 09) | Unchanged. Invoices are per-verified-payment; a booking without payment has none (BR-8). |
| Payments (STEP 10) | Verify is the single generate trigger (BR-1). `payments.amount` invariant preserved. |
| Refunds (STEP 11) | Invoice is historical; refund proceeds unchanged (BR-6 / D-2). |
| Late-cancel fee (STEP 15) | Fee is a booking-row record; does not appear on or affect invoices. Unchanged. |
| No-show / cleaning / holds (STEP 13/14) | No interaction — none of those paths touch payments. |
| Auth/RBAC (STEP 04/05) | New `InvoicePolicy` follows the established two-actor pattern; no Gate/role changes. |

---

## 16. Explicit Out-of-Scope (final checklist)

- ❌ No taxes/TVA, no discounts/pricing tiers, no fee settlement.
- ❌ No PDF/file generation or emailing; no new Composer dependency.
- ❌ No invoice editing, deletion, voiding, reversal, or status column
  (unless D-2(b) approved).
- ❌ No invoices for unpaid / pending / rejected / hold-expired / no-show /
  cancelled-without-payment bookings.
- ❌ No change to the refund engine, pricing, payment amounts, or the late-fee
  snapshot formula.
- ❌ No change to existing routes, requests, policies, response shapes or
  messages.
- ❌ No scheduled commands, no notifications, no dashboard/reporting/UI, no
  idempotency keys.
- ❌ No destructive schema changes — one new additive table only; no column/
  enum/index changes; no `deleted_at`.

---

## 17. Files to Create / Modify (summary)

| File | Change | New? |
|---|---|---|
| `docs/STEP-16-IMPLEMENTATION-PLAN.md` | this plan | **new** |
| `database/migrations/2025_02_10_000001_create_invoices_table.php` | §7 | **new** |
| `app/Models/Invoice.php` | §8 | **new** |
| `app/Services/InvoiceService.php` | `generateForPayment()` + number generator | **new** |
| `app/Policies/InvoicePolicy.php` | two-actor read policy | **new** |
| `app/Http/Controllers/Api/V1/Staff/InvoiceController.php` | index/show | **new** |
| `app/Http/Controllers/Api/V1/Customer/InvoiceController.php` | index/show | **new** |
| `app/Http/Requests/Invoice/FilterRequest.php` | search/per_page | **new** |
| `tests/Feature/Invoice/InvoiceGenerationTest.php` | ~10 tests | **new** |
| `tests/Feature/Invoice/StaffInvoiceTest.php` | ~10–11 tests | **new** |
| `tests/Feature/Invoice/CustomerInvoiceTest.php` | ~8 tests | **new** |
| `docs/STEP-16-IMPLEMENTATION-REPORT.md` | written after implementation | **new** |
| `app/Services/PaymentService.php` | inject `InvoiceService`; one appended call inside `verify()` | modify (additive) |
| `app/Models/Payment.php` | `invoice(): hasOne` relation | modify (additive) |
| `tests/Pest.php` | additive invoice helper | modify (additive) |
| `routes/api.php` | two read-only route groups (§9) | modify (additive) |

**Not touched:** `BookingService`, `RoomService`, `RoomTypeService`,
`RoomImageService`, every existing controller/policy/request, `config/*`,
`.env`/`.env.example`, `routes/console.php`, every scheduled command.

---

## A. Why a New Table and Not a Booking/Payment Column

Verified column-by-column (mirrors STEP 15 §7):
- `bookings.total_amount` — the stay price; 1:1 with `payments.amount`; invoices
  must not overload it (pricing tests pin it).
- `payments` — the *payment record* (`amount`, `method`, `status`); adding
  `invoice_number`/`issued_at` there would blur the payment state machine
  (payment rows have a status lifecycle; an invoice is an immutable document).
- `bookings.cancellation_reason` / `late_fee_*` — audit **events**, not billing
  documents.
- There is no line-item/ledger table (STEP 03 deliberately excluded one).

⇒ a `invoices` table is genuinely required (BR-2's 1:1 UNIQUE also makes the
"exactly one invoice" invariant structural, not conventional).

---

## B. Unresolved Business Decisions — MUST BE APPROVED BEFORE IMPLEMENTATION

Per the project discipline (see STEP 14 §3 / STEP 15 §27), nothing here is
decided by assumption. Every decision below is flagged; the recommended value is
first.

| # | Decision | Recommended | Alternatives |
|---|---|---|---|
| **D-S0** | **STEP 16 topic** | **Invoices** (this plan) — the recurring money-domain deferral with a named "atomic generation" milestone | (b) Pricing tiers/discounts (first-listed deferral, highest regression risk); (c) In-app notifications; (d) staff payment creation/payment edit-delete |
| D-1 | Invoice generation trigger | Atomically inside **payment verify** — invoice exists iff a payment was verified | Generate at check-out for every stay (requires new money rules for unpaid stays) |
| D-2 | Invoice lifecycle | **Immutable record, no status column**; a later refund leaves the invoice in place (refund engine untouched) | `issued/voided` status flipped atomically by refund (requires modifying `PaymentService::refund`) |
| D-3 | Line items | **No invoice_items table**; itemization derives read-only from the existing `booking_rooms.price_per_night` snapshots | Separate `invoice_items` snapshot table |
| D-4 | Response surface | **Dedicated invoice endpoints only**; existing payment/booking response shapes unchanged (zero regression risk) | Embed `invoice` relation into payment show/verify responses (forces per-test JSON verification) |
| D-5 | Customer visibility | Customers can list/view their **own** invoices | Staff-only invoice surface |
| D-6 | Invoice numbering | `INV-` + 8 uppercase random (booking-number pattern) | Sequential `INV-000001` (needs a counter/locking — new machinery) |

**Approve D-S0 (topic) and D-1…D-6, or specify overrides. No migration, no
code, no tests, no routes, no behavior changes until approved.** Once approved,
implementation follows the sections above exactly (B-1 triggers §17's modify
list; D-2(b) additionally extends `PaymentService::refund`).

---

## 18. Wait for Approval

Plan only. No implementation until approval.