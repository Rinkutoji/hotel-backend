# STEP 12 — Staff Booking on a Customer's Behalf — Implementation Report

Plan is in `docs/STEP-12-IMPLEMENTATION-PLAN.md` (approved). This covers what
was actually built on top of it. STEP 10 and STEP 11 behavior are unchanged;
STEP 13 is untouched.

---

## 1. Files Created

- `app/Http/Requests/Booking/StaffCreateBookingRequest.php` — thin request
  that `extends CreateBookingRequest` (inheriting the shared date/room shape
  without duplicating it) and adds ONLY the staff-only `customer_id` rule:
  required, integer, model-backed existence check that runs through the
  `Customer` model so the SoftDeletes global scope excludes soft-deleted
  customers (matching the rooms rule convention). Isolating it here prevents
  the customer self-booking route from ever accepting a `customer_id`
  (docs/STEP-12 §7 / §16).
- `tests/Feature/Booking/StaffCreateBookingTest.php` — 16 new tests (60
  assertions).
- `docs/STEP-12-IMPLEMENTATION-REPORT.md` (this file).

## 2. Files Modified

- `app/Policies/BookingPolicy.php` — added `createForCustomer($user): bool`
  returning `true` for an `Employee` in `['admin','manager','receptionist']`,
  `false` otherwise. The existing `create()` (customer self-book) is
  **unchanged** — a staff member calling the customer route still gets 403 via
  `actor:customer`, and `create()` stays customer-only.
- `app/Http/Controllers/Api/V1/Staff/BookingController.php` — added a
  `store(StaffCreateBookingRequest, BookingService)` action: `authorize(
  'createForCustomer', Booking::class)` → resolve the staff-chosen customer via
  `Customer::findOrFail($request->validated('customer_id'))` → delegate to the
  reused `BookingService::create($request->validated(), $customer->id)` → return
  `ApiResponse::success($booking, 'Booking created.', 201)`. The acting staff
  member is never the booking owner.
- `routes/api.php` — added `POST /api/v1/staff/bookings` inside the existing
  staff `bookings` group (`auth:sanctum, actor:staff`) with
  `role:admin,manager,receptionist` middleware.

**No migration. No schema change.** `bookings.customer_id` already references
`customers`. `DatabaseSchemaTest` is unchanged and green.

**No change to** `BookingService::create()`, `searchAvailable()`,
`PaymentService`, `RoomService::VALID_TRANSITIONS`, any model, or any existing
controller/policy/route behavior.

## 3. API Endpoint

| Method | URL | Middleware | Roles |
|---|---|---|---|
| POST | `/api/v1/staff/bookings` | `auth:sanctum, actor:staff` + `role:admin,manager,receptionist` | admin, manager, receptionist |

Request body (shared customer-create shape plus a `customer_id`):

```json
{
  "customer_id": 12,
  "check_in_date": "2026-09-20",
  "check_out_date": "2026-09-23",
  "rooms": [3, 5]
}
```

Response: **201** with `{success, message: "Booking created.", data}` where
`data` is the created booking with `bookingRooms.room`,
`bookingRooms.roomType`, and `customer` relations loaded.

## 4. Authorization Behavior

- **401** — unauthenticated guest.
- **403** — customer token on the staff route (actor middleware);
  staff roles outside admin/manager/receptionist (role middleware, though no
  other staff role exists to flow through here).
- **Policy re-check:** the controller calls `$this->authorize('createForCustomer')`
  after route middleware, keeping the existing two-layer pattern.

## 5. Customer Ownership Behavior

- `customer_id` is staff-only input, required and validated (`exists` among
  non-deleted customers via model-backed rule); the controller re-checks with
  `findOrFail` → **404** for a missing/soft-deleted customer.
- The acting staff member is never the booking owner — the booking's
  `customer_id` is the body-specified customer.
- A staff token can never act as a customer (route is `actor:staff`-guarded),
  and a customer can never set another customer as owner (the customer route
  has no `customer_id` field; it derives owner from the authenticated actor).
- Downstream ownership (customer list/view/cancel) is unchanged and respects
  the staff-chosen owner — verified: unrelated customers get an empty list and
  a 404 on view.

