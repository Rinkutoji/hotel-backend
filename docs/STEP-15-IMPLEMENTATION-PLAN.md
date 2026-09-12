# STEP 15 — Late-Cancellation Fees Implementation Plan (review before implementation below)

> **Decision points are flagged, not resolved by assumption.** The repository
> documents that late-cancel fees exist as an *out-of-scope / deferred* item,
> but it does **not** document the fee window, the fee amount/formula, or how
> the fee interacts with payment/refund. Per the STEP 15 scope instruction,
> those are explicit **unresolved product decisions** — each is presented with
> a *recommended* value and the alternatives, and must be **approved in §27**
> before any implementation. Nothing here is implemented by assumption.

## 0. Pre-Coding Review — Read the Actual Repository First

**Verified current state (STEP 01–14, full suite green: 371 tests / 958
assertions / 0 failures).** Everything below is checked against the actual
code, not assumptions.

### 0.1 What the repository actually says about late-cancellation fees

The only mentions of late-cancel fees in the entire `docs/` tree are
out-of-scope / deferral lines. There is **no definition of the window, the
amount, or the rule anywhere**:

| Location | What it says |
|---|---|
| `docs/STEP-09-BOOKING-PLAN.md:47-50` | STEP 09 out-list: "Pricing tiers / discounts, **late-cancel fees**, refunds" — all deferred, no definitions |
| `docs/STEP-12-IMPLEMENTATION-PLAN.md:83` | "Pricing tiers / discounts / late-cancel fees — out of scope." |
| `docs/STEP-12-IMPLEMENTATION-PLAN.md:436` | "❌ Pricing tiers / discounts / late-cancel fees." |
| `docs/STEP-14-PLAN.md:118` | "No late-cancel fees (STEP 09 out-list)." |
| `docs/STEP-14-IMPLEMENTATION-REPORT.md:174` | "...no auto-refund... no fees..." — the STEP 14 out-list |

Consequences of that gap (verified, this is the crux of STEP 15):

1. The **late-cancellation window** is undefined (hours? days? relative to
   check-in?).
2. The **fee formula** is undefined (percentage? fixed amount? one night?).
3. The **payment/refund interaction** is undefined. The existing refund engine
   refunds the **full** `payments.amount` (= `bookings.total_amount`) and only
   for a `verified` payment; partial refunds are explicitly out of scope
   (STEP 11 plan) and were re-confirmed out of scope for STEP 15 in the scope
   instruction.
4. **No schema field represents a fee** — see §7.

These are the **unresolved business decisions** §3–§5 and §14 pin down for
approval, using only documented precedent for the *mechanics*.

### 0.2 What exists today (reused, not re-built)

**Cancellation surface (STEP 09 + STEP 11 + STEP 14), all in
`app/Services/BookingService.php`:**

| Path | Statuses accepted | Notes |
|---|---|---|
| `cancelByCustomer()` (`:153`) | `pending_payment` only (not expired) | `applyCancellation(booking, reason ?? 'Cancelled by customer')` |
| `cancelByStaff()` (`:226`) | `pending_payment`, `confirmed` | requires `reason`; `applyCancellation` |
| `cancelExpiredHold()` (`:178`) / `releaseExpiredHolds()` (`:291`) | `pending_payment` expired | reason `'Payment deadline passed'` — no-fee path |
| `refundBooking()` (`:467`, STEP 11) | `confirmed`, `pending_payment` | sets `cancellation_reason = 'Refunded by staff: {reason}'`, releases rooms — **this is the only money-moving cancellation** |
| `applyNoShow()` (`:355`, STEP 14) | `confirmed` → `no_show` | terminal; not a cancellation |

**Shared helpers reused as-is:**
- `applyCancellation(Booking, string)` (`:421`) — sets `status=cancelled`,
  `cancelled_at=now()`, `cancellation_reason`, saves, then
  `releaseReservedRooms($booking, 'Booking cancelled')`. This is the audit +
  room-release convention STEP 15 must keep byte-identical.
- `releaseReservedRooms(Booking, string)` (`:441`) — releases only
  `reserved` rooms via `transitionRoom(room, 'available', reason)`,
  `changed_by = null`, writing `room_status_logs` rows.
- `transitionRoom(?Room, string, string)` (`:383`) — validates against
  `RoomService::VALID_TRANSITIONS`, writes audit row.
- `formatCents(int)` (`:477`) — integer-cents → `decimal:2` string via
  `sprintf` (the STEP 09 §5 drift-free pricing convention).
- Money is **never** read from request input; `UnitBooking` fields like
  `total_amount` are computed server-side (§5/§10 of STEP 09).

**Payment / refund invariant (STEP 10/11) — `app/Services/PaymentService.php`:**
- 1:1 booking↔payment, `payments.amount = bookings.total_amount` (`create()`
  `:35`), status enum `[pending, receipt_uploaded, verified, rejected, refunded]`.
