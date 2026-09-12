# STEP 09 — Booking Engine — Implementation Report

Plan is in `docs/STEP-09-BOOKING-PLAN.md` (written first). This covers
what was actually built on top of it and the two plan-conformance fixes made
during final verification.

---

## 1. Files Created / Modified

**Created:**
- `app/Models/BookingRoom.php` — `$fillable` incl. `price_per_night`,
  casts (`decimal:2`, datetime `actual_*`), `booking()`/`room()`/`roomType()`,
  no SoftDeletes.
- `app/Models/Payment.php` — minimal model to un-break `Booking::payment()`
  (later real module of STEP 10; the STEP 03 `payments` schema already
  existed, no migration).
- `app/Services/BookingService.php` — `searchAvailable()`, `create()`
  (transaction + §4 lock + §5 pricing + `BK-` booking number with unique
  retry ≤3), `cancelByCustomer()`, `confirm()`, `cancelByStaff()`,
  `checkIn()`, `checkOut()`, `releaseExpiredHolds()`; reuses
  `RoomService::isTransitionValid`.
- `app/Policies/BookingPolicy.php` — two-actor policy (`Customer` → ownership,
  `Employee` → staff capability).
- `app/Http/Controllers/Api/V1/Public/BookingController.php` — availability.
- `app/Http/Controllers/Api/V1/Customer/BookingController.php` — create/list/
  show/cancel (ownership enforced via policy + customer_id query scoping).
- `app/Http/Controllers/Api/V1/Staff/BookingController.php` — index/show/
  confirm/cancel/check-in/check-out.
- `app/Http/Requests/Booking/{Availability,CreateBooking,Filter,Cancel}Request.php`
  — all extend `ApiFormRequest`, validate shape only, never accept money
  fields.
- `app/Console/Commands/ReleaseExpiredBookingHolds.php` —
  `bookings:release-expired-holds`.
- `tests/Feature/Booking/` — 7 files, 66 tests (details in §5).
- `docs/STEP-09-IMPLEMENTATION-REPORT.md` (this file).

**Modified:**
- `app/Models/Booking.php` — real `bookingRooms()` (now resolves),
  `rooms()` through `bookingRooms`, `isExpired()`, status kept out of
  `$fillable`.
- `app/Services/RoomService.php` — `VALID_TRANSITIONS` extended with the
  booking-driven edges: `available→reserved`, `reserved→available`,
  `reserved→occupied`, `occupied→cleaning`. `cleaning→available` stays
  deferred to STEP 13 as documented.
- `config/booking.php` + `.env` / `.env.example` — `max_stay_nights`
  (30), `max_rooms_per_booking` (5).
- `routes/api.php` — 12 booking routes (1 public, 4 customer, 7 staff).
- `routes/console.php` — hourly `bookings:release-expired-holds` schedule.

**No migration.** Verified against the actual STEP 03 + STEP 06/07 migration
files — the `bookings`/`booking_rooms` schema already satisfied every
requirement (`DatabaseSchemaTest` stays green).

## 2. API Endpoints

