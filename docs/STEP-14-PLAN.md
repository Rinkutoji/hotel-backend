# STEP 14 — No-Show Automation Plan (review before implementation below)

> Decision point flagged at the top: STEP 14 is the **first** step whose feature
> has no ready-made column in the STEP 03 schema. §3 recommends a minimal
> additive migration (`bookings.no_show_at`) and also specifies the zero-migration
> fallback. **Approve one or the other in §3 before implementation.**

## 0. Pre-coding Review — Read First

**Verified current state (STEP 01–13, full suite green: 355 tests / 879
assertions / 0 failures).** Everything below is checked against the actual
code, not assumptions.

### 0.1 The one real schema question

`bookings.status` has carried the `no_show` enum value since STEP 03, but no
code path ever sets it ("enum-only" in every STEP 09–13 plan). The bookings
table has dedicated columns for **cancellations** (`cancelled_at`,
`cancellation_reason`) and the hold deadline (`payment_deadline_at`), and
`booking_rooms` has `actual_check_in_at` / `actual_check_out_at` — but **no
no_show-specific timestamp exists**. For checked-in/checked-out, the baseline
deliberately used booking_rooms timestamps, not a booking-level column. So the
schema leaves open *where the no-show moment is recorded*. §3 resolves this.

### 0.2 The essential data-flow finding (rooms must be released)

`confirmWithinTransaction()` reserves rooms (`available → reserved`) when a
booking becomes `confirmed` (`BookingService.php:216`). `STATUSES_HOLDING`
(`:21`) excludes `no_show` and `cancelled`, so an availability search already
treats a no-showed booking's **dates** as free — but **`room.status` stays
`reserved`** until something releases it. If a no_show sweep only flipped the
booking status and left rooms `reserved`, then:

- The room row lies (`reserved` while its dates are bookable).
- A later confirm of a new booking for that room would call
  `transitionRoom(room, 'reserved', ...)` while the room is already `reserved`
  → invalid `reserved→reserved` edge → `BusinessRuleException` → **409 on a
  legitimate rebooking**.

⇒ **STEP 14's sweep MUST release reserved rooms to `available`** (exactly like
`applyCancellation()` / `releaseReservedRooms()`, `BookingService.php:366-389`).

### 0.3 What exists today (reused, not re-built)

- **Hold-expiry sweep precedent** — `releaseExpiredHolds()` +
  `bookings:release-expired-holds`, registered `->hourly()` in
  `routes/console.php`, tested in `HoldExpiryTest.php` (command run + output +
  `Schedule::events()` expression `0 * * * *`). STEP 14 mirrors this shape
  exactly for candidate select, per-booking `DB::transaction`, and scheduling.
- **Room release helpers** — `applyCancellation()` (`:366`) and
  `refundBooking()` (`:402`) already release `reserved` rooms via the private
  `transitionRoom()` (`:328`), which writes `room_status_logs` with
  `changed_by = null` (the documented booking-driven/system convention;
  `Room::status` + `RoomStatusLog::changed_by`), reasons as strings, and rolls
  back atomically inside the caller's transaction.
- **Booking transition guards** — `confirm` (pending only), `cancelByStaff`
  (`pending_payment`/`confirmed` only), `checkIn` (`confirmed` only +
  `today >= check_in_date`), `cancelByCustomer` (`pending_payment` only).
  `no_show` is **already terminal on every existing surface** (everything else
  → 409) and is **excluded from availability** (`STATUSES_HOLDING`). No guard
  changes needed.
- **Filtering** — `FilterRequest::rules()` already whitelists `no_show`
  (`app/Http/Requests/Booking/FilterRequest.php:12`), and the staff/customer
  index controllers filter `status` generically → no-show bookings are
  listable/filterable with **zero code change**.
- **Config** — no config number is needed: the trigger is date-based
  (`check_in_date < today`), not a configurable buffer. STEP 14 adds **no**
  `config/booking.php` key (`config/booking.php` header comment says both
  current keys are read only by STEP 10-era code and STEP 13).
- **Room release audit** — `room_status_logs` reason strings are the
  established audit vehicle (e.g. `'Booking cancelled'`, `'Guest checked out'`).
  There is **no booking_status_logs table**; booking events live on the booking
  row (`cancelled_at`/`cancellation_reason`) plus the room audit rows.