## 6. Lifecycle Behavior

- A staff-created booking is created `pending_payment` (reusing
  `BookingService::create()` verbatim), so server-side pricing
  (nights × roomType.base_price snapshot, integer cents), the `payment_deadline_at`
  hold deadline, the unique `BK-` number, the `booking_rooms` snapshot rows,
  and the availability / double-booking `FOR UPDATE` locking / overlap /
  maintenance / soft-delete guards all apply identically to the customer flow.
- Creating it does **not** change room status (room status is unchanged at
  creation; rooms are reserved only on confirm) — matching STEP 09.
- It then flows through the same pipeline: staff confirm → confirmed + room
  reserved + audit log → check-in → check-out → payment/refund (STEP 10/11),
  with no divergence.

## 7. Test Strategy — Result

New `tests/Feature/Booking/StaffCreateBookingTest.php` (16 tests / 60
assertions):

1. Admin creates for an existing customer → 201, `pending_payment`,
   `customer_id` = chosen customer, server-side total (`200.00` for 2 nights),
   deadline = `created_at + hold_hours`, snapshot rows, room status remains
   `available`.
2. Manager and receptionist can both create → 201 each.
3. Customer token on the staff route → 403, no booking created.
4. Guest → 401.
5. Non-existent `customer_id` → 422, no booking created.
6. Soft-deleted `customer_id` → 422, no booking created (plan §17#6 — the
   implementation produces 422 via the model-backed existence rule; noted).
7. Client-sent `total_amount`/`price`/`status` are ignored; total is
   server-computed (`300.00` for 3 nights), status stays `pending_payment`.
8. Overlapping dates on chosen room → 409, no booking created (atomic).
9. Non-overlapping consecutive stay on same room → 201.
10. Maintenance room → 409.
11. Check-in/out validation (past check-in, check_out ≤ check_in, over max
    nights), non-existent/duplicate/soft-deleted room ids → 422.
12. Multi-room booking → 201, combined server-side pricing (`400.00`).
13. Staff-created booking viewable by staff and owned by chosen customer;
    unrelated customer sees an empty list and a 404 on view.
14. Staff-created booking flows into the normal confirm pipeline: confirm →
    `confirmed`, room `reserved`, audit log `to_status=reserved` /
    reason `Booking confirmed`.
15. Staff is never treated as owner (covered by #1/#2/#13 assertions on
    `customer_id`).
16. Customer route cannot override owner via a smuggled `customer_id` — ignored;
    booking created for the actor only.

## 8. Test / Lint Result

- **Full suite:** `php artisan test` →
  **335 passed / 792 assertions / 0 failures** (baseline was 319 / 732 — the
  +16 tests / +60 assertions are the new STEP 12 file; all pre-existing
  STEP 01–11 tests pass with zero changes).
- **Lint:** `php -l` clean on every created/modified PHP file
  (`StaffCreateBookingRequest.php`, `BookingPolicy.php`, staff
  `BookingController.php`, `routes/api.php`, `StaffCreateBookingTest.php`).
- No debug or temp files remain.

## 9. Deviations from the Approved STEP 12 Plan

None in scope. Notes on plan-language vs code behavior:

- Plan §17#5/#6 said non-existent/soft-deleted customer was "404 (or 422) —
  assert whichever the implementation produces". The implementation produces
  **422** (the model-backed `customer_id` existence rule rejects before the
  controller's `findOrFail`), and the tests assert 422 + "no booking created".
  The `findOrFail` 404 guard remains in the controller as belt-and-braces.
- The plan (§3) initially listed no new request file, then §7 explicitly
  overrode that by calling for `StaffCreateBookingRequest.php` — that file was
  created exactly as §7 required (a thin isolation layer, not a duplicated
  shape).

## 10. Out-of-Scope Confirmation (STEP 13 untouched)

No `cleaning → available` sweep, no `no_show` automation, no payment/refund
changes, no customer self-booking changes, no inline customer creation, no
attribution migration, no dashboard/reporting. STEP 12 does **not** proceed to
STEP 13.