- `refund()` (`:193`) requires `verified`, booking `confirmed`/`pending_payment`,
  refunds the **full** amount atomically (payment + booking cancel + room
  release), admin/manager only. `refunded` is terminal.
- No partial refunds exist anywhere (STEP 11 explicitly out of scope).

**Authz (STEP 09/11/12):**
- `BookingPolicy::cancel` (`app/Policies/BookingPolicy.php:67`) — Customer
  (own booking) **or** Employee `admin/manager/receptionist`.
- `PaymentPolicy::refund` (`:89`) — Employee via `process-refund` Gate
  (admin/manager), enforced by route `role:admin,manager` + policy re-check.
- Route `/api/v1/staff/bookings/{booking}/cancel` = `auth:sanctum, actor:staff`
  + `role:admin,manager,receptionist` (`routes/api.php:185-186`).
- Customer cancel route = `auth:sanctum, actor:customer`, scoped to own
  bookings (`routes/api.php:76`).

**Schema (`bookings`, `2025_02_01_000007_create_bookings_table.php`):**
`booking_number, customer_id, check_in_date, check_out_date, status,
total_amount, payment_deadline_at, cancelled_at, cancellation_reason, no_show_at
(STEP 14), timestamps`. No fee column; no invoice/ledger table. `bookings` has
no `deleted_at` (lifecycle = status transitions, `DatabaseSchemaTest` pins this).

**Config (`config/booking.php`):**
`hold_hours` (2), `cleaning_buffer_minutes` (15), `max_stay_nights` (30),
`max_rooms_per_booking` (5). All read via `config('booking.*')` — env() only in
this file per STEP 02 convention. STEP 15 adds two keys here.

### 0.3 Gaps STEP 15 closes

1. A `confirmed` booking cancelled "late" currently loses the hotel nothing:
   the whole `total_amount` may already be `verified`, yet cancellation is
   free and the refund engine returns 100% of it.
2. No record of "a cancellation incurred a charge" exists anywhere (no fee
   column, no fee table) — the cancelled booking is indistinguishable from a
   timely, fee-free cancellation.

---

## 1. Exact Objective and Scope

**Objective:** record a server-computed **late-cancellation fee** on a
`confirmed` booking at the moment a staff member cancels it *inside* the
late-cancellation window. The fee is a booking-level **record** (amount +
timestamp), made visible in staff/customer booking responses, mirroring how
cancellations (`cancelled_at/cancellation_reason`) and no-show (`no_show_at`)
are already recorded. It is **not** auto-collected, **not** deducted from any
payment, and **not** wired to a gateway.

**In (working MVP):**
- Two new nullable bookings columns: `late_fee_amount` (decimal 10,2),
  `late_fee_applied_at` (timestamp) — the only schema change (additive).
- Staff cancellation of a `confirmed` booking inside the window records the
  fee atomically with the existing cancellation (status, `cancelled_at`,
  `cancellation_reason`, room release — all unchanged).
- Fee is **always server-computed** from the existing
  `booking_rooms.price_per_night` snapshot (STEP 09 §5) + config; never from
  request input.
- Fee is visible: `GET /staff/bookings/{id}`, `GET /staff/bookings` and the
  customer's own booking responses carry the two fields (null when no fee).
- Fee is recorded through the **existing** `cancelByStaff()` method — no new
  endpoint, no new controller/policy/FormRequest, no new service.
- Two new `config('booking.*')` keys (window hours, fee nights) — values
  **approved** in §27.

**Out (deliberately, kept minimal for the university project):**
- No real money movement, no payment gateway, no payment-row changes
  (`payments.amount` stays = `total_amount`; 1:1 rule intact).
- No partial refunds, no withholding from a refund, no auto-refund of the fee.
- No change to the refund engine (`PaymentService::refund`,
  `BookingService::refundBooking`) — it neither triggers nor clears the fee
  (§14).
- No change to customer self-cancel (stays `pending_payment`-only; a customer
  can never trigger a fee, because `confirmed` self-cancel stays 409).
- No fee on hold-expiry cancellation, on no-show, or on the refund path.
- No invoices/ledger/reports/notifications/UI.
- No change to no-show automation (STEP 14), cleaning/turnover (STEP 13), or
  any payment/verify/reject/refund status flow.

---

## 2. What the Repository Already Says (evidence)