### 0.4 Gaps STEP 14 closes

1. `no_show` is never set anywhere → no-showed guests are indistinguishable
   from late `confirmed` bookings forever; their rooms stay `reserved`.
2. No scheduled sweep marks them or releases their rooms.

---

## 1. Exact Scope

**Objective:** implement the intentionally-deferred no-show lifecycle — a
`confirmed` booking whose check-in date has fully passed without a check-in is
automatically marked `no_show`, and its reserved rooms are released back to
`available` so the inventory re-enters circulation.

**In (working MVP):**
- A new `bookings:mark-no-show` Artisan command marks eligible bookings
  `confirmed → no_show` and releases their `reserved` rooms to `available`
  (room audit rows, `changed_by = null`, stable reason).
- The command is scheduled **hourly** in `routes/console.php` (same cadence as
  the sibling hold-expiry sweep; idempotent, so catch-up after downtime is
  automatic).
- Eligible = **`status = 'confirmed'` AND `check_in_date < today`** (strictly
  before today — the entire check-in day remains open for a late check-in; the
  sweep only acts once the day is over).
- Rooms release: `reserved → available` for every room of the booking whose
  `room.status` is still `reserved` (mirrors cancellation/refund release).
- Booking marker: `status = 'no_show'`, and (per §3 decision) either a
  `no_show_at` timestamp (recommended) or the row's `updated_at` (fallback).
- `no_show` stays **terminal**: existing confirm/cancel/check-in/check-out
  guards already reject it; no reverse/undo path.
- Staff can see no-show bookings via the existing list/filter (`status=no_show`
  already whitelisted) — zero code change.

**Out (deliberately):**
- **No automatic refund** on no_show. STEP 10/11 define refunds as a
  staff-initiated flow on a *verified* payment; STEP 11 explicitly deferred
  "refund on no_show". A staff member may still refund a no-showed booking
  through the existing payment-refund endpoint.
- No change to payment verification, pay-now balancing (`PaymentService`),
  customer self-service, notifications, dashboard, or UI.
- No new API endpoints, no new controllers/policies/FormRequests.
- No `no_show` handling inside `checkIn`/`confirm`/`cancel` beyond what already
  rejects it (409). No late-cancel fees (STEP 09 out-list).
- No reversal/undo of a no_show marking.
- No deleting or archiving rows (status-driven lifecycle only).

---

## 2. Business Rules (explicit, for review)

