# STEP 13 — Room Cleaning / Turnover Automation — Implementation Report

Plan is in `docs/STEP-13-IMPLEMENTATION-PLAN.md` (approved, 24 sections). This
covers what was actually built on top of it. STEP 01–12 behavior is unchanged;
STEP 14 is untouched.

---

## 1. Files Created

- `app/Console/Commands/CompleteRoomCleaning.php` — Artisan command
  `rooms:complete-cleaning` ("Transition rooms in cleaning past the buffer to
  available"). A thin CLI wrapper over `RoomService::completeReadyCleaning()`
  that prints `Cleaned {N} room(s) ready to leave cleaning.` and returns
  `SUCCESS` — mirrors the `ReleaseExpiredBookingHolds` (STEP 09) pattern: all
  business logic lives in the service, the command is a leaf.
- `tests/Feature/Room/RoomTurnoverTest.php` — 20 new tests (87 assertions).
- `docs/STEP-13-IMPLEMENTATION-REPORT.md` (this file).

## 2. Files Modified

- `app/Services/RoomService.php`:
  - `VALID_TRANSITIONS['cleaning']` widened from `['maintenance']` to
    `['maintenance', 'available']` — the single source of truth (plan §9). No
    other edge changed: live rooms (reserved/occupied/maintenance/available)
    still have no path to `available` except the existing
    reserved→available and maintenance→available rules.
  - Added `completeReadyCleaning(): int` (plan §6/§13/§14): computes
    `$cutoff = now() - config('booking.cleaning_buffer_minutes')`; selects
    candidate ids (`status = 'cleaning'` AND
    `COALESCE(cleaning_started_at, status_changed_at) <= $cutoff`, ordered by
    id); then per room runs a `DB::transaction` that re-reads the row with
    `lockForUpdate()`, skips if it vanished or is no longer `cleaning`, re-checks
    eligibility against the locked row (`$timer = cleaning_started_at ??
    status_changed_at`; skip if null or `> cutoff`), otherwise calls the existing
    `transitionStatus($room, 'available', null, 'Cleaning period complete')`
    and increments the count. Returns the count.
  - Imported `Illuminate\Support\Facades\DB`.
- `app/Services/BookingService.php` — private `transitionRoom()` (lines ~328):
  now also sets `$room->cleaning_started_at = now()` when `$toStatus ===
  'cleaning'`. This is the single place a room enters `cleaning` today
  (check-out), so it is the sole stamp site. `cleaning_started_at` is NOT in
  `Room::$fillable` — set via explicit property assignment, same pattern as
  `status`. This was the agreed minimal fix for the schema/repo divergence
  (column existed since STEP 03, never written originally).
- `routes/console.php` — registered
  `Schedule::command('rooms:complete-cleaning')->everyMinute();` next to the
  existing hourly hold sweep. Replaced the "not registered yet" placeholder
  comment block with a STEP 13 explanation and kept the local XAMPP caveat
  (`schedule:work` / cron required to actually run).

**No migration. No schema change.** `rooms.cleaning_started_at` and
`rooms.status_changed_at` were both already nullable columns from
`2025_02_01_000005_create_rooms_table.php`. `DatabaseSchemaTest` is unchanged
and green.

**No change to** `BookingService::create()`, `searchAvailable()`, confirm,
cancel, check-in/check-out booking logic (other than the single timestamp
stamp), `PaymentService`, any model, RoomPolicy, RooomStatusController,
FormRequests, or any existing endpoint. The manual early-complete path reuses
the existing `POST /api/v1/staff/rooms/{room}/status/available` (STEP 07).

## 3. Scheduler

| Command | Signature | Cadence |
|---|---|---|
| `rooms:complete-cleaning` | `handle(RoomService)` → `completeReadyCleaning(): int` | `everyMinute()` |

Registered in `routes/console.php` (Laravel 11 — no `app/Console/Kernel.php`,
routes/console.php is the scheduler home; matches the `bookings:release-expired-holds`
precedent). Run from CLI directly as `php artisan rooms:complete-cleaning`.
Idempotent, so a missed/throttled tick only delays turnover, never corrupts
state. Local caveat (same as STEP 09): needs `schedule:work` (dev) or cron
(prod) on XAMPP.

## 4. Room Transition Behavior

- **Eligibility:** `status = 'cleaning'` and
  `COALESCE(cleaning_started_at, status_changed_at) <= now - cleaning_buffer_minutes`
  (15 min via `config('booking.cleaning_buffer_minutes')`). Primary timer is
  the checkout-stamped `cleaning_started_at`; `status_changed_at` is the
  fallback for legacy rows that predate the stamp; a row with BOTH null never
  matches (`COALESCE` is null) and is safely skipped — it can still be
  late-completed manually.
- **Guardrails:** hard `status = 'cleaning'` filter means reserved, occupied,
  maintenance and available rooms are never candidates regardless of timestamps
  (verified by test). Maintenance interrupt still works and a maintenance room
  is never un-interrupted by the sweep. Soft-deleted rooms are excluded by the
  SoftDeletes global scope (candidate query + `findOrFail`-style auth in the
  controller) and by the sweep's `$room === null` re-check.
- **Idempotency:** only `cleaning` rooms are selected; each room transitions
  exactly once (subsequent runs find it `available`). Per-room
  `DB::transaction` + `lockForUpdate` + re-check serializes overlapping runs
  (works on MySQL/Postgres; sqlite ignores `FOR UPDATE`, documented caveat —
  covered behaviorally by the double-run test).
- **Audit:** sweep transitions write a `room_status_logs` row with
  `from_status=cleaning`, `to_status=available`,
  `changed_by=null` (system-triggered convention), `reason='Cleaning period complete'`.

## 5. Booking Lifecycle Behavior

- Check-out places the room into `cleaning` exactly as before and now also
  stamps `cleaning_started_at` in the same transition (both timestamps set from
  the same `now()`). Nothing else about booking create / confirm / check-in /
  check-out / cancel changes.
- A freshly checked-out room is only swept after the full buffer elapses — a
  same-day turnover window for housekeeping.

## 6. Manual Early-Completion Behavior (existing endpoint, no new surface)

- Reuses `POST /api/v1/staff/rooms/{room}/status/available` (STEP 07
  `RoomStatusController::toAvailable` → `RoomService::transitionStatus` through
  `VALID_TRANSITIONS`, now including the `cleaning → available` edge).
- RBAC unchanged: route role middleware `role:admin,manager,receptionist`
  (receptionist included, matching the STEP 07 authorization matrix) + the
  `RoomPolicy::changeStatus` re-check.
- 401 guest / 403 customer; a room in a disallowed source state (e.g.
  `occupied`) still gets 409. Manual completion requires no buffer wait; the
  audit log records the real employee (`changed_by` = employee id) and any
  `reason`.

## 7. Test Strategy — Result

New `tests/Feature/Room/RoomTurnoverTest.php` (20 tests / 87 assertions):

1. Checkout (full API lifecycle) moves the room to `cleaning` and stamps
   `cleaning_started_at` (clock-independent: differs from `status_changed_at`
   by < 1s).
2. Room started 5 min ago stays `cleaning`; sweep outputs `Cleaned 0`; no audit
   row.
3. Room started 16 min ago becomes `available`; `Cleaned 1`.
4. Exact 15:00 boundary (frozen clock) → eligible → `available`.
5. 14:59:59 boundary (frozen clock) → stays `cleaning`.
6. Repeated runs idempotent: second run `Cleaned 0`, exactly one audit row.
7. Multiple rooms, mixed ages → only the eligible two flip.
8. Maintenance interrupt on a cleaning room; sweep never un-interrupts it.
9. Manual early-complete before the buffer → 200, `available`, audit
   `changed_by` = employee, reason preserved.
10. admin / manager / receptionist all early-complete → 200.
11. Customer token on the staff endpoint → 403.
12. Guest on the staff endpoint → 401 (standalone first request, matching the
    STEP 07 T13 pattern).
13. `occupied → available` via endpoint → 409, status unchanged.
14. Sweep never flips reserved / occupied / maintenance / available rooms even
    with 2-hour-old `status_changed_at`.
15. Null `cleaning_started_at` falls back to `status_changed_at` → swept.
16. Both timestamps null → skipped (`Cleaned 0`), stays `cleaning`, no audit
    row.
17. Soft-deleted cleaning room ignored by the sweep and 404 on the endpoint.
18. Sweep audit row: `from_status=cleaning`, `to_status=available`,
    `changed_by=null`, `reason=Cleaning period complete`.
19. Transition matrix: `cleaning→available` and `cleaning→maintenance` valid;
    `cleaning→reserved/occupied`, `occupied→available`, `available→available`
    invalid; `reserved→available` and `maintenance→available` unchanged.
20. `rooms:complete-cleaning` registered in the scheduler at `* * * * *`.

## 8. Test / Lint Result

- **Full suite:** `php artisan test` →
  **355 passed / 879 assertions / 0 failures** (baseline was 335 / 792 — the
  +20 tests / +87 assertions are the new STEP 13 file; all pre-existing
  STEP 01–12 tests pass with zero changes).
- **Lint:** `php -l` clean on every created/modified PHP file
  (`RoomService.php`, `BookingService.php`, `CompleteRoomCleaning.php`,
  `routes/console.php`, `RoomTurnoverTest.php`).
- No debug or temp files remain; no migrations added or modified.

## 9. Deviations from the Approved STEP 13 Plan

None in scope. Two test-implementation notes:

- The plan (§21) listed "a customer cannot early-complete (403) and a guest
  gets 401" as one combined test. During implementation the chained guest
  request after a `withHeader` customer request inherited the persisted token
  (Laravel test client default headers), so the "guest" leg returned 403.
  Split into two standalone tests (customer 403 / guest 401) — identical
  coverage with zero flakiness; the guest test is a first-request test exactly
  like STEP 07's T13.
- The plan (§21) test-fidelity note said no `Carbon::setTestNow`. The two exact
  ±1-second boundary tests (15:00 / 14:59:59) instead use Laravel's built-in
  `$this->freezeTime()` + `$this->travelBack()` (guarded with `try/finally`).
  This is determinism-only deviation: it pins the clock for those two
  assertions and does not change any behavior under test. `freezeTime()` is a
  first-party test helper (no new dependency).

## 10. Out-of-Scope Confirmation (STEP 14 untouched)

No `no_show` automation, no payment/refund changes, no customer self-booking
changes, no notifications, no dashboard/reporting/UI, no new
endpoints/controllers/FormRequests/policies beyond what §2 lists. STEP 13 does
**not** proceed to STEP 14.