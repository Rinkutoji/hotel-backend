# STEP 11 — Refund Processing Plan (review before implementation below)

## 0. Pre-coding Review (read the actual project, not assumptions)

**Schema — complete, no migration needed.** The STEP 03 `payments` table
already has every column this step needs (verified against the actual
migration and `DatabaseSchemaTest`):

- `payments`: `status` enum `[pending, receipt_uploaded, verified, rejected,
  refunded]` — `refunded` exists but is currently unreachable. `notes` text
  nullable — stores the refund reason (same field rejection uses; the last
  write wins, which is the correct semantic: if a payment was rejected and
  later re-uploaded, verified, and refunded, `notes` naturally transitions
  from rejection reason to refund reason). `verified_at` / `verified_by`
  are preserved on refund (already set by STEP 10 verify; refund does not
  clear them). `booking_id` UNIQUE (1:1), `amount` decimal(10,2), `method`
  enum, no `deleted_at`.
- `bookings`: `status` enum including `cancelled`, `cancelled_at` datetime
  nullable, `cancellation_reason` text nullable — all already exist and are
  used by STEP 09's cancellation logic. No schema change needed.

**Existing scaffold (deliberately placed in earlier STEPs for STEP 11):**

- `AppServiceProvider` `Gate::define('process-refund', ...)`: admin +
  manager only. Comment at line 29 says *"The actual refund business
  logic is deferred to STEP 11; this only prepares the authorization
  rule."* This gate is now consumed by the refund endpoint.
- `PaymentService::VERIFIED_STATUSES = ['verified', 'refunded']`: already
  blocks `uploadReceipt()` on a refunded payment (line 76) — no change
  needed, refunded payments are correctly treated as upload-terminal.
- `PaymentFilterRequest` already accepts `refunded` as a valid `?status=`
  value for both customer and staff list endpoints (verified against both
  request classes).

**What refund does to existing rows:**

| Table | Column | Change |
|---|---|---|
| `payments` | `status` | `verified → refunded` |
| `payments` | `notes` | Set to refund reason (may overwrite a previous rejection reason — correct) |
| `bookings` | `status` | `confirmed → cancelled` (if confirmed) or `pending_payment → cancelled` (if still pending) |
| `bookings` | `cancelled_at` | Set to `now()` |
| `bookings` | `cancellation_reason` | Set to `"Refunded by staff: {reason}"` |
| `rooms` | `status` | `reserved → available` (if booking was confirmed and rooms are reserved) |
| `rooms` | `status_changed_at` | Updated |
| `room_status_logs` | (new row) | `from_status=reserved, to_status=available, reason=Refunded by staff: {reason}` |

**Existing code reused (not modified):**

- `BookingService::transitionRoom()` — `private`, writes `room_status_logs`
  audit row, validates against `RoomService::isTransitionValid` (`reserved
  → available` is valid since STEP 09).
- `BookingService::applyCancellation()` — sets `status=cancelled`,
  `cancelled_at=now()`, `cancellation_reason`. Currently `private` and does
  NOT accept an employee argument; STEP 11 does NOT modify it (a new
  `refundBooking()` method handles refund-specific cancellation with an
  employee for the room audit trail).
- `BookingService::releaseReservedRooms()` — `private`, iterates
  `bookingRooms`, transitions `reserved → available` if room is still
  reserved. Refund reuses this exact logic but calls `transitionRoom`
  with the employee who processed the refund for the audit trail.
- `RoomService::VALID_TRANSITIONS` — `reserved → available` already valid
  (STEP 09 added it); no modification.
- `PaymentPolicy` — `verify()` / `reject()` patterns reused for `refund()`.
- `BusinessRuleException` → 409 HTTP envelope (configured in
  `bootstrap/app.php`).

## 1. Scope

**In (working end-to-end MVP):** staff process a refund on a verified
payment; refund reason captured; payment status moves `verified → refunded`;
booking atomically cancelled (if `confirmed` or `pending_payment`); rooms
released (`reserved → available`, if confirmed); room_status_logs audit
row written; double-refund prevention; role-based authorization (admin +
manager only, via `process-refund` Gate); full transaction safety
(atomicity, lockForUpdate on payment + booking rows).

**Out (deliberately):** actual money movement / payment gateway integration,
partial refunds (1:1 payment, full-amount refund only), customer-initiated
refunds, refund of payments for `checked_in` / `checked_out` bookings
(guest has already stayed), `no_show` sweep, `cleaning → available`
(STEP 13), refund of already-cancelled bookings (terminal — no
double-action), notification/email on refund, refund approval workflows.

## 2. API Contract

### Staff — `/api/v1/staff/payments` — group middleware `['auth:sanctum', 'actor:staff']`