| # | Rule | Rationale / Source |
|---|---|---|
| BR-1 | A booking is no_show-eligible iff `status = 'confirmed'` **and** `check_in_date < now()->startOfDay()`. | Only confirmed bookings hold rooms and have passed payment; `pending_payment` is handled by the hold sweep (`cancelled`). A booking is only "late" once its whole check-in day is over (`checkIn` allows any time on/after the check-in date). |
| BR-2 | `check_in_date == today` is **not** eligible — the guest can still check in all day. | Conservative: never no-show a guest on their own check-in day. |
| BR-3 | No-show is **terminal**. Existing guards already return 409 for confirm/cancel/check-in/check-out on a `no_show` booking; nothing changes them. | STEP 09 plan: terminal set is `cancelled`, `checked_out`; STEP 11 report: `no_show` already terminal → 409. |
| BR-4 | The sweep releases every `reserved` room of an eligible booking to `available`, audit reason `'Guest did not check in'`, `changed_by = null`. Rooms not in `reserved` (shouldn't occur for `confirmed`) are left untouched. | §0.2 — without release, `room.status` lies and rebooking 409s. Audit convention mirrors `'Booking cancelled'`. |
| BR-5 | The booking row is marked `status = 'no_show'` plus the §3 timestamp, atomically with the room releases (one `DB::transaction` per booking). | Mirrors `applyCancellation()` / `refundBooking()` atomicity. |
| BR-6 | Candidate selection re-checks `status === 'confirmed'` **inside** the transaction before marking. | Closes the race where staff checks in a booking between the sweep's select and commit — the sweep must never clobber a live `checked_in` booking. |
| BR-7 | Idempotent: only `confirmed` candidates are selected; a second run finds the booking `no_show` and processes 0. Missed/throttled ticks self-correct on the next run. | Same contract as the hold + cleaning sweeps. |
| BR-8 | No refund automation, no fees, no notifications. | Out-of-scope (STEP 11 deferred refund-on-no_show; STEP 09 out-list). |

---

## 3. Decision Point — Where the No-Show Moment Is Recorded

**Recommended: minimal additive migration.**

- `database/migrations/2025_02_08_000001_add_no_show_at_to_bookings_table.php`
  — `Schema::table('bookings', fn (Blueprint $t) =>
  $t->timestamp('no_show_at')->nullable())`.
- `app/Models/Booking.php` — add `no_show_at` to `$fillable` +
  `'no_show_at' => 'datetime'` cast.
- Rationale: symmetric with `cancelled_at` (cancellation is the closest
  analogue — a terminal, notable event); records *when* the sweep acted; stays
  honest under later edits (unlike `updated_at`).
- Risk is low and verified: `DatabaseSchemaTest` asserts required fields, soft
  deletes, and table existence — it does **not** enumerate the exact bookings
  column set, so it stays green; nothing else reads a complete column list;
  the column is nullable and defaulted null.

**Fallback if "no migration" is insisted on** (the STEP 09–13 convention):
- Zero migration. Marker = `status = 'no_show'` only; the moment is the row's
  `updated_at` (Eloquent stamps it on the sweep's save) **plus** the
  `room_status_logs` rows with `created_at = useCurrent` and reason
  `'Guest did not check in'`, which together timestamp the event.
- Trade-off: `updated_at` is generic (any later edit moves it); the audit room
  rows are the reliable "when".

**This is the single schema decision in STEP 14.** Everything else is additive
code. **Approve §3 before implementation.**

---

## 4. Scheduler / Command Design

### Command: `bookings:mark-no-show`

`app/Console/Commands/MarkNoShowBookings.php` — mirrors
`ReleaseExpiredBookingHolds` exactly:

```php
class MarkNoShowBookings extends Command
{
    protected $signature = 'bookings:mark-no-show';
    protected $description = 'Mark confirmed bookings whose check-in date has passed as no-show';

    public function handle(BookingService $service): int
    {
        $count = $service->markNoShowBookings();
        $this->info("Marked {$count} booking(s) as no-show.");
        return self::SUCCESS;
    }
}
```

### `BookingService::markNoShowBookings(): int`

1. `$today = now()->startOfDay();`
2. Candidate ids: `Booking::where('status', 'confirmed')
   ->where('check_in_date', '<', $today->toDateString())->orderBy('id')
   ->pluck('id')` — deterministic ascending order (same rationale as the
   cleaning sweep).
3. For each id, its own `DB::transaction` (mirrors `releaseExpiredHolds`):
   - Re-read the row (`find`); skip if null (no soft-deletes on bookings, but
     defensive) or `status !== 'confirmed'` (**BR-6 race guard**; booking has
     no `deleted_at`, so the status re-check is the authoritative one).
   - `applyNoShow($booking)` then `$count++`.
4. Return `$count`.

### `applyNoShow(Booking $booking): void` (private, new)

```php
$booking->status = 'no_show';
if ($this->usesNoShowAt) { $booking->no_show_at = now(); } // per §3
$booking->save();
$this->releaseReservedRooms($booking, 'Guest did not check in');
```

### `releaseReservedRooms()` — small refactor (reason param)

Today `applyCancellation()` calls `releaseReservedRooms($booking)` with a
hard-coded reason `'Booking cancelled'`, and `refundBooking()` duplicates a
release loop with its own reason. STEP 14 needs a third reason, so:

- Change the private helper signature to
  `releaseReservedRooms(Booking $booking, string $reason)` and update its
  `transitionRoom($room, 'available', $reason)` call.
- `applyCancellation()` passes `'Booking cancelled'` (unchanged behavior,
  unchanged audit strings).
- `refundBooking()` collapses its duplicate loop to call the helper with its
  existing reason prefix (identical audit output — verified by existing STEP 11
  tests).
- All three paths keep `transitionRoom` behavior, so `changed_by` stays null
  and audit reasons stay exact.

**No change** to `BookingService::create`, `confirm`, `checkIn`, `checkOut`,
`cancelByCustomer`, `cancelByStaff`, `cancelExpiredHold`, `applyCancellation`
semantics, or any controller/policy/route.

### Schedule registration (`routes/console.php`)

```php
Schedule::command('bookings:mark-no-show')->hourly();
```

Placed beside the existing hourly hold sweep (registered at `routes/console.php:24`).
Hourly cadence (not daily): same expiry-sweep pattern as `bookings:release-expired-holds`;
≤1h detection latency after midnight, automatic catch-up, idempotent. Mirrors
`HoldExpiryTest.php:53` schedule-registration test (expression `0 * * * *`).

---

## 5. Data Flow

```
[booking confirmed]  ── confirmWithinTransaction ──▶  booking.status=confirmed
                                                       rooms: available → reserved  (audit 'Booking confirmed')

[00:00 next day+]    bookings:mark-no-show (hourly)
  └─ select confirmed bookings with check_in_date < today (asc id)
       └─ per booking, transaction:
            ├─ re-check status === confirmed     (BR-6 race guard)
            ├─ booking.status = 'no_show' (+ no_show_at per §3)
            └─ rooms: reserved → available       (audit 'Guest did not check in', changed_by=null)

[after]  room is bookable again (STATUSES_HOLDING excludes no_show;
         room.status = available); booking visible via status=no_show filter.
```

---

## 6. API Contract

**No new endpoints.**

| Action | Endpoint (existing) | Result |
|---|---|---|
| List/filter no-show bookings | `GET /api/v1/staff/bookings?status=no_show` | unchanged, `no_show` already whitelisted |
| View a no-show booking (staff/customer own) | `GET .../bookings/{booking}` | unchanged — shows `status: no_show` |
| Confirm / cancel / check-in / check-out on a no-show booking | existing lifecycle endpoints | **409** (unchanged guards, terminal) |
| Availability for a released room | `POST /api/v1/public/availability` | unchanged — room shows as available |
| Automated marking | `bookings:mark-no-show` (Artisan, scheduled) | system (no auth) |

---

## 7. Authorization / RBAC

- **No API surface changes.** Sweep runs as the scheduler (CLI) user; room
  audit rows carry `changed_by = null` (the documented system/booking-driven
  convention, same as cancellation and STEP 13's sweep). Artisan commands are
  not role-protected (same as `bookings:release-expired-holds` and
  `rooms:complete-cleaning`).
- Booking mark itself has no audit table; per BR-5 the "actor" is implicit in
  the sweep (system). Existing staff/customer authorization on the list/view
  endpoints is unchanged and already covers no-show rows (policy branches are
  status-agnostic).

---

## 8. Validation

- No new request-validation class; the command has no client input.
- Trigger is a business rule (`check_in_date < today`), not request shape —
  consistent with Architecture Baseline §8 (requests validate shape; rules/policies
  decide state).
- Room release legality is enforced by `RoomService::isTransitionValid`
  (`reserved → available` is a long-standing valid edge) inside
  `transitionRoom`, not by the sweep.
- All existing lifecycle guards (`confirm`/`cancel`/`checkIn`/`checkOut` 409s)
  are unchanged and continue to reject `no_show`.

---

## 9. Concurrency / Transactions / Audit

- **One short `DB::transaction` per booking** (mirrors `releaseExpiredHolds`):
  minimal lock hold, one bad booking can't roll back the run.
- **BR-6 race guard:** the candidate select is a snapshot; the authoritative
  check is `status === 'confirmed'` re-read inside the transaction. Scenario
  closed: staff check-in lands between select and commit → the sweep sees
  `checked_in` and skips; the booking is never clobbered to `no_show`. (Same
  protect-the-live-row reasoning as STEP 13's re-check; booking rows have no
  `deleted_at`, so no soft-delete path exists here.)
- **Deterministic order:** ascending `id`, so overlapping runs can't deadlock
  (same rationale as `BookingService::create` / the cleaning sweep).
- **Idempotency under overlap:** two runs racing one booking serialize per
  transaction; loser's re-check sees `no_show` and skips. Exactly one release
  per room.
- **sqlite caveat (unchanged, documented):** no `FOR UPDATE` in this sweep
  (none needed — the re-check is a status read, and bookings have no
  soft-delete row race); sqlite tests cover the functional behavior.
- **Audit rows:** per released room,
  `{from_status: reserved, to_status: available, changed_by: null,
  reason: 'Guest did not check in', created_at: useCurrent}`. No new log table.
  If §3 migration is approved, `bookings.no_show_at` records the sweep time
  (else `updated_at` + room audit rows).
- **No `no_show` audit row exists for the booking itself** — consistent with
  the rest of the app (`checked_in`/`checked_out` have no booking-level log
  either; only cancellations get booking-row fields).

---

## 10. Interaction with Existing Modules (verified non-regression)

- **Availability (STEP 09):** no_show bookings were never holding; releasing
  rooms makes them *device-consistent* (`room.status = available`), no
  behavioral change to the query.
- **Hold sweep (STEP 09) + cleaning sweep (STEP 13):** both are separate,
  status-filtered, per-row-transaction sweeps; the new sweep is additive and
  cannot collide (different statuses: `pending_payment` vs `confirmed` vs
  `cleaning`).
- **Payments/refunds (STEP 10/11):** untouched. A `verified`/`refunded`
  payment for a no-showed booking still works exactly as today (staff
  `POST /payments/{payment}/refund`; a refund on a `no_show` booking walks the
  existing `refundBooking()` path — nothing about that changes).
- **Staff booking create (STEP 12):** unchanged; must remain bookingable into
  a released room (covered by a test).
- **Room transitions (STEP 07/13):** `reserved → available` is one of the
  original STEP 09 edges; the sweep is just a new caller of the same code.

---

## 11. Files to Create / Modify

| File | Change | New? |
|---|---|---|
| `app/Console/Commands/MarkNoShowBookings.php` | command `bookings:mark-no-show` | **new** |
| `tests/Feature/Booking/NoShowSweepTest.php` | ~14 tests | **new** |
| `docs/STEP-14-IMPLEMENTATION-REPORT.md` | written after implementation | **new** |
| `app/Services/BookingService.php` | `markNoShowBookings(): int` + `applyNoShow()` + `releaseReservedRooms(Booking, string $reason)` refactor | modify |
| `routes/console.php` | `Schedule::command('bookings:mark-no-show')->hourly();` | modify |
| `app/Models/Booking.php` | **if §3 migration approved:** `no_show_at` in `$fillable` + `datetime` cast | modify (conditional) |
| `database/migrations/2025_02_08_000001_add_no_show_at_to_bookings_table.php` | **if §3 migration approved** | modify (conditional, additive) |

**No new controllers, policies, FormRequests, models, or services.** The sweep
lives as one method on the existing `BookingService` (it already owns the
booking state machine and cancellation release logic; a second service would be
indirection).

---

## 12. Test Strategy — Exact Scenarios

New `tests/Feature/Booking/NoShowSweepTest.php` (~14 tests), mirroring
`HoldExpiryTest` + `RoomTurnoverTest` conventions (shared `makeBookingCustomer`,
`makeBookingRoom`, `makeHoldingBooking`, `loginAsEmployee` helpers; frozen-time
only where day-boundary exactness needs it → `$this->freezeTime()` +
`$this->travelBack()` in `try/finally`):

1. **Confirmed booking, check-in yesterday** → run command → `no_show`,
   `no_show_at` set (per §3), `Cleaned`-style output "Marked 1", exit 0.
2. **Same-day booking (`check_in_date == today`) stays `confirmed`** — the
   whole check-in day stays open (BR-2); "Marked 0".
3. **Future check-in stays `confirmed`** — "Marked 0".
4. **Only `confirmed` is targeted:** proven by a set containing
   `pending_payment` (unexpired + expired), `confirmed` (yesterday),
   `checked_in`, `checked_out`, `cancelled`, `no_show` → only the confirmed one
   is marked; every other status untouched.
5. **Rooms released:** two rooms confirmed+reserved → both `reserved →
   available`; two `room_status_logs` rows
   `{reserved, available, changed_by: null, reason: 'Guest did not check in'}`.
6. **No-show booking's room is bookable again** (public availability + a new
   booking 201 on the same dates) — proves the inventory re-entry end-to-end.
7. **Idempotent double run:** second run "Marked 0"; exactly one release per
   room.
8. **Race guard (BR-6):** two eligible bookings; flip one to `checked_in`
   before the run → only the still-`confirmed` one is marked; the `checked_in`
   one is untouched.
9. **Terminal behavior preserved:** staff `confirm` / `cancel` / `check-in` /
   `check-out` on a `no_show` booking → each **409**; customer cancel → **409**
   (unchanged guards, regression).
10. **Staff listing:** `GET /api/v1/staff/bookings?status=no_show` returns the
    marked booking; customer sees it in their own list.
11. **Command registered hourly:** `Schedule::events()` contains
    `bookings:mark-no-show` with expression `0 * * * *`
    (mirror `HoldExpiryTest.php:53`).
12. **Mixed candidates, count correct:** 2 yesterday-confirmed + 1 today +
    1 future + 1 pending → "Marked 2", only the 2 flipped.
13. **Single-room non-reserved corner:** a `confirmed` booking whose room is
    somehow `available` → booking still marked `no_show`, no room transition,
    no audit row (BR-4 "only reserved rooms touched").
14. **Multi-room partial:** one room `reserved`, one already `available` →
    only the reserved one released.

**Baseline:** 355 tests / 879 assertions must stay green with **zero changes**
to existing tests (STEP 13 state). Expected final ≈ **369 / ~966+**.

---

## 13. Regression Protection for STEP 01–13

- `BookingService` edits are additive (new public method + private helpers) and
  the one refactor (`releaseReservedRooms` gains a reason param) is
  behavior-preserving: `applyCancellation` passes the identical string;
  `refundBooking` collapses its duplicate loop to identical audit output —
  verified by the existing STEP 11 refund tests asserting reasons.
- The 409 guards in confirm/cancel/check-in/check-out and `STATUSES_HOLDING`
  are untouched → `BookingLifecycleTest`, `StaffBookingTest`,
  `CustomerBookingTest`, `DoubleBookingTest`, `HoldExpiryTest` stay green
  (none asserts a booking can leave `confirmed` without a lifecycle call).
- `routes/api.php`, all controllers/policies/requests, `PaymentService`,
  `RoomService` untouched.
- If §3 migration is approved: additive nullable column; `DatabaseSchemaTest`
  doesn't enumerate bookings columns (verified §3) — stays green; `Booking`
  `$fillable` addition can't mass-assign from client input because no request
  accepts a `no_show_at` key.
- Full suite must finish **355 + ~14 = ~369 tests green**.

---

## 14. Scheduler Behavior on XAMPP / Cron

- Same documented caveat as the hold and cleaning sweeps
  (`STEP-09-IMPLEMENTATION-REPORT.md` §7 / STEP-13 plan §23): Laravel's
  scheduler needs `php artisan schedule:work` (dev) or cron
  `* * * * * ... php artisan schedule:run` (prod) — XAMPP ships no scheduler
  daemon.
- Hourly cadence ⇒ a no-show crossing midnight is detected within ≤ 1 hour;
  catch-up is automatic and idempotent if the scheduler was down.
- Tests assert schedule registration + cron expression, not live cron.

---

## 15. Explicit Out-of-Scope (final checklist)

- ❌ No automatic refund / pay-now refund on no_show (staff-initiated refund of
  the booking's verified payment remains available — unchanged).
- ❌ No new endpoints, controllers, policies, FormRequests, models, services.
- ❌ No change to booking create / confirm / check-in / check-out / cancel
  (only the new sweep's `confirmed → no_show` + room release); no late-cancel
  fees.
- ❌ No notifications, email, dashboard, reporting, UI.
- ❌ No reversal/undo of a no_show mark; no data deletion/archiving.
- ❌ No customer self-service to mark or dispute no-show.
- ❌ No change to the cleaning or hold sweeps, `RoomService::VALID_TRANSITIONS`,
  `RoomStatusController`, or payment routes.
- ❌ Unless §3 is approved: no schema change at all.

## 16. Wait for Approval

Plan only, with an explicit §3 decision to confirm. No implementation until
approved.