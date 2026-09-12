# STEP 12 — Staff Booking on a Customer's Behalf (Front-Desk / Walk-In) Plan (review before implementation below)

## 0. Pre-coding Review (read the actual project, not assumptions)

**Verified current state (STEP 01–11, full suite green: 319 tests / 732
assertions / 0 failures).**

The booking lifecycle is fully built through the **customer** path:
customer → create `pending_payment` → staff confirm → check-in →
check-out → (refund). But there is a clear, explicitly-deferred gap on the
**staff** side: a staff member cannot create a booking on a customer's
behalf. This was listed word-for-word in the STEP 09 plan §1 "Out
(deliberately)" list (`docs/STEP-09-BOOKING-PLAN.md:48`):

> "...staff creating bookings on a customer's behalf, ..."

Verified against the actual code:

- **`Staff/BookingController`** has only `index / show / confirm / cancel /
  checkIn / checkOut` — **no `store()` (create) action.** (`routes/api.php`
  staff bookings group has no `POST /`.)
- **`BookingPolicy::create($user)`** (`app/Policies/BookingPolicy.php:38`)
  currently returns `$user instanceof Customer` **only** — a staff member
  is denied. This is the single authorization gap to close.
- **`BookingService::create(array $data, int $customerId)`** is already
  customer-agnostic: it takes the customer id as a parameter and does all
  the work (availability/double-booking `FOR UPDATE` lock, server-side
  pricing, `pending_payment` status, `payment_deadline_at`, `BK-` number,
  `booking_rooms` snapshots). **Reused as-is — no change.**
- **`CreateBookingRequest`** validates shape only (dates, rooms
  existence/models) with `check_in_date after_or_equal:today` and does NOT
  contain any customer key — it is fully reusable for the staff path.
- **`RoomService` / `RoomStatusLog` / hold-expiry / payment** — untouched by
  this step; a staff-created booking is just another `pending_payment`
  booking, so it flows through the identical confirm → check-in → check-out
  → payment → refund pipeline with no new transitions.

**Key design decision — how the customer is chosen:** the staff endpoint
takes an existing `customer_id` (walk-in / front-desk books for a known
guest, or for a guest the front desk just registered). Creating a new
customer via an inline anonymous record is NOT in scope (that would add
customer-creation + password/status complexity and duplicate the STEP 04
registration flow; a front desk guest registers or is registered through the
existing customer path first). This keeps STEP 12 minimal and does not touch
payment/refund behavior.

**Key design decision — booking status:** a staff-created booking is created
`pending_payment` exactly like a customer-created booking (reusing
`BookingService::create`). It then requires the same confirm + payment flow.
This preserves one consistent lifecycle and server-side money rules; there is
no silent "staff books = pre-confirmed" shortcut, because confirmation is
tied to payment verification elsewhere and introducing a second
creation-status path would ripple into STEP 10/11.

## 1. Exact Objective and Scope

**Objective:** Allow an authenticated staff member (admin / manager /
receptionist) to create a booking on behalf of an existing customer,
reusing the complete existing booking engine. This closes the front-desk /
walk-in booking gap.

**In (working end-to-end MVP):**
- Staff creates a booking for an existing `customer_id`, supplying the same
  `check_in_date`, `check_out_date`, and `rooms[]` as the customer flow.
- Booking created `pending_payment` with the same server-side pricing,
  double-booking protection, hold deadline, and snapshot rules.
- Staff can use the existing availability endpoint to check room
  availability first (`POST /public/availability` is public and unchanged).
- RBAC: admin, manager, receptionist all may create (receptionist books at
  the front desk — this is the operational role).
- Customer-actor separation preserved: `customer_id` may only reference an
  existing customer; a staff token cannot act as a customer, and the actor
  middleware still guards the route.

**Out (deliberately):**
- Creating a brand-new customer inline from the booking endpoint (that is a
  customer-account concern; reuse/call the existing customer registration
  flow separately).
- `cleaning → available` buffer sweep — **STEP 13**, explicitly untouched.
- `no_show` automation — out of scope (enum-only, unchanged).
- Payment/refund behavior changes — staff-created bookings use the existing
  STEP 10/11 payment+verify+refund pipeline unchanged.
- Pricing tiers / discounts / late-cancel fees — out of scope.
- Staff creating a booking for *no* customer (anonymous/walk-in with no
  account) — requires a customer row; explicitly out of scope.
- Invoices, notifications, idempotency keys — out of scope.
- Dashboard / reporting / UI — out of scope (not required by this step).

