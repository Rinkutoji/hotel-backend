# STEP 09 — Booking Engine Plan (review before implementation below)

## 0. Pre-coding Review (read the actual project, not assumptions)

**Schema — complete, no migration needed.** STEP 03 created all three
tables with every field this step needs:

- `bookings`: `booking_number` (unique), `customer_id` (FK,
  `restrictOnDelete`), `check_in_date`/`check_out_date` (date, no time
  component), `status` enum
  `[pending_payment, confirmed, checked_in, checked_out, cancelled, no_show]`,
  `total_amount` decimal(10,2), `payment_deadline_at` (snapshot, per STEP 03
  §9 rule), `cancelled_at`, `cancellation_reason`, default
  `pending_payment`. No `deleted_at` — lifecycle is status transitions.
- `booking_rooms`: `booking_id` (FK cascade), `room_id` (FK restrict),
  `room_type_id` (FK restrict — the approved "snapshot" approach, safe
  because room_types only soft-deletes), `price_per_night` decimal(10,2),
  `actual_check_in_at`/`actual_check_out_at`, unique `(booking_id, room_id)`.
- `payments`: exists, `booking_id` unique (1:1). **Not built this step**
  (STEP 11), but a minimal `Payment` model lands to un-break the existing
  `Booking::payment()` reference (currently a `Class not found` landmine).

**Existing `Booking` model** already has `customer()`, `bookingRooms()`
(→ `BookingRoom::class`, **class does not exist yet**), `payment()`
(→ `Payment::class`, **class does not exist yet**). Both are latent
fatal errors and are created here.

**Room status integration (STEP 07 baseline):** `RoomService::VALID_TRANSITIONS`
currently only covers the two maintenance actions and explicitly deferred
"booking-driven destinations (`reserved`, `occupied`, `cleaning` as a
destination)". This step adds exactly those; `cleaning → available`
stays deferred to STEP 13 as documented.

**Config:** `config/booking.php` already holds `hold_hours` (2) and
`cleaning_buffer_minutes` (15), read only via `config('booking.*')`.
Two new caps join it (see §10). `routes/console.php` already reserves a
comment slot for the hold-expiry sweep — it lands now.

## 1. Scope

**In (working end-to-end MVP):** customer availability check, customer
booking creation with server-side pricing, customer self-cancel of
unpaid bookings, customer list/view of own bookings, staff list/view of
all bookings, staff lifecycle actions (confirm → check-in → check-out,
staff cancel), double-booking protection, hold-expiry rule.

**Out (deliberately):** the Payments module (STEP 11), `no_show` sweep,
`cleaning → available` (STEP 13), staff creating bookings on a
customer's behalf, pricing tiers/discounts, late-cancel fees, refunds,
idempotency keys. Duplicate-submit resilience falls out of the
double-booking check for free (a second create for the same already-held
room fails the overlap check).

## 2. API Contract