| Topic | Finding | Source |
|---|---|---|
| Late-cancel fees | Exists only as deferred/out-of-scope; zero definition | STEP-09:49, STEP-12:83/:436, STEP-14:118 |
| Cancellation timing | `confirmed` cancellable "pre check-in"; check-in allowed on/after `check_in_date` | STEP-09-BOOKING-PLAN.md §2/§6 |
| Fees | None anywhere — no amount, no window, no formula | repo-wide `docs/` grep |
| Refunds | Full-amount, verified-payment, admin/manager, staff-initiated only | STEP-11-IMPLEMENTATION-PLAN.md §1–§5; `PaymentService::refund` |
| Lifecycle | `pending_payment → confirmed → checked_in → checked_out`; terminal `cancelled`, `no_show`; `no_show` auto after check-in day passes (STEP 14) | `BookingService`; STEP-03 §6; STEP-14 |
| Booking pricing | Server-side, snapshot `booking_rooms.price_per_night`, integer cents | STEP-09 §5; `BookingService::create` |

**Conclusion:** the *architecture and mechanism* are fully precedented (additive
nullable booking columns + a private service calculation + existing cancel
endpoint). The *business numbers* (window, fee amount, refund interaction) are
**not documented** and are §27 approval items.

---

## 3. Exact Definition of "Late Cancellation" (approval item)

**Recommended rule (proposed, to be approved):**
> A cancellation is **late** when a staff member cancels a **`confirmed`**
> booking at a moment `now() >= (check_in_date at start of day) −
> config('booking.late_cancel_window_hours')`.

- Only `confirmed` bookings are eligible. `pending_payment` bookings are never
  late for this purpose: they hold no confirmed room (nothing reserved), the
  customer may self-cancel them freely, and the hold-expiry sweep already
  cancels them with the documented "Payment deadline passed" no-fee reason.
- `checked_in` / `checked_out` / `cancelled` / `no_show` bookings are not
  cancellable at all (existing 409 guards), therefore never feeable.
- The window is measured from the **start of the check-in day** (bookings are
  date-granular per STEP 03 §4), so with a 24 h window a booking becomes
  "late-cancellable" at 00:00 of the day before check-in.

**Why this shape:** it is the only place a fee can attach without touching the
terminology already locked by STEP 09 (staff may cancel `confirmed` only
pre-check-in). It reuses the check-in-date semantics the no-show sweep already
depends on (`check_in_date` boundaries) and stays independent of it: within the
window the sweep has not yet fired (or has fired and the day passed — then the
booking is `no_show`, not cancellable).

**Alternative rules (also approvable, provided explicitly so nothing is
assumed):**
- **A.** Cancel-at-or-after-check-in-day only (`now()` on/after `check_in_date`)
  — the fee then applies only to same-day / day-of-arrival cancellations.
- **B.** N calendar days before check-in (`now() >= check_in_date − N days`).
- **C.** Hours before check-in (recommended; most configurable and easiest to
  test at exact boundaries).

The plan below is written against **C**; A/B are drop-in rule changes confined
to one private `isLateCancellation(Booking)` method, so the rest of the design
holds regardless of your choice.

---

## 4. Exact Cancellation Window / Threshold (approval item)

- **Key:** `config('booking.late_cancel_window_hours')`
  = `(int) env('LATE_CANCEL_WINDOW_HOURS', 24)`.
