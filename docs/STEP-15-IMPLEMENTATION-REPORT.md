# STEP 15 — Late-Cancellation Fees — Implementation Report

Plan is in `docs/STEP-15-IMPLEMENTATION-PLAN.md` (approved; business rules in
§3–§5, decisions §27 D-1..D-6 all approved exactly as recommended). This covers
what was actually built. STEP 01–14 behavior is unchanged; the full suite stays
green (395 tests / 1082 assertions / 0 failures — baseline was 371 / 958).

---

## 1. Files Created

- `database/migrations/2025_02_09_000001_add_late_fee_to_bookings_table.php`
  — the single additive schema change: two nullable columns on `bookings`
  (§2). Applied to the XAMPP MySQL dev DB (`hotel_management_v2`) via
  `php artisan migrate --force`; `migrate:status` shows it `Ran` (sequence [6]).
- `tests/Feature/Booking/LateCancellationFeeTest.php` — 24 new tests
  (124 assertions) covering the full §23 matrix.
- `docs/STEP-15-IMPLEMENTATION-REPORT.md` (this file).
- `docs/STEP-15-IMPLEMENTATION-PLAN.md` (written and approved before coding).

## 2. Migration / New Fee Field(s)

```php
Schema::table('bookings', function (Blueprint $table) {
    $table->decimal('late_fee_amount', 10, 2)->nullable()->after('no_show_at');
    $table->timestamp('late_fee_applied_at')->nullable()->after('late_fee_amount');
});
```