## 2. Existing Modules/Components to Reuse (no duplication)

| Component | How reused | Changed? |
|---|---|---|
| `BookingService::create()` | the staff controller calls it with the selected customer id | **No** |
| `BookingService::searchAvailable()` | front desk availability check (via public endpoint) | **No** |
| `CreateBookingRequest` | reused verbatim as the request validation for the staff create | **No** |
| `Booking` / `BookingRoom` / `Room` models | unchanged, no new schema | **No** |
| `RoomService::VALID_TRANSITIONS` | unchanged — confirm/check-in/check-out still drive room transitions | **No** |
| `PaymentService` (create/verify/refund) | unchanged — a staff-created booking is paid & refunded as any booking | **No** |
| `ApiResponse` / `BusinessRuleException` (409) | standard controller/error envelope | **No** |
| `EnsureActor` / `EnsureStaffHasRole` middleware | route applied `auth:sanctum, actor:staff` + `role:admin,manager,receptionist` | **No** |
| `BookingPolicy` | add a `createForCustomer` (or extend `create`) method for the staff path | **Yes** (additive) |
| `Staff/BookingController` | add a `store()` action | **Yes** (additive) |
| `routes/api.php` | add one staff booking-creat route | **Yes** (additive) |

**Nothing existing is modified semantically.** The only edits are additive:
one new policy method, one new controller action, one new route. Every
existing booking/payment/room transition path is untouched.

## 3. Exact Files to Create

- `docs/STEP-12-IMPLEMENTATION-REPORT.md` (written after implementation).

No new controller, service, request, model, policy, or migration file is
needed — the feature is a thin staff entry point over the existing engine.
(A new dedicated request file is unnecessary because `CreateBookingRequest`
already validates exactly the fields the staff path needs; the request's
`after_or_equal:today` and room existence rules are appropriate for staff-led
booking too.)

## 4. Exact Files to Modify

| File | Change |
|---|---|
| `app/Policies/BookingPolicy.php` | Add staff-capable creation authorization (either extend `create()` to accept `Employee` for the staff path, or add a dedicated `createForCustomer()` method). See §8. |
| `app/Http/Controllers/Api/V1/Staff/BookingController.php` | Add `store(CreateBookingRequest, BookingService)` that resolves the existing customer, authorizes, and delegates to `BookingService::create()`. |
| `routes/api.php` | Add `POST /api/v1/staff/bookings` with `role:admin,manager,receptionist` middleware. |
| `docs/STEP-12-IMPLEMENTATION-REPORT.md` | Written after implementation. |

**No migration.** No schema change is required — `bookings.customer_id`
already references `customers` and the booking engine already accepts any
customer id. Verified against `2025_02_01_000004_create_customers_table.php`
and `2025_02_01_000007_create_bookings_table.php`; `DatabaseSchemaTest`
stays green with zero changes.

## 5. Database / Migration Requirements

**None.** 
- `bookings.customer_id` is a required FK to `customers` (already exists).
- No new columns (no "created_by staff" attribution is added — see §14 for
  why attribution is deliberately limited).
- No new tables.
- No indexes added (staff create hits the same existing queries the customer
  create path already uses).

`DatabaseSchemaTest` must remain unchanged and green.

## 6. API Endpoints

### Staff — `/api/v1/staff/bookings` — group middleware `['auth:sanctum', 'actor:staff']`

| Method | URL | Additional middleware | Notes |
|---|---|---|---|
| POST | `/api/v1/staff/bookings` | `role:admin,manager,receptionist` | Create a booking for an existing customer. Reuses `CreateBookingRequest` + `BookingService::create()`. |

**Request body** (identical shape to the customer create, plus a customer):

```json
{
  "customer_id": 12,
  "check_in_date": "2026-09-20",
  "check_out_date": "2026-09-23",
  "rooms": [3, 5]
}
```

**Response:** 201 with the created booking (relations: `bookingRooms.room`,
`bookingRooms.roomType`, `customer`), same shape as the customer create
response. Message: `"Booking created."`.

The public `POST /public/availability` endpoint is reused unchanged for the
front desk to check availability before creating (no new availability code).

## 7. Request Validation

Reuse `CreateBookingRequest` **as-is**. Its existing rules (verified against
the actual file):

- `check_in_date`: required, `date`, `after_or_equal:today`.
- `check_out_date`: required, `date`, `after:check_in_date`, plus the
  max-nights closure (`config('booking.max_stay_nights')`).