- **Recommended default: `24`** hours before the check-in day's start.
- **Boundary semantics (pinned by tests, mirroring STEP 13's ±1 s method):**
  `now()` **equal to** the cutoff is **late** (`>=`); one second before is
  **not** late.

> ⚠️ **Unresolved product decision — not assumed.** 24 h is a *recommendation
> for approval*. If you prefer 48/72 h or "same-day only", say so at approval;
> only `config/booking.php` + `.env`/`.env.example` change.

---

## 5. Exact Fee Calculation Rule (approval item)

- **Key:** `config('booking.late_cancel_fee_nights')`
  = `(int) env('LATE_CANCEL_FEE_NIGHTS', 1)`.
- **Recommended formula (server-side, integer-cents, snapshot-based):**
  `fee_cents = late_cancel_fee_nights × Σ over bookingRooms of
  round(price_per_night × 100)`, persisted via `formatCents()` as `decimal:2`.

Rationale: reuses the **exact** STEP 09 §5 price source already snapshotted on
`booking_rooms.price_per_night` at booking time (immune to later `base_price`
changes — same invariant already tested by `BookingPricingTest`). A 1-night fee
is the most common hotel convention and, on a 1-1-night stay, naturally caps at
the full stay value. Multi-room bookings fee one night × every booked room.

> ⚠️ **Unresolved product decision — not assumed.** The *formula* (one night) is
> a *recommendation for approval*. Alternatives if approved instead: a fixed
> percentage of `bookings.total_amount` (e.g., 50%), or a fixed currency amount.
> Whichever is chosen lives only in the private `computeLateCancellationFee()`
> method + the two config keys — the rest of this design is formula-agnostic.

**Invariants enforced by construction:**
- Client-sent `late_fee_amount` / `late_fee_applied_at` are **never accepted**:
  the two fields are **not** added to any request rule, `CancelRequest` keeps
  only `reason`, and no create/update path mass-assigns them from user input
  (same pattern as `cancelled_at`/`no_show_at`).
- Fee is computed **inside** the cancellation transaction from the booking row
  + config + clock — pure server-side.

---

## 6. Where the Fee Is Stored

Two additive, nullable columns on `bookings` (symmetric with the cancellation
and no-show record-keeping the table already uses):

| Column | Type | Meaning |
|---|---|---|
| `late_fee_amount` | `decimal(10,2)` nullable | server-computed fee, `null` = no fee applied |
| `late_fee_applied_at` | `timestamp` nullable | the moment the fee was recorded |

`app/Models/Booking.php`: add both to `$fillable` (service-only writes, exactly
as `cancelled_at`/`no_show_at` already are) and casts `'decimal:2'` /
`'datetime'`. No request accepts either key, so fillable status cannot be
exploited by the client — verified by the existing "client-sent money fields
ignored" test convention (STEP 09 §5 / STEP 11).

---

## 7. Whether an Existing Field Could Represent the Fee

**No.** Verified column-by-column:
- `bookings.total_amount` — the *full stay* price, must stay untouched (booking
  pricing tests pin it; payment `amount` = it).
- `bookings.cancellation_reason` — a **string**; the fee is a **decimal amount
  + a timestamp**. Overloading a reason string with a fee would corrupt the
  existing audit values (`'Cancelled by staff'`, `'Refunded by staff: …'`,
  `'Payment deadline passed'`) that STEP 09/11 tests assert verbatim.
- `payments.amount` — 1:1 with `total_amount`, and payments are a separate
  lifecycle (`create()` locks amount to the booking total); mutating it to
  carry a fee would break STEP 10's "amount = booking total" invariant and the
  payment status machine.
- There is no invoices/ledger/charges table to write a fee into.

⇒ A migration is **genuinely required** (§8).

---

## 8. Database / Schema Change (genuinely required — the single one)

**New migration:** `database/migrations/2025_02_09_000001_add_late_fee_to_bookings_table.php`

```php
Schema::table('bookings', function (Blueprint $table) {
    $table->decimal('late_fee_amount', 10, 2)->nullable()->after('no_show_at');
    $table->timestamp('late_fee_applied_at')->nullable()->after('late_fee_amount');
});
```

Why safe (`DatabaseSchemaTest` stays green with zero edits — verified against
`tests/Feature/DatabaseSchemaTest.php`):
- It checks table existence, bookings *required* fields, and that bookings have
  **no `deleted_at`** — it does **not** enumerate the bookings column set
  (same verification STEP 14 relied on).
- Both columns are nullable and default null → existing rows unaffected; the
  migration is purely additive.
- Money stays `decimal(10,2)` (STEP 03 §4 convention); timestamps stay
  `timestamp`.

**No other schema changes.** No new tables, no enum changes, no indexes (the
fields are read inside the existing booking row; no hot-path query filters on
them).

---

## 9. Customer Cancellation Behavior (unchanged)

- **Customer self-cancel is NOT extended.** It remains `pending_payment`-only
  (`BookingService::cancelByCustomer` `:153`), and cancelling a `confirmed`
  booking by its owner stays **409** (`CustomerBookingTest` pins it).
- Because a customer can only self-cancel a `pending_payment` booking, **a
  customer can never trigger a late fee** by construction. The "ownership"
  rule that a customer cannot cancel someone else's booking (scoped `findOrFail`
  → 404, `BookingPolicy::cancel`) is untouched.
- The owning customer's booking list/show responses gain the two fee fields
  (null where no fee) — the only perceivable change on the customer surface,
  and it is data-only.

> **Decision made in this plan (flagging, not assuming):** "let customers
> cancel a confirmed booking and pay the fee" is **excluded** — it would be a
> new customer surface with no precedent in STEP 09–14 and would need a new
> payment/collection story. Approve in §27 if you want it; the plan otherwise
> keeps STEP 09's guard.

---

## 10. Staff Cancellation Behavior (the fee surfaces here)

`cancelByStaff(Booking, string $reason)` (`BookingService.php:226`) gains the
fee step, **inside its existing `DB::transaction`**:

1. Guard unchanged: status must be `pending_payment` or `confirmed`, else 409.
2. `applyCancellation($booking, $reason)` — **unchanged** (status, `cancelled_at`,
   `cancellation_reason`, room release, audit rows — byte-identical).
3. **If `status was 'confirmed'` AND `isLateCancellation($booking)`** → compute
   the fee (§5) and write `late_fee_amount` + `late_fee_applied_at = now()` on
   the booking row in the same transaction.
4. Return the booking as today.

`pending_payment` staff-cancellations, hold-expiry cancellations
(`'Payment deadline passed'`), and no-show automation never touch the fee —
each is excluded by the `confirmed` + window guard.

---

## 11. Admin / Manager / Receptionist Authorization (unchanged)

