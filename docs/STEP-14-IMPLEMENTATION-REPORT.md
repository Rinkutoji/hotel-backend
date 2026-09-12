# STEP 14 — No-Show Automation — Implementation Report

Plan is in `docs/STEP-14-PLAN.md` (approved; business rules in §2, schema
decision in §3 — "minimal migration", command design in §4, test matrix in
§12). This covers what was actually built. STEP 01–13 behavior is unchanged.

---

## 1. Files Created

- `database/migrations/2025_02_08_000001_add_no_show_at_to_bookings_table.php`
  — adds a nullable `no_show_at` timestamp to `bookings` (placed after
  `cancellation_reason`; `after()` is ignored on sqlite, tests use RefreshDatabase
  against in-memory sqlite). Applied to the XAMPP MySQL dev DB via
  `php artisan migrate`.
- `app/Console/Commands/MarkNoShowBookings.php` — Artisan command
  `bookings:mark-no-show` ("Mark confirmed bookings whose check-in date has
  passed as no-show"). A thin CLI wrapper over `BookingService::markNoShowBookings()`
  that prints `Marked {N} booking(s) as no-show.` and returns `SUCCESS` —
  mirrors `ReleaseExpiredBookingHolds` (STEP 09) and `CompleteRoomCleaning`
  (STEP 13): all business logic lives in the service, the command is a leaf.
- `tests/Feature/Booking/NoShowSweepTest.php` — 16 new tests (79 assertions).
- `docs/STEP-14-IMPLEMENTATION-REPORT.md` (this file).

## 2. Files Modified

- `app/Models/Booking.php` — `no_show_at` added to `$fillable` (plan §5) and to
  the `$casts` as `datetime`. No request accepts `no_show_at` (no mass-assignment
  path) — it is only ever written by the service sweep.
- `app/Services/BookingService.php`:
  - **`releaseReservedRooms(Booking $booking, string $reason)`** — the private
    helper gained a `reason` argument; it still only touches rooms whose
    `room->status === 'reserved'` and calls `transitionRoom($room, 'available', $reason)`.
    `applyCancellation()` now calls it with `'Booking cancelled'` and the
    duplicate release loop inside `refundBooking()` was collapsed into a single
    call with `'Refunded by staff: ' . $reason` — identical room/audit behavior
    as before (verify by the unchanged STEP 09/11 tests, all green).
  - **`markNoShowBookings(): int`** (plan §4): candidate ids are
    `status = 'confirmed'` AND `check_in_date < now()->startOfDay()->toDateString()`
    (strictly before today — the whole check-in day stays open for a late
    check-in, BR-2), ordered by id. Each candidate is handled in its own
    `DB::transaction` that re-reads the row and re-checks `status === 'confirmed'`
    before applying (BR-6 race guard: a staff check-in that lands between the
    select and the transaction is never clobbered). Returns the number marked.
  - **`applyNoShow(Booking $booking)`** (private): sets `status = 'no_show'`,
    `no_show_at = now()`, saves, then calls
    `releaseReservedRooms($booking, 'Guest did not check in')` so every reserved
    room goes `reserved → available`. `no_show` stays terminal — the existing
    guard re-checks in confirm/cancel/check-in/check-out already make every
    invalid transition a 409, confirmed by test.
- `routes/console.php` — registered
  `Schedule::command('bookings:mark-no-show')->hourly();` (cron `0 * * * *`)
  next to the hold-expiry sweep, with the same local XAMPP caveat
  (`schedule:work` dev / cron prod required to actually run).

**Schema change (the only one in STEP 14):** the single nullable
`bookings.no_show_at` column. `DatabaseSchemaTest` does not enumerate bookings
columns and stays green.

**No change to** `BookingService::create()`, `searchAvailable()`, confirm,
cancel, check-in/check-out booking logic beyond the helper refactor above,
`PaymentService`, `RoomService`, any controller/FormRequest/Policy, or any
endpoint. The `no_show` status was already in `Booking::STATUSES` (STEP 09);
`FilterRequest` already whitelists `no_show` (staff `?status=no_show` list works
with zero code change); `STATUSES_HOLDING` excludes `no_show`, so a swept room
is immediately re-bookable. There is **no auto-refund**: refunds stay a
staff-initiated, admin/manager-only action (STEP 11) — money never moves without
an authenticated actor (M-4).

## 3. Scheduler

| Command | Signature | Cadence |
|---|---|---|
| `bookings:mark-no-show` | `handle(BookingService)` → `markNoShowBookings(): int` | `hourly()` |

Registered in `routes/console.php` (Laravel 11 scheduler home, matching the
hold-expiry precedent). Run directly as `php artisan bookings:mark-no-show`.
Idempotent (BR-7): a missed/throttled tick is self-healing — the next run finds
the still-`confirmed` and still-`reserved` rows. Local caveat (same as STEP 09/
13): needs `schedule:work` (dev) or cron (prod) on XAMPP.

## 4. No-Show Behavior

- **Eligibility:** `status = 'confirmed'` and `check_in_date < today` (strict).
  Same-day arrivals are never swept, even late at night — they get the whole
  check-in day. Tomorrow's arrivals are never swept.
- **Room release:** every `reserved` room of the booking goes to `available`
  via `releaseReservedRooms`, which locks onto the `room.status === 'reserved'`
  condition — a room already `available`/`maintenance` is left alone (verified:
  a confirmed booking whose room is not reserved is still marked `no_show`, with
  zero room touches). Multi-room bookings release exactly the reserved subset.
- **Race guard (BR-6):** per-booking transaction re-reads the booking and
  re-checks `status === 'confirmed'` before applying. Staff check-in winning the
  race leaves that booking `checked_in` and its room `occupied`, untouched by the
  sweep (tested).
- **Idempotency:** only `confirmed` rows are candidates; `no_show` rows are
  never re-selected. Double runs produce `Marked 1` then `Marked 0` with exactly
  one release audit row (tested).
- **Audit:** released rooms log `from_status=reserved, to_status=available,
  changed_by=null, reason='Guest did not check in'` — the null-`changed_by`
  convention for system/booking-driven transitions (same as the refund and
  cleaning sweeps).
- **Terminal:** confirm / staff-cancel / check-in / check-out on a `no_show`
  booking each return 409; customer self-cancel returns 409 and leaves the
  booking `no_show`.

## 5. Test Strategy — Result

New `tests/Feature/Booking/NoShowSweepTest.php` (16 tests / 79 assertions),
scenario 1–14 from plan §12 plus standalone split-outs for the two-actor
legs:

1. Yesterday-confirmed booking → `no_show`, `no_show_at` stamped, room
   `available`, output `Marked 1`.
2. Same-day stays `confirmed` (frozen clock, `try/finally` travelBack) →
   `Marked 0`, room stays `reserved`.
3. Future-dated confirmed booking never marked.
4. Status matrix: only `confirmed` flipped; `pending_payment` (expired and not),
   `checked_in`, `checked_out`, `cancelled`, `no_show` untouched → `Marked 1`.
5. Multi-room (2 rooms) full release + 2 audit rows, reason
   `'Guest did not check in'`, `changed_by` null.
6. Swept room re-bookable: public availability shows it for future dates and a
   customer booking on it returns 201.
7. Idempotent double run: `Marked 1` / `Marked 0`, one release audit row.
8. Race guard: second candidate pre-flipped to `checked_in` (+ room `occupied`)
   is never touched.
9. Terminal on every staff lifecycle endpoint (409 each, standalone staff test).
10. Owning customer cancel → 409 (standalone customer test).
11. Staff list `?status=no_show` → 200, booking present.
12. Owning customer list shows the `no_show` booking (standalone customer test).
13. `bookings:mark-no-show` registered in the scheduler at `0 * * * *`.
14. Mixed candidates → `Marked 2`; today/future/pending untouched.
15. Confirmed booking whose room is already `available` → still marked, no room
    transition, no audit row.
16. Multi-room partial release: only the `reserved` room released (one audit row).

## 6. Test / Lint Result

- **Full suite:** `php artisan test` →
  **371 passed / 958 assertions / 0 failures** (baseline before STEP 14 was
  355 / 879 — the +16 tests / +79 assertions are the new STEP 14 file; all
  STEP 01–13 tests pass with zero changes).
- **Lint:** `php -l` clean on every created/modified PHP file
  (`BookingService.php`, `Booking.php`, `MarkNoShowBookings.php`,
  `routes/console.php`, `2025_02_08_000001_add_no_show_at_to_bookings_table.php`,
  `NoShowSweepTest.php`).
- **Dev DB:** `php artisan migrate` applied `2025_02_08_000001_add_no_show_at_to_bookings_table`
  to the XAMPP MySQL database (tests validate the same migration via
  RefreshDatabase on sqlite).
- No debug or temp files remain.

## 7. Deviations from the Approved STEP 14 Plan

None in scope. Two test-implementation notes:

- The plan (§12) listed customer-cancel-409, staff-list and customer-list as
  legs of the mixed two-actor scenarios. Laravel's test client caches the
  authenticated user between HTTP requests within one test (a staff request
  followed by a customer request on the same test case keeps resolving as staff —
  the defining symptom was a 403 "requires a customer account" while the request
  presented a valid customer bearer token). Rather than rely on that cache or
  thread `Auth::forgetGuards()` through every test, the two-actor legs were
  split into standalone tests (staff-only / customer-only), matching the
  established pattern from STEP 07 T13 and STEP 13. Identical coverage, zero
  flakiness.
- Test 6 books the room on **future** dates (the swept booking's own dates are
  already past). The plan's phrasing "same/future dates" is satisfied modulo
  this — a no-showed booking is by definition one whose check-in already
  elapsed.

## 8. Out-of-Scope Confirmation

No auto-refund (still staff-initiated, admin/manager, STEP 11); no payments
change; no notifications / dashboard / reporting / UI; no new
endpoints / controllers / FormRequests / policies (the `no_show` status was
already listable via `FilterRequest`); no `config/booking.php` key; no update
to the manual front-desk flow. STEP 14 does **not** proceed to STEP 15.