| Method | URL | Route middleware | Notes |
|---|---|---|---|
| POST | `/payments/{payment}/refund` | `role:admin,manager` | `verified → refunded`; booking cancelled atomically; rooms released if confirmed |

**Refund endpoint detail:**

- Request body: `{ "reason": "Guest cancelled via phone" }` — `reason`
  required, string, max 255.
- Pre-conditions checked inside transaction (with row locks):
  1. Payment `status === 'verified'` else **409**.
  2. Payment's booking exists and status is one of `confirmed`,
     `pending_payment` else **409** (checked_in/checked_out/cancelled are
     not refundable — guest has used the room or booking already ended).
  3. Payment and booking rows locked via `lockForUpdate` (serialises
     concurrent refund attempts on the same booking).
  4. Double-check `status === 'verified'` under lock (concurrent refund).
- Writes (all in one `DB::transaction`):
  1. `payment.status = 'refunded'`
  2. `payment.notes = $reason` (stores the refund reason; overwrites any
     prior rejection notes — correct semantic)
  3. `booking.status = 'cancelled'`, `cancelled_at = now()`,
     `cancellation_reason = 'Refunded by staff: {reason}'`
  4. For each `bookingRoom` where `room.status === 'reserved'`:
     room `reserved → available` + `room_status_logs` audit row
     (`changed_by = null` for system-level transition, consistent with
     STEP 09 `releaseReservedRooms` pattern).

**Response:** same shape as other payment endpoints — the updated payment
with `booking` relation, HTTP 200 with success envelope.

## 3. Authorization Matrix

| | Admin | Manager | Receptionist | Customer (own) | Guest |
|---|:---:|:---:|:---:|:---:|:---:|
| Refund verified payment | ✅ | ✅ | ❌ | ❌ | ❌ |
| List/view payments (staff) | ✅ | ✅ | ✅ | – | ❌ |

Receptionist is excluded from refunds — the `process-refund` Gate in
`AppServiceProvider` (line 31) defines admin + manager only. This is a
deliberate design choice: refunds are a financial operation requiring
elevated authority. The route `role:admin,manager` middleware enforces this
at the HTTP layer, and `$this->authorize('refund', $payment)` re-checks it
inside the controller (two-layer pattern).

**No customer refund endpoint.** Refunds are staff-initiated operations.
The customer-facing workflow is: customer contacts staff → staff processes
refund. This matches real hotel operations and keeps the authorization
model simple.

`PaymentPolicy::refund()` implementation:

```php
public function refund($user): bool
{
    if ($user instanceof Employee) {
        return Gate::allows('process-refund', $user);
    }
    return false;
}
```

This delegates to the existing Gate, keeping the single source of truth
for refund role requirements in `AppServiceProvider` as the Architecture
Baseline intended.

## 4. Payment + Booking Refund State Transitions

**Payment:**
```
verified ──staff refund──▶ refunded (terminal — no further uploads/verifies/refunds)
```

| From | To | Trigger |
|---|---|---|
| `verified` | `refunded` | staff refund (admin/manager only) |
| anything else | — | 409 (`pending`, `receipt_uploaded`, `rejected`, already `refunded` all blocked) |

`refunded` remains terminal — `uploadReceipt()` already rejects both
`verified` and `refunded` via `VERIFIED_STATUSES` check in
`PaymentService` (line 76). No change needed.

**Booking (concurrent with payment refund):**

| Booking From | Booking To | Room Effect |
|---|---|---|
| `confirmed` | `cancelled` | `reserved → available` (rooms released) |
| `pending_payment` | `cancelled` | no room transitions (rooms never reserved) |
| `checked_in` | — | **409** (guest is staying; cannot refund after check-in) |
| `checked_out` | — | **409** (stay complete; cannot refund after check-out) |
| `cancelled` | — | **409** (already terminal; no double-action) |
| `no_show` | — | **409** (already terminal) |

## 5. Transaction Boundary (Atomicity)

`PaymentService::refund(Payment $payment, Employee $employee, string
$reason): Payment`

One `DB::transaction` wrapping the entire operation:

1. **Pre-check** (outside lock): `payment.status === 'verified'` else 409;
   booking status in `['confirmed', 'pending_payment']` else 409.
2. **Lock both rows** in deterministic order (payment first, then booking)
   via `lockForUpdate` — serialises concurrent refund/verify/reject on the
   same payment, and concurrent confirm/cancel/refund on the same booking.
3. **Double-check** payment `status === 'verified'` under lock (prevents
   TOCTOU double-refund).
4. **Write payment:** `status = 'refunded'`, `notes = $reason`.
5. **Write booking:** `status = 'cancelled'`, `cancelled_at = now()`,
   `cancellation_reason = 'Refunded by staff: {reason}'`.
