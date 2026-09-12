# STEP 11 — Refund Processing — Implementation Report

Plan is in `docs/STEP-11-IMPLEMENTATION-PLAN.md` (written first, per
instruction, including the pre-coding review that verified the existing
`process-refund` Gate in `AppServiceProvider`, the `refunded` status already
present in the `payments` enum, and the reusable `BookingService` /
`RoomService` / audit-log machinery). This covers what was actually built on
top of it.

---

## 1. Files Created

- `app/Http/Requests/Payment/RefundRequest.php` — shape-only validation,
  `reason` required string max 255 (ApiFormRequest convention).
- `tests/Feature/Payment/RefundTest.php` — 19 new tests.
- `docs/STEP-11-IMPLEMENTATION-REPORT.md` (this file).

## 2. Files Modified

- `app/Services/BookingService.php` — added `refundBooking(Booking $booking,
  string $reason): void` — parallels `applyCancellation()` but is tailored
  for refunds so `cancellation_reason` carries `"Refunded by staff: {reason}"`
  and the `room_status_logs` rows carry the refund reason instead of a
  generic "Booking cancelled". Reuses the existing private method
  `transitionRoom()` unchanged (room validation + `reserved → available` +
  audit log), so `RoomService::VALID_TRANSITIONS` was NOT touched. `changed_by`
  stays null, consistent with the system-level transition pattern. No wrapper
  — callers (`PaymentService::refund`) own the transaction, matching the
  `confirmWithinTransaction` precedent.
- `app/Services/PaymentService.php` — added `refund(Payment $payment,
  string $reason): Payment` — single `DB::transaction` that locks the payment
  then the booking row (`lockForUpdate`), re-checks the payment is still
  `verified` under lock (double-refund / TOCTOU guard), sets
  `status='refunded'` + `notes=$reason`, then calls
  `BookingService::refundBooking()` so the booking cancellation + room
  release + audit rows commit atomically with the payment refund. Locks the
  payment row first, then the booking (deadlock-safe, mirrors `verify()`).
  The `refund()` method is NOT referencing `Gate` — authorization stays at
  the policy/route layer.
- `app/Policies/PaymentPolicy.php` — added `refund($user): bool` delegating
  to the existing `process-refund` Gate via `Gate::forUser($user)->allows(
  'process-refund')`. This keeps the refund capability a single source of
  truth in `AppServiceProvider`, exactly as the plan and the Gate's own
  comment intended.
- `app/Http/Controllers/Api/V1/Staff/PaymentController.php` — added
  `refund()` action following the exact `verify()`/`reject()` shape:
  `$this->authorize('refund', $payment)` then
  `$service->refund($payment, $request->validated('reason'))`.
- `routes/api.php` — added
  `POST /api/v1/staff/payments/{payment}/refund` with
  `role:admin,manager` route middleware (narrower than verify/reject,
  matching `process-refund`).

**No migration.** Verified against the actual STEP 03 `payments` migration
(`refunded` in the status enum, `notes` column) and `bookings` migration
(`cancelled_at`, `cancellation_reason`). `DatabaseSchemaTest` stays green.

**No changes to `RoomService::VALID_TRANSITIONS`, `BookingService` existing
methods, `PaymentService` existing methods (create/uploadReceipt/verify/
reject), `PaymentPolicy` existing methods, or the `process-refund` Gate.**

## 3. API Endpoint

| Method | URL | Middleware | Roles |
|---|---|---|---|
| POST | `/api/v1/staff/payments/{payment}/refund` | `auth:sanctum, actor:staff` + `role:admin,manager` | admin, manager |

Request body: `{ "reason": "<string, max 255>" }` — required, the only
accepted field.

Response: HTTP 200 with the updated payment (relations: booking, verifiedBy)
in the standard success envelope; `message: "Payment refunded."`.

## 4. Refund State Transition

**Payment:** `verified → refunded` (terminal). `pending`, `receipt_uploaded`,
`rejected`, and already-`refunded` payments → **409**. `verified_at` /
`verified_by` and `receipt_path` are preserved (audit / evidence kept).

**Booking (atomic with the payment refund):**

| Booking Status | New Status | Room Effect |
|---|---|---|
| `confirmed` | `cancelled` | reserved rooms → `available` + `room_status_logs` row |
| `pending_payment` | `cancelled` | no room change (rooms were never reserved) |
| `checked_in` / `checked_out` / `cancelled` / `no_show` | — | **409** |

## 5. Transaction Behavior (Atomicity)

`PaymentService::refund()` is a single `DB::transaction`:

1. Pre-check (outside lock): payment `status === 'verified'`, booking in
   `['confirmed', 'pending_payment']` — else 409.