| Method | URL | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/public/availability` | None | read-only; returns available rooms + server-computed pricing |
| POST | `/api/v1/customer/bookings` | `auth:sanctum, actor:customer` | create → status `pending_payment` |
| GET | `/api/v1/customer/bookings` | +actor | own only, paginated, status filter |
| GET | `/api/v1/customer/bookings/{booking}` | +actor | 404 unless own |
| POST | `/api/v1/customer/bookings/{booking}/cancel` | +actor | `pending_payment` only |
| GET | `/api/v1/staff/bookings` | `auth:sanctum, actor:staff` | all, paginated, status/range/search filters |
| GET | `/api/v1/staff/bookings/{booking}` | +actor | any staff |
| POST | `/api/v1/staff/bookings/{booking}/confirm` | +`role:admin,manager,receptionist` | pending_payment → confirmed |
| POST | `/api/v1/staff/bookings/{booking}/cancel` | +role | reason required |
| POST | `/api/v1/staff/bookings/{booking}/check-in` | +role | confirmed → checked_in |
| POST | `/api/v1/staff/bookings/{booking}/check-out` | +role | checked_in → checked_out |

All invalid lifecycle transitions → **409 Conflict**
(`BusinessRuleException`), same as Room status. Money fields are never
accepted on any request — pricing is server-side (§5).

## 3. Authorization Matrix

| | Admin | Manager | Receptionist | Customer (own) | Guest |
|---|:---:|:---:|:---:|:---:|:---:|
| Availability (public) | – | – | – | – | ✅ |
| Create booking | – | – | – | ✅ | ❌ |
| List/view own booking | – | – | – | ✅ | ❌ |
| View any booking (staff) | ✅ | ✅ | ✅ | – | ❌ |
| Confirm / check-in / check-out / cancel (staff) | ✅ | ✅ | ✅ | ❌ | ❌ |
| Cancel own unpaid booking | – | – | – | ✅ | ❌ |

**Two-actor `BookingPolicy` (documented departure):** existing policies
type-hint `Employee` only because only staff ever reach them. Bookings
are the first resource **both** actors legitimately reach, so
`BookingPolicy` takes an untyped first `$user` and branches on instance
(`Customer` → ownership check; `Employee` → staff capability). Staff
routes keep the two-layer pattern: route `role:*` middleware first
(`admin,manager,receptionist`), then `$this->authorize()` re-checks the
same rule. Customer ownership is enforced twice: policy + query scoping
(`->where('customer_id', $customer->id)`), so a bad route can't leak
other customers' data.

## 4. Double-Booking Protection (the critical rule)

A room is bookable for `[check_in_date, check_out_date)` iff:

1. Room not soft-deleted and its `room_type` not soft-deleted.
2. `room.status !== 'maintenance'`.
3. No overlapping **holding** booking, where holding =
   `status IN (pending_payment, confirmed, checked_in, checked_out)`
   **and** (for `pending_payment` only) `payment_deadline_at > now()`.
   Excluded entirely: `cancelled`, `no_show`, expired holds.

Overlap uses the half-open interval — `existing.check_in_date < :check_out
AND existing.check_out_date > :check_in` — so same-day checkout → same-day
check-in turnover is allowed (2-night stay `d1..d3`, next guest `d3..d4`:
`3 < 4 ✓`, `3 > 3 ✗` → no conflict).

**Concurrency (why it holds under MySQL):** booking creation runs in a
`DB::transaction` that first issues `SELECT ... FOR UPDATE` on the
requested `rooms` rows (pessimistic row lock — serializes every booking
attempt touching the same room), then runs the overlap check **as another
locking read (`->lockForUpdate()`), not a plain select**. The second point
matters: under `REPEATABLE READ` a plain read would use a stale snapshot
and two racing transactions could both pass the check. Locking reads always
see the latest committed state, so once transaction A commits its hold,
transaction B (blocked at the room lock) sees it on retry and rejects the
overlap. A clear 422-style conflict message ("not available") is returned.

**Test caveat (flagged, not hidden):** sqlite ignores `FOR UPDATE`, so the
row-lock path itself is exercised only functionally in tests (sequential
overlap/cancel/rebook scenarios). The lock is real in MySQL; the overlap
query logic is fully tested in sqlite.

## 5. Server-Side Pricing (never trust the client)

- `nights = check_out_date.diffInDays(check_in_date)` — checkout day is
  not billed.
- Per room: `price_per_night` = **snapshot** of `room.roomType.base_price`
  at booking time (stored in `booking_rooms.price_per_night` +
  `room_type_id`). Future base_price changes never alter existing bookings.
- `bookings.total_amount = Σ over rooms of nights × price_per_night`,
  computed in integer cents then formatted `decimal:2` (no float drift).
- Computed in `BookingService`; no request rule, no validated key, and no
  mass-assignment path accepts a price (requests validate room/date shape
  only; the service reads price from the DB). Test proves a client-sent
  `price`/`total_amount` is silently ignored.

## 6. Booking + Room Lifecycle

`BookingService` transition guards (invalid → 409):

| From | To | Trigger |
|---|---|---|
| `pending_payment` | `confirmed` | staff confirm (± role). Expired hold → auto-cancel first, then 409 |
| `pending_payment` | `cancelled` | customer self-cancel **or** staff cancel |
| `confirmed` | `checked_in` | staff check-in (**requires `today >= check_in_date`**, else 409) |
| `confirmed` | `cancelled` | staff cancel (pre check-in) |
| `checked_in` | `checked_out` | staff check-out |

Terminal: `cancelled`, `checked_out`. `no_show` exists in the enum but is
not implemented (future sweep).

**Room status integration** — single DB transaction around booking status +
all room transitions (`RoomService::transitionStatus` writes the
`room_status_logs` audit rows; they roll back with the transaction if any
room fails):

| Room from | Room to | When |
|---|---|---|
| `available` | `reserved` | booking confirmed |
| `reserved` | `available` | booking cancelled |
| `reserved` | `occupied` | check-in (sets `booking_rooms.actual_check_in_at`) |
| `occupied` | `cleaning` | check-out (sets `actual_check_out_at`) |

Extends `RoomService::VALID_TRANSITIONS` accordingly. **Creating a
`pending_payment` booking does NOT change room status** — the booking row
itself is the hold's source of truth, so unpaid holds never touch
`room.status`/the audit log; availability is driven purely by the bookings
table.

## 7. Hold-Expiry Rule (Architecture Baseline §9)

- `payment_deadline_at = created_at + config('booking.hold_hours')`,
  snapshotted at creation.
- Expired `pending_payment` bookings are treated as **non-holding** by
  the availability/overlap query (rooms implicitly free).
- Staff confirm on an expired booking auto-cancels it first → 409.
- New `php artisan bookings:release-expired-holds` command batch-cancels
  expires (`status=cancelled`, `cancelled_at=now()`,
  `cancellation_reason='Payment deadline passed'`), scheduled hourly in
  `routes/console.php` (the placeholder slot reserved since STEP 02).
  Local caveat documented: needs `schedule:work`/cron on XAMPP.

## 8. Models / Service / Policy

**Models (new):** `BookingRoom` (`$fillable` incl. `price_per_night`,
casts `decimal:2`, datetime casts for `actual_*`; `room()`, `booking()`,
`roomType()` — no SoftDeletes); `Payment` (**minimal only** — fillable +
casts + `booking()`; un-breaks `Booking::payment()`, no endpoints, still
STEP 11's module).

**Model (modified):** `Booking` — fill in `bookingRooms()` (now resolves),
add `rooms()` through `bookingRooms`, keep `status` out of `$fillable`,
add `$hidden = [ ]` none, cast `total_amount/cancelled_at` already present.

**Service (new):** `BookingService` —
`searchAvailable()`, `create()` (transaction + §4 lock + §5 pricing +
`booking_number` = `BK-` + uppercase random, unique-retry ≤3),
`cancelByCustomer()`, `confirm()`, `cancelByStaff()`, `checkIn()`,
`checkOut()`, `releaseExpiredHolds()`. Reuses `RoomService::transitionStatus`.

**Policy (new):** `BookingPolicy` (§3). Controllers: `Public/BookingController`
(availability), `Customer/BookingController` (create/list/show/cancel),
`Staff/BookingController` (index/show/confirm/cancel/check-in/check-out).
Requests: `Booking/AvailabilityRequest`, `Booking/CreateBookingRequest`,
`Booking/FilterRequest` (staff + customer index), `Booking/CancelRequest`
(reason). All extend `ApiFormRequest`.

## 9. Files New / Modified

| File | Change |
|---|---|
| `app/Models/BookingRoom.php` | new |
| `app/Models/Payment.php` | new (minimal, un-breaks `Booking::payment()`) |
| `app/Models/Booking.php` | real `bookingRooms()`, add `rooms()` |
| `app/Services/BookingService.php` | new |
| `app/Services/RoomService.php` | extend `VALID_TRANSITIONS` (§6) |
| `app/Policies/BookingPolicy.php` | new |
| `app/Http/Controllers/Api/V1/{Public,Customer,Staff}/BookingController.php` | new |
| `app/Http/Requests/Booking/{Availability,CreateBooking,Filter,Cancel}Request.php` | new |
| `app/Console/Commands/ReleaseExpiredBookingHolds.php` | new |
| `routes/api.php` | booking routes |
| `routes/console.php` | schedule the hold sweep (hourly) |
| `config/booking.php` + `.env` / `.env.example` | `max_stay_nights`, `max_rooms_per_booking` |
| `docs/STEP-09-IMPLEMENTATION-REPORT.md` | written after implementation |

**No migration.** The schema already satisfies everything; verified against
the actual STEP 03 + STEP 06/07 migration files, and the `DatabaseSchemaTest`
constraints all remain green.

## 10. Config / Validation Highlights

- `check_in_date`: required, date, `after_or_equal:today`.
- `check_out_date`: required, date, `after:check_in_date`.
- Nights = `diffInDays`, capped at `config('booking.max_stay_nights')` = 30
  (chosen default).
- `rooms`: array, 1..`config('booking.max_rooms_per_booking')` = 5,
  `distinct`, each `exists:rooms,id` (active) — validate active + non-
  maintenance in service where the overlap check runs.
- No price/status/payment fields accepted anywhere.

## 11. Test Plan (~45–50 tests)

`tests/Feature/Booking/{AvailabilityTest, CustomerBookingTest,
StaffBookingTest, BookingLifecycleTest, BookingPricingTest,
DoubleBookingTest, HoldExpiryTest}` covering: server-side pricing +
nights (incl. client-sent price ignored, same-day turnover, snapshot
isolation from later base_price changes); availability matrix (overlap,
day-boundary, cancelled/expired-doesn't-block, maintenance-room, deleted
room/type); create validation/golden path (`pending_payment`, deadline =
now+hold, booking_number, booking_rooms snapshots, total); double-booking
reject + cancel-then-rebook + non-overlap allowed; customer ownership
(own-404 for others', list scoping, staff-token→403, guest→401); customer
self-cancel (pending ok, confirmed 409); staff lifecycle (confirm→reserved
+ reserved→occupied→cleaning room transitions + status-log rows, check-in
before date→409, cancel reasons, role matrix admin/manager/receptionist
vs customer denied); hold expiry (expired ≠ holding, command sweep,
confirm-expired→409 + auto-cancel). Existing 178 tests must stay green
(RoomService transition additions are additive).