6. **Release rooms** (if booking was `confirmed`): for each
   `bookingRoom->room` where `room.status === 'reserved'`, transition
   `reserved → available` and write `room_status_logs` audit row.
   (Rooms that have already moved to `occupied` or `cleaning` are not
   touched — the check-in/check-out guard in step 2 already prevented
   refunding a checked-in/checked-out booking.)
7. Return fresh payment with `booking` and `verifiedBy` relations.

All six writes (payment, booking, N rooms, N audit logs) commit or roll
back together. If any room transition fails (should not happen given the
pre-check, but belt-and-braces), the entire transaction rolls back.

**Lock ordering note:** payment row locked first, then booking row —
consistent with `PaymentService::verify()` pattern (line 127–128 of
`PaymentService.php`). This prevents deadlock between concurrent verify
and refund on the same payment.

## 6. Service / Policy / Model / Request

**`PaymentService` (modified):** new `refund()` method as §5. No changes
to existing `create()`, `uploadReceipt()`, `verify()`, `reject()`.

**`BookingService` (new method):** `refundBooking(Booking $booking,
Employee $employee, string $reason): void` — private method that handles
the refund-specific booking cancellation + room release. This parallels
`applyCancellation()` but is tailored for refunds:
- Accepts `Employee $employee` to record who triggered the refund in the
  room status log (`changed_by` set to the employee's ID, providing an
  audit trail that `applyCancellation` deliberately omits for
  system-triggered cancellations).
- No expired-hold check (a verified payment is inherently non-expired in
  the normal flow; even if a race occurred, the pre-check at §5 step 1
  already ensures the booking is `confirmed` or `pending_payment`).
- Reuses `transitionRoom()` for each room (already handles validation +
  audit log).

This avoids modifying `applyCancellation()` or `cancelByStaff()`, both
of which are called from other contexts (STEP 09 customer cancel,
expired-hold sweep, staff cancel) where adding an employee parameter
would change the API surface.

**`PaymentPolicy` (modified):** add `refund($user): bool` method
delegating to `Gate::allows('process-refund', $user)`.

**`Staff/PaymentController` (modified):** add `refund()` action:

```php
public function refund(RefundRequest $request, Payment $payment, PaymentService $service): JsonResponse
{
    $this->authorize('refund', $payment);

    $payment = $service->refund($payment, $request->user(), $request->validated('reason'));

    return ApiResponse::success($payment, 'Payment refunded.');
}
```

Follows the exact same shape as `verify()` and `reject()` (§3 two-layer
pattern).

**`RefundRequest` (new):** `App\Http\Requests\Payment\RefundRequest`
extends `ApiFormRequest`:

```php
public function rules(): array
{
    return [
        'reason' => ['required', 'string', 'max:255'],
    ];
}
```

Shape-only validation. Authorization via `authorize()` →
`PaymentPolicy::refund()`.

## 7. Files New / Modified

| File | Change |
|---|---|
| `app/Services/PaymentService.php` | add `refund()` method |
| `app/Services/BookingService.php` | add `refundBooking()` private method |
| `app/Policies/PaymentPolicy.php` | add `refund()` method |
| `app/Http/Controllers/Api/V1/Staff/PaymentController.php` | add `refund()` action |
| `app/Http/Requests/Payment/RefundRequest.php` | new — `reason` required string max 255 |
| `routes/api.php` | add refund route |
| `tests/Feature/Payment/RefundTest.php` | new — ~25 tests |
| `docs/STEP-11-IMPLEMENTATION-REPORT.md` | written after implementation |

**No migration.** Verified against the actual STEP 03 `payments` migration
(`refunded` in the status enum, `notes` column exists) and `bookings`
migration (`cancelled_at`, `cancellation_reason` both exist).
`DatabaseSchemaTest` stays green — no schema changes.

**No changes to `config/`** — no refund-specific configuration needed.

## 8. Edge Cases & Security Cases

- **Double-refund (concurrent requests):** the `lockForUpdate` + double-
  check under lock at §5 step 3 ensures the second request sees
  `refunded` status and throws 409. The DB-level isolation (REPEATABLE
  READ) guarantees the lock is respected.
- **Refund on `checked_in` / `checked_out` booking → 409:** guest has
  already used the room. The pre-check at §5 step 1 enforces this before
  any write.
- **Refund on `cancelled` booking → 409:** booking is terminal, no
  double-action.
- **Refund on `pending_payment` booking (unusual but valid):** payment
  status is `verified` (staff verified the receipt), but booking is still
  `pending_payment` (staff verified before confirming, or a race). Refund
  cancels the booking; no room transitions needed (rooms were never
  reserved for `pending_payment` bookings). This is valid and safe.