| Method | URL | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/public/availability` | None | read-only; available rooms + server-computed price preview |
| GET | `/api/v1/customer/bookings` | `auth:sanctum, actor:customer` | own only, paginated, `?status=` filter |
| POST | `/api/v1/customer/bookings` | +actor | create → `pending_payment` |
| GET | `/api/v1/customer/bookings/{booking}` | +actor | 404 unless own |
| POST | `/api/v1/customer/bookings/{booking}/cancel` | +actor | `pending_payment` only |
| GET | `/api/v1/staff/bookings` | `auth:sanctum, actor:staff` | all, `?status=&from=&to=&search=` |
| GET | `/api/v1/staff/bookings/{booking}` | +actor | any staff |
| POST | `/api/v1/staff/bookings/{booking}/confirm` | +`role:admin,manager,receptionist` | `pending_payment → confirmed` |
| POST | `/api/v1/staff/bookings/{booking}/cancel` | +role | reason; `pending_payment`/`confirmed` only |
| POST | `/api/v1/staff/bookings/{booking}/check-in` | +role | `confirmed → checked_in` (requires today ≥ check-in date) |
| POST | `/api/v1/staff/bookings/{booking}/check-out` | +role | `checked_in → checked_out` |

All invalid lifecycle transitions → **409** (`BusinessRuleException`), matching
the Room status convention. Money fields are never accepted on any request —
pricing is server-side only.

## 3. Business Behavior

- **Double-booking protection (§4):** bookable iff room not soft-deleted AND
  its `room_type` not soft-deleted, `status !== 'maintenance'`, and no
  overlapping *holding* booking (`pending_payment`/`confirmed`/`checked_in`/
  `checked_out`, and for `pending_payment` only while
  `payment_deadline_at > now()`). Half-open interval
  `existing.check_in_date < :check_out AND existing.check_out_date > :check_in`
  so same-day checkout→check-in turnover works.
- **Concurrency:** `create()` runs in a `DB::transaction` that first issues a
  `SELECT ... FOR UPDATE` on the requested `rooms` rows in **deterministic
  ascending id order** (serializes concurrent attempts, avoids deadlock), then
  the overlap check runs as another **locking read** (`lockForUpdate()`), so
  under `REPEATABLE READ` a racing hold that already committed is always seen.
  (sqlite ignores `FOR UPDATE` — the overlap logic is functionally covered in
  tests; the row-lock itself is real only on MySQL.)
- **Server-side pricing (§5):** `nights = check_out_date.diffInDays(check_in_date)`
  (checkout day not billed), per room `price_per_night` is a snapshot of
  `room.roomType.base_price` (stored on `booking_rooms` with `room_type_id`),
  **accumulated in integer cents** then formatted `decimal:2` — no float
  drift (a later `base_price` change never alters an existing booking). Client-
  sent `price`/`total_amount` are ignored (proven by test).
- **Room integration (§6):** creating a `pending_payment` booking never touches
  room status — the booking row is the hold's source of truth. Confirm
  → `available→reserved`, cancel → `reserved→available`, check-in →
  `reserved→occupied` (+ `actual_check_in_at`), check-out → `occupied→cleaning`
  (+ `actual_check_out_at`), each transition writing a `room_status_logs` audit
  row inside the same transaction.
- **Hold expiry (§7):** `payment_deadline_at` snapshotted at creation
  (`created_at + config('booking.hold_hours')`). Expired holds are
  non-holding for availability/overlap; staff confirm auto-cancels first then
  returns 409; the hourly sweep command cancels them in batches.

## 4. Conformance Fixes Applied During Final Verification

Two deviations from the approved plan were found in the pre-existing code and
corrected; both are additive/behavior-preserving except as intended:

1. **Integer-cents pricing (§5).** `BookingService::create` originally summed
   floats and formatted with `number_format`. Now every price is converted to
   integer cents once (`round(base_price * 100)`), accumulated as integers,
   and rendered with an integer-only `formatCents()` helper (`sprintf`).
   Output for whole-number prices is unchanged (all existing tests green).
2. **Staff-cancel guard (§6).** `cancelByStaff` originally allowed cancelling
   *any* non-terminal status (including `checked_in`), which would leave a room
   stuck `occupied` with no active booking. It now permits only
   `pending_payment` and `confirmed`, matching the plan's transition table.

## 5. Tests Added (66 in `tests/Feature/Booking/`)

- **AvailabilityTest (19):** golden path + server price, overlap blocked,
  same-day turnover allowed, cancelled/expired-doesn't-block, active hold
  blocks, maintenance room excluded, soft-deleted room and soft-deleted
  room-type excluded, sibling isolation, date/room validation, duplicate ids.
- **CustomerBookingTest (17):** create golden path (`pending_payment`,
  deadline = now + hold, `BOOKING_NUMBER` format, booking_rooms snapshots),
  room status unchanged by create, validation caps, own/other-ownership 404s,
  list scoping + status filter, staff-token→403, guest→401, self-cancel
  (pending ok, confirmed 409).
- **StaffBookingTest (14):** list all + pagination, status/search filters,
  view any, role matrix (admin/manager/receptionist confirm), customer→403,
  guest→401, confirm→`reserved` + audit log, cancel reasons, confirmed-cancel
  returns rooms, terminal/checked-in cancel→409 (new guard test), early
  check-in→409, non-confirmed check-in→409.
- **BookingLifecycleTest (4):** full create→confirm→check-in→check-out with
  room transitions, timestamp snapshots and 3 audit-log rows; confirmed-cancel
  before check-in returns rooms; invalid check-out / re-check-in → 409.
- **BookingPricingTest (7):** nights × base price (checkout day not billed),
  snapshot isolation from later `base_price` change, multi-room sums,
  mixed room types, client-sent price ignored, fractional `99.99 × 3 = 299.97`
  accumulated in cents (new test).
- **DoubleBookingTest (5):** overlapping create (pending and confirmed) → 409,
  consecutive non-overlap allowed, cancel-then-rebook allowed, availability
  and create agree.
- **HoldExpiryTest (5):** expired hold non-holding, sweep command cancels only
  expired, hourly schedule, confirm-expired → 409 + auto-cancel, customer
  cannot self-cancel an expired booking.

## 6. Full Test Results

- Full suite: **300 passed (675 assertions)**, 0 failures (~2.6s, sqlite
  in-memory). 234 pre-existing STEP 02–08 tests stayed green + 66 STEP 09
  tests.
- `php -l` clean on all created/modified PHP files (24 files touched this
  step). No temporary/debug files.
- `DatabaseSchemaTest` (incl. bookings/booking_rooms non-soft-delete and
  constraint checks) stays green — confirming the no-migration decision.

## 7. Remaining Issues / Notes

- **`no_show` is enum-only.** The status exists in the schema but no sweep or
  lifecycle path implements it — explicitly out of scope, same as the plan.
- **`cleaning → available` deferred to STEP 13.** `occupied→cleaning` is built;
  the scheduled buffer sweep is not.
- **Payments are a separate step (STEP 10).** Nothing payment-related was
  added here; the existing STEP 10 module (present in the repo) is untouched
  and its 56 tests stay green.
- **Hold sweep needs a scheduler.** On XAMPP the registered hourly schedule
  requires `php artisan schedule:work` (or cron); documented caveat, unchanged.
- **MySQL lock path is not exercised by tests** (sqlite ignores `FOR UPDATE`) —
  the overlap query logic is fully tested; the pessimistic row-lock itself is
  only in effect on the production MySQL connection.