- The fee rides the **existing** staff cancel endpoint `POST
  /api/v1/staff/bookings/{booking}/cancel`, already gated by
  `auth:sanctum, actor:staff` + `role:admin,manager,receptionist`
  (`routes/api.php:185`), re-checked by `BookingPolicy::cancel`.
- **No role changes.** Admin, manager and receptionist may cancel (as today)
  and thereby trigger the fee; refund authority (admin/manager only, via
  `process-refund`) is **not** changed and does not interact with the fee (§14).
- Guests → 401; customers on the staff route → 403 (actor middleware).

---

## 12. Customer Ownership Enforcement (unchanged)

- The staff route is staff-only; the customer route scope
  (`Booking::where('customer_id', $user->id)->findOrFail`) is untouched.
- `BookingPolicy::cancel` ownership branch untouched.
- Because customers cannot cancel `confirmed` bookings (§9), the fee cannot be
  triggered by a customer at all; ownership leaks remain impossible (both
  existing enforcement layers unchanged).

---

## 13. Interaction with Every Booking Status

| Status | Cancellable? | Fee? | Why |
|---|---|---|---|
| `pending_payment` | staff: yes; customer: yes (not expired) | **no** | Nothing reserved/verified yet; hold sweep is the documented no-fee cancel |
| `confirmed` | staff: yes | **yes, iff inside window** (STEP 15) | Rooms reserved; payment may be verified |
| `checked_in` | **no (409)** | n/a | Guest is staying |
| `checked_out` | **no (409)** | n/a | Stay complete |
| `cancelled` | **no (409)** | n/a | Terminal; no double-action |
| `no_show` | **no (409)** | n/a | Terminal (STEP 14); sweep's fee-less path unchanged |
| refunded payment (booking `confirmed`/`pending_payment` → `cancelled`) | via refund only | **never** | §14 — refund path is untouched |

Every "no" is enforced by the already-tested 409 guards
(`BookingLifecycleTest`, `StaffBookingTest`, `NoShowSweepTest`,
`RefundTest`); STEP 15 adds **no** new guard.

---

## 14. Interaction with the Existing Refund Engine (decision to approve)

Current behavior (STEP 11, untouched): staff refunds a `verified` payment →
payment `refunded`, booking cancelled (`Refunded by staff: {reason}`), reserved
rooms released, **full amount returned**.

**Proposed rule (flagging for approval):**
> The refund path neither **triggers** nor **clears** the late fee.

- **Refund never triggers a fee.** A booking cancelled via
  `PaymentService::refund` / `BookingService::refundBooking`
  (`BookingService.php:467`) gets **no** `late_fee_amount` — refunds already
  have their own money rule (full refund), and attaching a fee there would be
  partial-refund logic (explicitly out of scope).
- **Refund never clears a fee.** A `staff_cancelled` booking keeps its recorded
  fee. Since a booking is terminal after either path, no booking can ever carry
  *both* a refund cancellation and a staff-cancel fee — the two paths are
  mutually exclusive on any given row (a refund rejects `cancelled` bookings;
  `cancelByStaff` rejects `refunded`-cancelled bookings). No contradiction
  arises.
- Consequence, stated plainly: **the fee is a recorded amount; its eventual
  collection/settlement is out of scope.** `PaymentService::refund` continues
  to return 100% of `payments.amount`.

> This is the single most important §27 approval item. If you want refunds to
> deduct the fee, that requires partial refunds — which the STEP 11 scope
> explicitly excluded and the STEP 15 scope instruction re-excludes. If you
> want refunds to waive a recorded fee (zero it), approve that as a separate
> rule: one extra `if sum` in `refundBooking()` clearing the two columns. The
> plan's recommended default is **no change to refunds**.

---

## 15. Room Release Behavior (unchanged)

Same as today, via the untouched `applyCancellation()`:
- Every `reserved` room → `available` through `releaseReservedRooms($booking,
  'Booking cancelled')` (`:441`/`:428`), writing
  `{from_status: reserved, to_status: available, changed_by: null,
  reason: 'Booking cancelled'}` audit rows.
- The fee is written **after** `applyCancellation()` in the same transaction;
  room release, audit rows, cancellation fields and the fee all commit or roll
  back together.
- `changed_by` remains null (booking-driven transition convention), even though
  the acting staff member is known — the fee does not alter the existing audit
  convention, matching how all other booking-driven room moves record no actor.

---

## 16. Audit Logging

No new log table — consistent with the rest of the project (there is **no**
`booking_status_logs` table; booking events live on the booking row,
`STEP-14-PLAN.md §0.3`). For a late-fee cancellation the trail is:

| Artifact | Content |
|---|---|
| `bookings.status` | `cancelled` (unchanged) |
| `bookings.cancelled_at` / `cancellation_reason` | existing fields, **values unchanged** (reason string verbatim from the request, or defaulted) |
| `bookings.late_fee_amount` / `late_fee_applied_at` | the fee + when it was recorded (the new audit record) |
| `room_status_logs` | one row per released room, reason `'Booking cancelled'` (unchanged) |

The fee fields themselves are the audit record, exactly as `no_show_at` is for
STEP 14. No `updated_at` additions, no new actor column.

---

## 17. Transaction Boundaries

- The fee is computed and written **inside the existing
  `DB::transaction` in `cancelByStaff()`** — one transaction covers: status,
  cancellation fields, fee fields, room releases, room audit rows. All-or-nothing
  (if a room transition ever threw, the fee would not persist — tested).
- `applyCancellation`, `releaseReservedRooms`, `transitionRoom` are already the
  shared, transaction-owning helpers used by every cancel/refund/sweep path;
  STEP 15 adds calls, not new boundaries.
- No new transaction is started anywhere; the hold sweep, no-show sweep, and
  refund keep their own (untouched).

---

## 18. Concurrency / Locking Requirements

- **No new lock is strictly required.** The fee is derived from the booking row
  itself + `now()` + config — there is no second source of truth to race. Two
  concurrent staff-cancels on one booking serialize on the booking row's
  existing single-writer update; the loser's fresh load sees `cancelled` and
  409s (§19).
- **Recommended hardening (matching STEP 11's `lockForUpdate` re-read
  pattern):** re-read the booking row with `lockForUpdate()` inside the
  transaction and re-check `status` before deciding the fee, so the fee decision
  always uses the authoritative committed state. This mirrors
  `PaymentService::refund` (§5 step 3) and the STEP 14/13 sweeps' re-check
  philosophy. sqlite ignores `FOR UPDATE` (documented STEP 09 caveat); the
  functional behavior is covered by sequential tests.
- Cancellations happen in one transaction per booking; no cross-booking lock
  ordering is introduced.

---

## 19. Duplicate / Repeated Cancellation Behavior

- A second cancellation of an already-`cancelled` booking → **409** (the
  `cancellable` guard re-reads a fresh model). Exactly one fee record can ever
  exist because a booking is feeable only on the cancellation that flips
  `confirmed → cancelled`, which can happen at most once.
- The no-show sweep is a different transition (`confirmed → no_show`,
  STEP 14) and cannot reach a `cancelled` booking; no interplay.
- Idempotency of the fee write: a single `if feeable → set columns` inside the
  one winning transaction. No partial writes possible (transactional).

---

## 20. API Endpoint Changes

**No new endpoints.**

| Action | Endpoint (existing) | Change |
|---|---|---|
| Cancel a `confirmed` booking (fee may record) | `POST /api/v1/staff/bookings/{booking}/cancel` | server-side behavior only; response data gains `late_fee_amount` / `late_fee_applied_at` |
| List/show bookings (staff & customer) | existing booking show/index | the two fields serialize (null unless set) — no query/route change |
| Cancel a `pending_payment` booking | existing customer/staff cancel | unchanged, no fee |
| Refund | `POST /api/v1/staff/payments/{payment}/refund` | untouched (§14) |

The staff cancel response message stays `'Booking cancelled.'` and the customer
message stays unchanged — existing assertions on them stay green.

---

## 21. Request Validation

- **None added.** `CancelRequest` keeps its only rule: `reason` nullable string
  `max:255`.
- Fee keys are **not** accepted on any request, so there is nothing for the
  client to send; a client-sent `late_fee_amount` is silently ignored (not
  fillable from any request path; proven by a test).
- The fee is a business computation, not request shape — consistent with the
  Architecture Baseline rule that requests validate shape and
  services/policies decide state (same stance as STEP 14 §8).

---

## 22. Response Format and HTTP Status Codes

- **200** — successful staff/customer cancel; response body contains the
  booking with `status: cancelled` plus (when applicable) a non-null
  `late_fee_amount` (a `decimal:2` string) and `late_fee_applied_at`.
- **409** — `BusinessRuleException` on any cancelled-once-already /
  `checked_in` / `checked_out` / `no_show` cancel (unchanged guards).
- **401** guest, **403** wrong actor / wrong role (unchanged middleware +
  `{success, message, errors}` envelope in `bootstrap/app.php`). No new status
  codes.

---

## 23. Test Strategy — Exact Scenarios

New `tests/Feature/Booking/LateCancellationFeeTest.php` (~18–20 tests), using
the existing `Pest.php` helpers (`makeBookingCustomer`, `makeBookingRoom`,
`makeHoldingBooking`, `loginAsEmployee`) and `$this->freezeTime()` +
`$this->travelBack()` in `try/finally` only for boundary pinning (STEP 13
test-fidelity precedent):

1. **Before the window → no fee:** staff cancels a `confirmed` booking far from
   check-in → 200, `late_fee_amount` null, `late_fee_applied_at` null, room
   `available`, audit row `'Booking cancelled'`.