2. Lock the payment row, then the booking row, via `lockForUpdate`.
3. Under lock, re-check the payment is still `verified` — a concurrent
   refund/verify/change is detected and the second caller gets 409 (double
   refund prevented).
4. Write payment: `status='refunded'`, `notes=$reason`.
5. Write booking via `refundBooking()`: status `cancelled`,
   `cancelled_at=now()`, `cancellation_reason="Refunded by staff: {reason}"`,
   and for each reserved room `available` + `room_status_logs` audit row.

All of these commit or roll back together. If any write fails (e.g. an
unexpected room transition error, though the pre-check prevents it), the
entire refund — payment, booking, rooms, logs — is rolled back. (sqlite
ignores `FOR UPDATE`, so row-locking is functionally covered in tests; the
lock is real on MySQL, same caveat as STEP 09.)

## 6. Authorization Behavior

- **Route layer:** `role:admin,manager` middleware rejects a receptionist
  (403) before the controller runs. Customer tokens on the staff route → 403
  (actor middleware). Guests → 401.
- **Policy layer:** `PaymentPolicy::refund()` re-checks via the shared
  `process-refund` Gate (Admin + Manager only), so capability is a single
  source of truth. Two-layer pattern preserved.
- **Customer-initiated refunds are not implemented** (no customer route) —
  refunds are staff-only by design, per plan.

## 7. Edge Cases Covered (via tests)

- Double refund / concurrent booking state drift → 409 under lock re-check.
- Payment in `pending` / `receipt_uploaded` / `rejected` → 409.
- Booking `checked_in` / `checked_out` / `cancelled` → 409.
- Missing / empty / >255-char reason → 422.
- Non-existent payment → 404.
- Client-sent `status` / `amount` injection silently ignored.
- `verified_at` / `verified_by` / `receipt_path` preserved on refund.
- Report fully covers golden paths (confirmed + pending bookings) and
  authorization matrix (admin/manager allow, receptionist/customer deny).

## 8. Test Count / Result

- Full suite: **319 passed (732 assertions)**, 0 failures (~2.9s, sqlite
  in-memory). 300 pre-existing STEP 01–10 tests stayed green + **19 new
  STEP 11 tests (57 assertions)** in `tests/Feature/Payment/RefundTest.php`.
- No regression in Booking, Payment, Room, or Authorization suites
  (`RoomService::VALID_TRANSITIONS` and all existing service methods were
  deliberately left untouched).

## 9. Lint Result

`php -l` clean on every created/modified PHP file (7 files):
`BookingService.php`, `PaymentService.php`, `PaymentPolicy.php`,
`RefundRequest.php`, `Staff/PaymentController.php`, `routes/api.php`,
`RefundTest.php`.

## 10. Temporary / Debug Files

None left. No debug files added; the `tests/Feature/Payment/` directory
contains only the planned test files.

## 11. Deviations from the Approved Plan

1. **`BookingService::refundBooking` made `public`, not `private`** — the
   plan §6 listed it as private, but it is called from
   `PaymentService::refund()`, which owns the transaction. Making it public
   mirrors the existing `confirmWithinTransaction()` (also public, also
   invoked from inside `PaymentService::verify`'s transaction). A private
   method would have been unreachable from `PaymentService`.
2. **Plan §6's proposed `Employee` parameter on `refundBooking` was dropped,**
   and the `room_status_logs.changed_by` stays **null** — §2 and §0 of the
   plan themselves specify `changed_by = null` (consistent with the
   `releaseReservedRooms` pattern), so passing an Employee through to the
   audit log would have contradicted the plan's own `changed_by = null`
   requirement. The `Employee` actor is enforced at the route + policy layer,
   not persisted per-room.
3. **The `process-refund` Gate itself was NOT re-tested in RefundTest** — it
   is already covered by the pre-existing `AuthorizationTest` (line 148,
   "refund authorization matrix"). RefundTest instead asserts the new
   `PaymentPolicy::refund()` delegates correctly and cross-checks it against
   the Gate (`Gate::forUser(...)->allows('process-refund')`), avoiding
   duplicate coverage.
4. **`PaymentPolicy::refund()` uses `Gate::forUser($user)`** rather than
   `Gate::allows('process-refund', $user)`. The latter would shadow the
   acting user (the Gate's first param is the authenticated user from the
   request context, which is not the `$user` argument passed to `allows`)
   and wrongly return false. `forUser($user)` correctly impersonates the
   resolved policy user.

**Out of scope honored:** no gateway/money movement, no partial refunds, no
customer-initiated refunds, no `no_show` automation, no `cleaning →
available` (STEP 13). No unrelated STEP 09/10 behavior modified.