- **Refund reason injection:** client-sent `reason` is the only accepted
  field. `amount`, `status`, `verified_at`, `verified_by` are never
  mass-assignable (same STEP 10 pattern — `$fillable = ['method']` only).
  Test proves `status` injection is silently ignored.
- **Receipt file on refund:** kept on disk for audit (same policy as
  rejection — the receipt proves what was paid; deleting it would destroy
  evidence). `receipt_path` is not cleared.
- **`verified_at` / `verified_by` preserved:** refund does not clear who
  verified the payment or when — the verification audit trail is
  independent of the refund.
- **Role escalation prevention:** receptionist cannot refund (403 via
  `role:admin,manager` middleware + `process-refund` Gate double-check).
  Customer cannot reach the staff route (actor middleware). Guest → 401.
- **`no_show` bookings:** enum-only, out of scope; refund on `no_show`
  → 409 (not in `['confirmed', 'pending_payment']`).
- **Cleanliness of `notes` field:** refund overwrites any previous
  rejection reason in `notes`. This is correct — the most recent
  financial action is what `notes` reflects. The rejection audit is
  preserved on-disk in the receipt file (receipt is not deleted on
  rejection, per STEP 10 §7).

## 9. Test Plan (~25 tests)

`tests/Feature/Payment/RefundTest.php` — all using Pest + RefreshDatabase:

- **Golden path (confirmed booking):** staff (admin) refund a verified
  payment on a confirmed booking → payment `status=refunded`, `notes=reason`,
  booking `status=cancelled`, `cancelled_at` set,
  `cancellation_reason="Refunded by staff: {reason}"`, rooms
  `reserved→available`, `room_status_logs` audit row written with the
  correct from/to/reason; response 200 with payment+booking.
- **Golden path (pending_payment booking):** same but booking still
  `pending_payment` → payment refunded, booking cancelled, no room
  transitions (rooms never reserved).
- **Manager can refund:** same as golden path with `manager` role → 200.
- **Receptionist denied → 403:** receptionist token on refund → 403.
- **Customer token → 403:** customer token on `/staff/payments/.../refund`
  → 403 (wrong actor).
- **Guest → 401:** no token → 401.
- **Non-existent payment → 404.**
- **Payment `pending` → 409:** refund before upload → 409.
- **Payment `receipt_uploaded` → 409:** refund before verify → 409.
- **Payment `rejected` → 409:** refund on a rejected payment → 409.
- **Payment already `refunded` → 409:** double refund → 409.
- **Payment `verified` but booking `checked_in` → 409:** guest is
  staying — cannot refund.
- **Payment `verified` but booking `checked_out` → 409:** stay complete.
- **Payment `verified` but booking `cancelled` → 409:** already cancelled.
- **Reason validation → 422:** missing reason → 422; empty string → 422;
  >255 chars → 422.
- **Reason stored in `notes`:** verify the reason string appears in
  `payment.notes` and `booking.cancellation_reason`.
- **Refund does NOT clear `verified_at` / `verified_by`:** response and
  DB show original verify timestamps and employee id preserved.
- **Receipt file preserved on refund:** `receipt_path` still set after
  refund (file not deleted).
- **Client-sent `status` injection ignored:** POST refund with
  `{"reason":"x","status":"pending"}` — status still `refunded` (the
  injected value is ignored; `$fillable` does not include `status`).
- **Existing 298 tests stay green:** no regression in booking, payment,
  or room tests (the `refundBooking` method is additive; existing cancel
  paths untouched).

## 10. Regressions to Protect

- All 298 existing tests (STEP 02–10) must pass with zero changes.
- `BookingService::applyCancellation()`, `cancelByStaff()`,
  `cancelByCustomer()`, `confirm()`, `checkIn()`, `checkOut()` are NOT
  modified — their tests are unaffected.
- `PaymentService::verify()`, `reject()`, `create()`, `uploadReceipt()`
  are NOT modified — their tests are unaffected.
- `PaymentPolicy::verify()`, `reject()` are NOT modified —
  `PaymentPolicyTest` unchanged.
- `RoomService::VALID_TRANSITIONS` is NOT modified — `reserved → available`
  was added in STEP 09; no new transitions added here.
- `BookingService::transitionRoom()` is called (not modified) — its
  existing validation + audit-log behavior is inherited.
- `PaymentFilterRequest` already accepts `refunded` — no change needed.
- `PaymentService::VERIFIED_STATUSES` already includes `refunded` —
  `uploadReceipt()` already blocks on refunded payments, no change.

## 11. Wait for Approval

Plan only. No implementation until approved.