- `rooms`: required, array, min 1, max `config('booking.max_rooms_per_booking')`.
- `rooms.*`: required, integer, distinct, model-backed existence
  (soft-deleted rooms rejected via the model's SoftDeletes scope).

**Add one rule** for the staff path — `customer_id`: required, integer,
`exists:customers,id` (existence among non-deleted customers). This is the
only difference from the customer create (which never takes a customer id —
it derives it from the authenticated actor).

> Implementation note: rather than mutating `CreateBookingRequest` (used by
> both actors) with a conditional `customer_id` rule, the cleanest approach
> is a new thin `StaffCreateBookingRequest extends CreateBookingRequest`
> that adds only the `customer_id` rule. This keeps the shared shape in one
> place and prevents the customer route from ever accepting a `customer_id`
> (which would be a security hole — a customer must not choose whose booking
> they create). A new file `app/Http/Requests/Booking/StaffCreateBookingRequest.php`
> is therefore created (this overrides §3's "no new request" note purely for
> the security-correct isolated rule; the shared date/room rules are
> inherited, not duplicated).

Ownership of `customer_id` is NOT validated in the request shape — §13 covers
the server-side guard.

## 8. Authentication and Authorization / RBAC

- **Route:** `auth:sanctum, actor:staff` + `role:admin,manager,receptionist`
  (receptionist included — front-desk booking is the operational use case,
  matching how confirm/check-in/check-out already grant receptionist).
- **Policy:** a **new** `createForCustomer($user)` method on `BookingPolicy`
  that returns `true` for an `Employee` in `['admin','manager','receptionist']`
  and `false` otherwise. The controller calls
  `$this->authorize('createForCustomer', Booking::class)`.
- **Do NOT reuse/extend `create()` for the staff path** — `create()` is
  customer-only by design (a customer creating a booking for themselves). A
  staff member calling `store()` on the customer route must still get 403
  (actor middleware) and `create()` stays customer-only. Two explicit
  methods (`create` = customer self-book, `createForCustomer` = staff
  front-desk book) is clearer than overloading one, keeps each policy
  intent obvious, and preserves the existing customer `create()` behavior
  exactly.
- **No implicit admin bypass** — roles listed explicitly
  (`admin,manager,receptionist`), matching the existing `EnsureStaffHasRole`
  convention.

## 9. Customer vs Staff Actor Separation

- The staff route is under the `actor:staff` group — a customer token hitting
  `POST /staff/bookings` → 403 (actor middleware). A staff token cannot use
  the customer `POST /customer/bookings` route (there is no staff path there;
  404 for that route shape is not possible since it's a different URI, and
  the customer route's `actor:customer` yields 403 for staff).
- The staff `store()` action does NOT use `$request->user()` as the booking's
  customer — it resolves a **separate explicit `customer_id`** from the
  request body, then confirms that customer exists (via the `exists`
  validation rule + a `Customer::findOrFail` at the controller for a clean
  404). The acting staff member is never conflated with the booking's owner.
- A booking's `customer_id` is the true owner for all downstream ownership
  checks (customer payment, customer view, customer cancel) — unchanged from
  STEP 09.

## 10. Service / Business Logic

`Staff/BookingController::store(CreateBookingRequest $request, BookingService $service)`:

1. `$this->authorize('createForCustomer', Booking::class)` — RBAC gate.
2. Resolve the target customer: `Customer::findOrFail($request->validated('customer_id'))`
   → clean 404 if missing/deleted. (Validation `exists` already filtered it;
   `findOrFail` is belt-and-braces + guarantees a typed model.)
3. `$booking = $service->create($request->validated(), $customer->id);`
   — **`BookingService::create()` is reused verbatim.** It performs the
   existing whole engine: transaction + `SELECT ... FOR UPDATE` on rooms in
   ascending id order, overlap re-check as a locking read, server-side
   integer-cents pricing, `pending_payment` status, `payment_deadline_at`,
   unique `BK-` number, and `booking_rooms` snapshots. No change to this
   method.
4. Return `ApiResponse::success($booking, 'Booking created.', 201)`.

**No new service methods.** The feature is purely a controller + policy +
request + route addition over the unchanged `BookingService`.

## 11. State Transitions

**None new.** A staff-created booking is created `pending_payment` and then
follows the identical, already-implemented lifecycle:

```
pending_payment ──staff confirm──▶ confirmed ──check-in──▶ checked_in ──check-out──▶ checked_out
      │                                                                                  │
      └──customer/staff cancel / expired-hold sweep──▶ cancelled                         (rooms→cleaning = STEP 13)
```

- Creating the booking (`pending_payment`) does **not** change room status
  (the booking row is the hold's source of truth — STEP 09 behavior). This
  is already how the customer path works; the staff path inherits it.
- All subsequent transitions (`pending_payment → confirmed` via confirm OR
  payment verify, `confirmed → checked_in`, `checked_in → checked_out`,
  cancellation, refund) are the existing STEP 09/10/11 paths — untouched.

## 12. Transaction Boundaries and Concurrency Considerations

- The transaction boundary is entirely inside the reused
  `BookingService::create()` (its own `DB::transaction` with the
  `FOR UPDATE` room locks and locking-read overlap check). No new
  transaction is needed in the controller.
- The controller resolves the `Customer` and authorizes **before** calling
  into `create()`; `create()` then runs its atomic transaction. There is no
  cross-entity transaction to manage here.
- Concurrency: the same double-booking protection the customer path already
  has applies with zero extra work — two concurrent staff/customer bookings
  for the same room/dates are serialized by the room row lock; the second
  sees the overlapping hold and gets the standard 409 "not available".
- sqlite ignores `FOR UPDATE` (as in STEP 09); the overlap logic is
  functionally covered by existing + new tests; the row lock is real in
  MySQL. Same documented caveat.

## 13. Ownership / Security Rules

- **Staff chooses the customer:** `customer_id` is a required, validated
  `exists:customers,id` value. The controller re-checks existence with
  `findOrFail` → 404 for a missing/deleted customer.
- **A customer cannot set their own `customer_id`:** the customer create
  path never accepts a `customer_id` (its `CreateBookingRequest` has no such
  field, and `BookingService::create()` receives the authenticated actor's
  id). The staff-only `customer_id` rule lives in the isolated
  `StaffCreateBookingRequest`, so a customer can never choose a different
  owner for their booking.
- **A staff member cannot impersonate a customer via this route:** the
  route is `actor:staff`-guarded; the booking owner is the body-specified
  customer, not the acting staff member.
- **Data exposure:** the response returns the booking with its customer —
  this is staff-visible data (staff already view all bookings), not a
  privacy leak.
- **Money integrity:** pricing is server-side (`BookingService::create`
  reads `base_price` from the DB); the staff request carries no price or
  amount field; `Booking::$fillable` excludes `status`/pricing still.
- **`customer_id` in `Booking::$fillable`:** unchanged — it is already
  there and is set by `BookingService::create`, never from the customer
  request. For the staff path it is set to the validated, staff-chosen
  customer, which is the intended semantics.

## 14. Audit Logging Requirements

- `room_status_logs`: a staff-created `pending_payment` booking writes **no
  room transition** (rooms are not reserved until confirm). This matches the
  existing STEP 09 rule and is correct — no room status/audit row is
  expected at creation time.
- The booking row itself (`created_at`, `booking_number`) is the creation
  audit record — consistent with how customer-created bookings are audited.
- **No new attribution column** ("who created this" staff id) is added.
  Adding `created_by` would require a migration and a `$fillable`/policy
  change, and it is not required by the Architecture Baseline for this
  university-scope project. The front-desk booking's provenance is captured
  adequately by the booking row + the existing `room_status_logs`
  (which DO record the staff member via `changed_by` once confirm/check-in
  run). This keeps the schema unchanged.

## 15. Error Responses / Status Codes

Consistent with the whole API (configured in `bootstrap/app.php`:

- **201** — booking created (`ApiResponse::success`, message "Booking created.").
- **401** — unauthenticated guest.
- **403** — customer token on the staff route (actor); staff/receptionist-
  role mismatch (role). Also 403 for a receptionist only if their role were
  somehow excluded — but they are included, so the realistic 403 is the
  customer actor.
- **404** — non-existent/soft-deleted `customer_id` (`findOrFail`), and for
  the usual missing-route binding on other booking actions.
- **409** — `BusinessRuleException` from `BookingService::create` for
  overlap/maintenance/soft-deleted room ("not available"), max-nights, etc.
- **422** — validation failures in `StaffCreateBookingRequest` /
  `CreateBookingRequest` (invalid dates, room ids, missing/unknown
  `customer_id`, over max rooms/nights).

All error shape via the `{success, message, errors}` envelope.

## 16. Edge Cases

- **Overlapping dates for the chosen rooms** → 409 (existing double-booking
  guard). Already covered by the reused engine.
- **Room in maintenance / soft-deleted room / soft-deleted room type** →
  409 (engine guard).
- **Missing or soft-deleted customer** → 404 before create.
- **`customer_id` referencing a customer with no account token** (e.g. a
  walk-in registered but not logged in) → fine; ownership is by `customer_id`,
  not token presence.
- **Same room/dates raced by a customer *and* staff concurrently** → 409,
  serialized by `FOR UPDATE` (STEP 09 behavior).
- **Booking for dates already in the past via staff** → blocked by
  `after_or_equal:today` in the reused request.
- **Customer attempts to send `customer_id` on the customer route** →
  ignored (shape has no such field) — no injection of ownership.
- **Staff tries to book for a non-existent customer id** → 422 (validation)
  then 404 (`findOrFail`) — clean, no partial state.
- **Staff creates many bookings quickly** → booking-number unique retry
  (≤3) already handles collision; identical to customer path.

## 17. Test Strategy — Exact Scenarios

New file `tests/Feature/Booking/StaffCreateBookingTest.php` (~14–16 tests):

1. **Golden path:** admin creates a booking for an existing customer → 201,
   `status=pending_payment`, `customer_id` = chosen customer,
   `payment_deadline_at` ≈ now + hold, room status unchanged (available),
   snapshot `booking_rooms` rows.
2. **Manager and receptionist can both create** → 201 for each role.
3. **Customer token on the staff route → 403.**
4. **Guest → 401.**
5. **Booking for a non-existent customer_id → 404** (or 422 at validation;
   assert whichever the implementation produces — 422 from `exists` rule, with
   a note; keep both covered by asserting the booking is NOT created).
6. **Booking for a soft-deleted customer → 404/not created.**
7. **Server-side pricing:** client-sent `total_amount`/`price` ignored; total
   = nights × base_price snapshot.
8. **Overlapping dates on the chosen room → 409; no booking created**
   (atomic rollback verified).
9. **Non-overlapping consecutive stay on the same room is allowed → 201.**
10. **Check-in/out date validation** (check_out ≤ check_in, past check-in,
    over max nights) → 422.
11. **Maintenance room rejected → 409.**
12. **Double room ids / non-existent room id → 422.**
13. **The created booking is immediately viewable by staff (`GET /staff/
    bookings/{id}`) and does not appear in another customer's list.**
14. **A staff-created booking flows into the normal pipeline:** create (staff)
    → staff confirm → room reserved + log row → (optionally) payment verify
    confirms atomically — proving no divergence from the STEP 09/10 paths.
15. **Customer cannot see another customer's staff-created booking in their
    own list** (ownership returns 404 for unrelated owner) — confirms the
    staff-chosen owner is respected.
16. **Request cannot override the target owner via a body `customer_id` on
    the CUSTOMER route** (customer create ignores it / never accepts it).

## 18. Regression Protection for STEP 01–11

- **Baseline:** 319 tests / 732 assertions. Must stay green after STEP 12
  with **zero changes** to existing passing tests.
- `BookingService::create()`, `BookingService::searchAvailable()`,
  `PaymentService`, `RoomService::VALID_TRANSITIONS`, and all models are
  **untouched** — the corresponding STEP 09/10/11 suites are unaffected.
- `BookingPolicy::create()` (customer path) is **unchanged** —
  `CustomerBookingTest` ownership/403/401 tests stay green.
- `Staff/BookingController` existing `index/show/confirm/cancel/checkIn/
  checkOut` are **unchanged** — `StaffBookingTest` / `BookingLifecycleTest`
  stay green.
- Adding a `store()` action + one route + one policy method is purely
  additive; no existing route, action, or policy behavior changes.
- `DatabaseSchemaTest` unchanged (no migration).

## 19. Explicit OUT-OF-SCOPE (final checklist)

- ❌ `cleaning → available` buffer sweep — **STEP 13**.
- ❌ `no_show` automation.
- ❌ Any change to payment create/verify/reject/refund behavior.
- ❌ Any change to customer self-booking behavior (`BookingPolicy::create`).
- ❌ Inline customer registration/creation from the booking endpoint.
- ❌ Pricing tiers / discounts / late-cancel fees.
- ❌ Staff cancel/check-in/check-out (already present in STEP 09).
- ❌ Creating a booking with no customer (true anonymous walk-in).
- ❌ A `created_by` attribution migration.
- ❌ Invoices, notifications, dashboard, reporting, UI.
- ❌ Idempotency keys.

## 20. Wait for Approval

Plan only. No implementation until approved.