2. **Inside the window → fee applied:** confirmed booking, cancel at
   `check_in_date − window + 1 min` → 200, fee = `fee_nights × Σ price_per_night`
   (snapshot), `late_fee_applied_at` set.
3. **Exact boundary (== cutoff) → late:** `now()` == `checkInStart−window`
   → fee applies (freezeTime).
4. **Exact boundary (one second before) → not late:** `now()` ==
   `cutoff − 1 s` → no fee.
5. **Fee formula — multi-room:** 2 rooms at different `price_per_night`
   snapshots → fee = 1 night of each, summed as `decimal:2`.
6. **Fee formula — fractional snapshot:** `99.99`/night × 1 → `99.99`,
   accumulated in integer cents (STEP 09 §5 convention).
7. **`pending_payment` staff-cancel inside the window → no fee** (not
   `confirmed`; rooms never reserved).
8. **Customer self-cancel `pending_payment` → no fee; `confirmed` self-cancel →
   409** (unchanged guards).
9. **Hold-expiry sweep cancel → no fee** (`'Payment deadline passed'` path).
10. **`checked_in` / `checked_out` / `no_show` / already-`cancelled` staff-cancel
    → each 409, no fee.**
11. **Client-cannot-manipulate:** POST cancel with body
    `{"reason":"x","late_fee_amount":0.01,"late_fee_applied_at":"..."}` →
    fee still server-computed / ignored (i.e., the cancellation still happens;
    when in window the fee is the server value).
12. **Room release + audit unchanged:** confirmed late-cancel → reserved room →
    `available`, one `room_status_logs` row `{reserved, available, changed_by:
    null, reason: 'Booking cancelled'}` (exact reasons preserved).
13. **`cancellation_reason` verbatim:** request reason stored unchanged; staff
    default `'Cancelled by staff'` when omitted (existing behavior).
14. **Fee visible in staff list/show and customer's own show** (null when no
    fee; correct value when set).
15. **Authorization matrix:** admin, manager, receptionist cancel confirmed →
    200 (+ fee when late); customer on staff route → 403; guest → 401.
16. **Ownership:** another customer's booking → 404 (scoped findOrFail,
    unchanged).
17. **Transaction rollback:** force a room-transition failure during a
    late-cancel (e.g., a booking whose room is already past a legal edge) →
    cancellation + fee both absent (all-or-nothing).