Two **additive, nullable** columns — existing bookings are untouched and remain
valid (verified on the dev DB via `php artisan db:table bookings`:

| Column | Type | Meaning |
|---|---|---|
| `late_fee_amount` | `decimal(10,2)` nullable | server-computed fee; `NULL` = no fee |
| `late_fee_applied_at` | `timestamp` nullable | the moment the fee was recorded |

`DatabaseSchemaTest` does not enumerate bookings columns and stays green with
zero edits (same reasoning as STEP 14). No new tables, no enum changes, no
indexes.

## 3. Files Modified

- `app/Models/Booking.php` — `late_fee_amount`, `late_fee_applied_at` added to
  `$fillable` (service-only writes, exactly as `cancelled_at`/`no_show_at`) and
  casts `'decimal:2'` / `'datetime'`. No request accepts either key.
- `app/Services/BookingService.php` — `cancelByStaff()` gained the fee step
  inside its **existing** `DB::transaction`; three private helpers added below
  `applyCancellation()`:
  - **`isLateCancellation(Booking): bool`** — `now() >=
    startOfDay(check_in_date) - config('booking.late_cancel_window_hours')`.
    Inclusive boundary (`>=`): exactly at the cutoff is late; one second before
    is not (pinned by tests).
  - **`computeLateCancellationFee(Booking): int`** —
    `late_cancel_fee_nights × Σ round(booking_rooms.price_per_night × 100)`,
    accumulated in integer cents (STEP 09 §5 convention), immune to later
    room-type price changes because it reads the booking-time snapshot.
  - **`applyLateCancellationFee(Booking): void`** — writes `late_fee_amount`
    via `formatCents()` (decimal:2) + `late_fee_applied_at = now()`,
    `save()`.
- `config/booking.php` — two new keys (env() used only here, STEP 02 rule):
  - `'late_cancel_window_hours' => (int) env('LATE_CANCEL_WINDOW_HOURS', 24)`
  - `'late_cancel_fee_nights' => (int) env('LATE_CANCEL_FEE_NIGHTS', 1)`
- `.env` / `.env.example` — `LATE_CANCEL_WINDOW_HOURS=24`,
  `LATE_CANCEL_FEE_NIGHTS=1`.
- `tests/Pest.php` — **additive** helper `makeConfirmedBookingWithRooms(...)`
  (confirmed booking with explicit `booking_rooms.price_per_night` snapshots
  and its rooms already `reserved`). No existing helper edited.

**No changes** to `PaymentService`, `RoomService`, any controller, FormRequest,
policy, route, `routes/console.php`, schedule, or the no-show/cleaning/hold
commands.

## 4. Endpoint Behavior

- **Staff cancel** `POST /api/v1/staff/bookings/{booking}/cancel` — unchanged
  guard (`pending_payment`/`confirmed`, else 409), unchanged
  `applyCancellation()` (status, `cancelled_at`, `cancellation_reason`,
  `releaseReservedRooms(..., 'Booking cancelled')`). **New:** after the
  cancellation, iff the re-read status was `confirmed` AND `isLateCancellation`
  is true, the response data (and row) carry `late_fee_amount` +
  `late_fee_applied_at`. Response message stays `'Booking cancelled.'`.
- **Customer cancel** — unchanged (`pending_payment`-only, no fee).
- **Staff/customer list & show** — the two fields serialize automatically
  (`NULL` unless set). No query/route change.
- **Refund** `POST /api/v1/staff/payments/{payment}/refund` — untouched; never
  triggers or clears a fee.

## 5. Exact Late-Fee Calculation

```
Evaluate only when an in-window staff cancel flips confirmed → cancelled:
window = config('booking.late_cancel_window_hours')                       # 24 h
cutoff = (check_in_date at start of day) − window
late   = now() >= cutoff                                                 # inclusive
fee_cents = config('booking.late_cancel_fee_nights')                     # 1
          × Σ over booking_rooms of round(price_per_night × 100)         # snapshot, int
late_fee_amount   = sprintf('%d.%02d', intdiv(fee_cents,100), fee_cents%100)  # formatCents
late_fee_applied_at = now()
```

The fee is **always server-computed** — never accepted from the client (verified
by test: a body with `late_fee_amount`/`late_fee_applied_at` is ignored).
`pending_payment` staff-cancels, hold-expiry (`'Payment deadline passed'`),
no-show (STEP 14), and refund paths never touch the fee fields.

## 6. Authorization Behavior

- Unchanged: staff route is `auth:sanctum, actor:staff` +
  `role:admin,manager,receptionist`, re-checked by `BookingPolicy::cancel`.
  Admin, manager and receptionist can all record a late fee (matrix test).
  Customer on the staff route → 403; guest → 401.
- Customers cannot cancel `confirmed` bookings at all (409 in `cancelByCustomer`),
  so a customer can never trigger a fee by construction. Ownership enforcement
  unchanged: `BookingPolicy::cancel` owner branch + scoped `findOrFail` (404 for
  another customer's booking) both untouched and covered.

## 7. Transaction / Concurrency Behavior

- The fee is computed and written inside the **existing single
  `DB::transaction` in `cancelByStaff()`** — status, cancellation fields, fee
  fields, room releases and `room_status_logs` rows commit or roll back
  together. Proven by the rollback test: a simulated failure on the audit write
  leaves the booking `confirmed`, the room `reserved`, and the fee fields NULL.
- Concurrency hardening: inside the transaction the booking row is **re-read
  with `lockForUpdate()`** and its status re-checked before the fee decision
  (mirrors `PaymentService::refund` and the STEP 13/14 re-check pattern). Two
  concurrent cancels serialize on the row; the loser re-reads `cancelled` and
  gets 409 — at most one fee record can ever exist (double-cancel test asserts
  exactly one release audit row and an unchanged fee).
- sqlite ignores `FOR UPDATE` (documented STEP 09 caveat); the functional
  behavior is covered by sequential tests, the project's established concurrency
  stance.

## 8. Test Result

- New suite: `tests/Feature/Booking/LateCancellationFeeTest.php`
  **24 tests / 124 assertions — PASS** (before/after-window, exact ±1 s
  boundaries via `travelTo`, one-/multi-room fees, fractional integer-cent
  accumulation, snapshot-not-current-price, client-injection, `pending_payment`,
  customer self-cancel, hold-expiry sweep, terminal-status 409s, reason default,
  fee visibility in staff/customer show, RBAC matrix, 403/401/404, rollback,
  double-cancel, refund-notrigger, refund-notclear, no-show, schema columns).
- Full suite: **395 tests / 1082 assertions / 0 failures** (371 + 24;
  958 + 124). STEP 01–14 regression green with zero edits to existing tests —
  including the two existing same-day confirmed staff-cancels
  (`StaffBookingTest`, `BookingLifecycleTest`) that now record a fee under the
  24 h window; their assertions check only status/reason/room/audit and stay
  green (plan §24.5).

## 9. Lint Result

- `php -l` on every created/modified PHP file — **no syntax errors**
  (migration, `BookingService.php`, `Booking.php`, `config/booking.php`,
  `Pest.php`, `LateCancellationFeeTest.php`).

## 10. Migration / Debug-File Verification

- `php artisan migrate --force` applied only the STEP 15 migration to the XAMPP
  MySQL dev DB; `php artisan migrate:status` shows all 6 migrations `Ran`; the
  two new columns are present and nullable (verified with `db:table bookings`).
- No temporary/debug files were introduced; only pre-existing macOS `.DS_Store`
  artifacts and the standard `storage/logs/laravel.log` remain (none created by
  this step).

## 11. Deviations from the Approved STEP 15 Plan

None in scope. Two test-implementation notes (same rationale as STEP 14 §7):

- Laravel's test client caches the authenticated user between HTTP requests
  within one test case; the two-actor legs (staff-then-customer; then guest)
  were therefore written as standalone tests (403 alone, 401 alone), matching
  the established STEP 07/13/14 pattern. Identical coverage, zero flakiness.
- Time-boundary pinning uses `travelTo()` (this framework version's
  `freezeTime()` takes a callable, not a date); the try/finally `travelBack()`
  pattern is preserved exactly as in STEP 13/14.

## 12. Out-of-Scope Confirmation

No real-money movement / gateway / payment-row or `payments.amount` changes; no
partial refunds, no fee withheld from or auto-refunded by the refund engine; no
change to customer self-service (self-cancel stays `pending_payment`-only, no
customer fee-cancel flow); no new endpoints / controllers / policies /
FormRequests / services / log tables; no fee on hold-expiry, no-show, or refund
paths; no change to STEP 13 cleaning or STEP 14 no-show automation; no pricing
tiers, invoices, ledgers, notifications, dashboard, or UI; no destructive
schema changes.