18. **Double-cancel:** second cancel → 409; exactly one fee record ever.
19. **Refund interaction (recommended rule):** verified payment on a confirmed
    booking + staff refund → `refunded`/`cancelled`, `late_fee_amount` stays
    null (refund never triggers a fee); a pre-existing staff-cancel fee is
    **not** cleared by any later refund attempt (refund 409s on the cancelled
    booking — proof the two paths don't mix).
20. **Migration/schema:** `Schema::hasColumn('bookings','late_fee_amount')` and
    `late_fee_applied_at` true; `DatabaseSchemaTest` untouched and green.

Helpers: a `makeConfirmedLateCancellableBooking(...)` may extend `Pest.php`
**additively** (new function, no edits to existing helpers).

---

## 24. Regression Protection for STEP 01–14

Full suite must finish **371 + ~18–20 + migration/schema ≈ 391–393 tests green
with zero edits to existing tests**. The exact existing tests that protect every
touched surface:

| Area | Protecting tests (unchanged, must stay green) |
|---|---|
| Booking creation + server pricing | `CustomerBookingTest`, `StaffCreateBookingTest`, `BookingPricingTest` |
| Cancellation (staff) | `StaffBookingTest` (`staff can cancel…`, `staff cancel of a confirmed booking returns its rooms…`, `staff cannot cancel checked_out/checked_in`), `BookingLifecycleTest` (`a confirmed booking can be cancelled by staff…`) |
| Cancellation (customer) | `CustomerBookingTest` (`a customer can cancel their own pending_payment booking`, `a customer cannot cancel a confirmed booking`) |
| Hold expiry | `HoldExpiryTest` (all 5 — `cancelExpiredHold`/`releaseExpiredHolds` untouched) |
| No-show (STEP 14) | `NoShowSweepTest` (all 16 — sweep and terminal 409s untouched) |
| Payment lifecycle | `PaymentCreateTest`, `PaymentVerifyBookingTest`, `StaffPaymentReviewTest` |
| Refund (STEP 11) | `RefundTest` (all 18 — `PaymentService::refund`, `refundBooking`, reason strings, audit preserved verbatim) |
| Room release + audit | `BookingLifecycleTest`, `StaffBookingTest`, `RefundTest`, `NoShowSweepTest` — `releaseReservedRooms`/`transitionRoom`/reason strings untouched |
| Authorization / actors / ownership | `AuthorizationTest`, `CustomerAuthTest`, `StaffAuthTest`, `AuthSecurityTest`, `BookingPolicy`-driven 401/403/404 tests |

**Hard invariants the implementation must respect (else regression):**
1. No change to `applyCancellation`, `releaseReservedRooms`, `transitionRoom`,
   `confirmWithinTransaction`, sweeps, or any reason string.
2. No change to `PaymentService::refund` / refund amounts / `payments.amount`.
3. `CancelRequest`, `routes/api.php`, all policies, and response **messages**
   unchanged.
4. Fee fields server-side only; never on any request.
5. Existing tests that staff-cancel a **same-day confirmed** booking
   (`StaffBookingTest:149`, `BookingLifecycleTest:75`) will, under the approved
   24 h window, record a fee in their response data — their assertions only
   check `status`, `cancellation_reason`, room state and audit rows, so they
   stay green. Confirm with a `assertExactJson`-scope grep at implementation
   time; no such assertion exists in the reviewed files.

---

## 25. Explicit Out-of-Scope (final checklist)

- ❌ No real-money movement, no payment gateway, no payment-row or
  `payments.amount` changes.
- ❌ No partial refunds, no fee withheld from a refund, no auto-refund of a fee.
- ❌ No change to `PaymentService::refund`, `BookingService::refundBooking`, or
  the refund state machine (`verified → refunded`).
- ❌ No change to customer self-service: self-cancel stays
  `pending_payment`-only; no customer-visible fee-cancel flow.
- ❌ No new endpoints / controllers / policies / FormRequests / services / log
  tables.
- ❌ No fee on hold-expiry, no-show, payment-verify/reject, or refund paths.
- ❌ No change to STEP 13 round-turnover (`cleaning → available`) or STEP 14
  no-show automation or their commands/schedules.
- ❌ No pricing tiers, discounts, invoices, ledgers, notifications,
  dashboard/reporting, UI, idempotency keys.
- ❌ No destructive schema changes — two additive nullable columns only; no
  enum changes; no `deleted_at` on bookings.

---

## 26. Files to Create / Modify

| File | Change | New? |
|---|---|---|
| `docs/STEP-15-IMPLEMENTATION-PLAN.md` | this plan | **new** |
| `database/migrations/2025_02_09_000001_add_late_fee_to_bookings_table.php` | 2 additive nullable columns (§8) | **new** |
| `tests/Feature/Booking/LateCancellationFeeTest.php` | ~18–20 tests (§23) | **new** |
| `docs/STEP-15-IMPLEMENTATION-REPORT.md` | written after implementation | **new** |
| `app/Services/BookingService.php` | `cancelByStaff()` fee step; private `isLateCancellation(Booking)`, `computeLateCancellationFee(Booking): int`, `applyLateCancellationFee(Booking)`; optional `lockForUpdate` re-read (§18) | modify (additive) |
| `app/Models/Booking.php` | `late_fee_amount` + `late_fee_applied_at` in `$fillable` and casts | modify (additive) |
| `config/booking.php` | `late_cancel_window_hours`, `late_cancel_fee_nights` | modify |
| `.env` / `.env.example` | `LATE_CANCEL_WINDOW_HOURS=24`, `LATE_CANCEL_FEE_NIGHTS=1` (values per §27 approval) | modify |

**No changes** to `routes/api.php`, `routes/console.php`, controllers,
policies, requests, `PaymentService`, `RoomService`, or any other service.

---

## 27. Unresolved Business Decisions — MUST BE APPROVED BEFORE IMPLEMENTATION

Per the STEP 15 scope instruction, these are **not** resolved by assumption.
Each shows the recommended value and the alternatives.

| # | Decision | Recommended | Alternatives |
|---|---|---|---|
| D-1 | Late-cancellation window model | hours before start of check-in day (`now() >= checkInStart − window`) | same-day-only; N days before |
| D-2 | Window value | `24` hours | 48 / 72 / other |
| D-3 | Fee formula | `fee_nights × Σ price_per_night` snapshot (1 night) | % of `total_amount`; fixed amount |
| D-4 | `fee_nights` value | `1` | other |
| D-5 | Refund interaction | Refund **neither triggers nor clears** the fee (no refund change) | (a) refund clears a recorded fee; (b) refund deducts fee *requires partial refund — rejected by STEP 11/15 scope unless you override* |
| D-6 | Customer surface | No customer fee-cancel flow (self-cancel stays `pending_payment`-only) | add a customer confirmed-cancel-with-fee surface (new scope) |

Approve D-1…D-6 (or specify overrides). **No migration, no code, no tests, no
behavior changes until D-1…D-6 are approved.** Once approved, implementation
follows §26 exactly with the plan's §0–§25 as the contract.

---

## 28. Wait for Approval

Plan only, with explicit §27 decisions for confirmation. No implementation
until approved.