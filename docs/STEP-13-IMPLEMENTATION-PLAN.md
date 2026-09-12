# STEP 13 — Room Cleaning / Turnover Automation Plan (review before implementation below)

## 0. Pre-coding Review — Read First (discrepancy flagged)

**Verified current state (STEP 01–12, full suite green: 335 tests / 792
assertions / 0 failures).**

### 0.1 Discovered discrepancy — checkout does NOT record `cleaning_started_at`

The STEP 13 business rule stated "checkout already places the room into
`cleaning` and records `cleaning_started_at`". The **first half is true, the
second is not.** Verified against the actual code:

- `BookingService::checkOut()` (`app/Services/BookingService.php:272`) calls the
  private `transitionRoom($room, 'cleaning', 'Guest checked out')`, which sets
  `status = 'cleaning'` and `status_changed_at = now()` and writes the audit
  log — but it does **not** touch `cleaning_started_at`.
- `cleaning_started_at` exists in the schema (nullable timestamp,
  `2025_02_01_000005_create_rooms_table.php:21`), is cast to `datetime` in
  `Room` (`app/Models/Room.php:32`), and is deliberately **not** in
  `Room::$fillable`. A repo-wide search finds **zero writes** to it from any
  code path.

So STEP 13 cannot simply "read the timestamp checkout wrote" — no timestamp is
written. The plan resolves this in §4 with a **minimal, additive fix** (stamp
`cleaning_started_at` exactly where a room enters `cleaning`, inside the single
existing transition helper), plus a **robust fallback** in the sweep
eligibility (`COALESCE(cleaning_started_at, status_changed_at)`) so rooms whose
row predates the fix are still handled correctly. No existing test asserts
`cleaning_started_at` is null after checkout, so the fix cannot break anything
green.

### 0.2 What exists today (reused, not re-built)

- **`RoomService::VALID_TRANSITIONS`** — the single source of truth for room
  status edges (`app/Services/RoomService.php:23`):
  `available→[maintenance,reserved]`, `reserved→[available,occupied]`,
  `occupied→[cleaning]`, `cleaning→[maintenance]`, `maintenance→[available]`.
  STEP 13 adds exactly one edge: `cleaning→available`.
- **`RoomService::transitionStatus()`** (`:96`) — validates against
  `VALID_TRANSITIONS`, writes the room + a `room_status_logs` audit row, and
  already accepts a **nullable `Employee`** (`$employee?->id`). Its own comment
  says "a future scheduled sweep (STEP 13) would pass null here for
  system-triggered transitions." This is the sanctioned hook for the sweep —
  `changed_by` null = system-triggered.
- **`BookingService::transitionRoom()`** (private, `:328`) — the booking-driven
  transition helper; reuses `RoomService::isTransitionValid()` and writes the
  same audit pattern. This is where check-in/check-out drive
  `reserved→occupied→cleaning`. Reused as-is for the timestamp stamp.
- **`rooms.status_changed_at`** — always set by both transition helpers on every
  transition, so `status_changed_at` is a guaranteed fallback timer for a room
  in `cleaning`.
- **Artisan sweep precedent** — `app/Console/Commands/ReleaseExpiredBookingHolds.php`
  (`bookings:release-expired-holds`) registered in `routes/console.php:24`
  (`Schedule::command(...)->hourly()`), tested in `HoldExpiryTest.php` (command
  run + `Schedule::events()` registration assertion). STEP 13 mirrors this
  pattern exactly.
- **Config** — `config('booking.cleaning_buffer_minutes')` = env
  `CLEANING_BUFFER_MINUTES` = **15**, already present in `config/booking.php:25`,
  `.env:73`, `.env.example:73`. **Do not change it.**
- **Manual maintenance interrupt** — `cleaning→maintenance` is already valid and
  tested (`tests/Feature/Room/RoomStatusTest.php:34`). Staff already has
  `POST /api/v1/staff/rooms/{room}/status/{maintenance,available}` with
  `role:admin,manager,receptionist` + `RoomPolicy::changeStatus`. Once §4 adds
  the `cleaning→available` edge, the **existing** `/status/available` endpoint
  IS the manual early-completion action — no new endpoint needed.

### 0.3 Gaps STEP 13 closes

1. `cleaning→available` edge is missing → rooms never leave `cleaning`.
2. No `cleaning_started_at` write → no reliable timer for the buffer.
3. No sweep command or schedule → turnover is manual only.
4. Staff cannot early-complete a `cleaning` room via the API (currently 409).

---

## 1. Exact Scope

**Objective:** complete the intentionally-deferred room turnover lifecycle —
a `cleaning` room returns to `available` 15 minutes after cleaning started —
with (a) an idempotent scheduled sweep, and (b) staff manual early-completion.

**In (working MVP):**
- `cleaning → available` becomes a valid transition (single edge added to the
  ONE state machine).
- Checkout (the only production path into `cleaning`) records
  `cleaning_started_at` at the moment the room enters `cleaning`.
- A new `rooms:complete-cleaning` Artisan command transitions every eligible
  `cleaning` room to `available`, using `COALESCE(cleaning_started_at,
  status_changed_at) <= now - cleaning_buffer_minutes` as eligibility, with
  per-room locking and a status re-check. Writes `changed_by = null` audit rows
  with a stable reason. Idempotent and safe to run repeatedly.
- The command is scheduled **every minute** in `routes/console.php`.
- Staff (admin/manager/receptionist) can manually move a `cleaning` room to
  `available` early via the **existing** `POST /api/v1/staff/rooms/{room}/status/available`
  endpoint (no new endpoint, no buffer check on the manual path — staff override
  is the point).
- Maintenance can interrupt cleaning via the existing `cleaning→maintenance`
  edge (unchanged, already valid).

**Out (deliberately):**
- `no_show` automation — enum-only, stays untouched. STEP 13 does not need it;
  nothing in the repo requires it here.
- Any change to bookings (creation, confirmation, cancel, check-in, check-out
  transitions *other than* writing `cleaning_started_at`), payments, or refunds.
- Pricing, invoicing, notifications, dashboard, UI.
- Deleting rows, new schema, new tables, new columns.
- A second room-status state machine — every transition still goes through
  `RoomService::VALID_TRANSITIONS` + the existing transition helpers.
- Scheduled auto-maintenance or auto-booking behaviors.
- Admin-dashboard exposure of the sweep (CLI-only, like the hold sweep).

---

## 2. Existing Implementation to Reuse (no duplication)

| Component | How reused | Changed? |
|---|---|---|
| `RoomService::VALID_TRANSITIONS` | add `cleaning → ['maintenance','available']` — still the ONE matrix | **Yes** (1 edge) |
| `RoomService::transitionStatus(Room, string, ?Employee, ?string)` | sweep + manual early-complete both call it; sweep passes `null` (system), manual passes the staff actor | **No** (already nullable-Employee ready) |
| `RoomService::isTransitionValid()` | booking path untouched, still validates every edge | **No** |
| `BookingService::transitionRoom()` | stamp `cleaning_started_at` when a room moves to `cleaning` (only reachable via check-out today) | **Yes** (additive, `toStatus === 'cleaning'` guard) |
| `ReleaseExpiredBookingHolds` command + `routes/console.php` | exact shape for the new command + schedule registration | **Yes** (additive) |
| `RoomPolicy::changeStatus` + `role:admin,manager,receptionist` route middleware | manual early-complete RBAC — existing, unchanged | **No** |
| `RoomStatusController::toAvailable()` | **is** the manual early-complete action once the edge exists | **No** |
| `ChangeStatusRequest` | existing nullable `reason` shape for the manual action | **No** |
| `BusinessRuleException` (409) | invalid manual transitions (e.g. `occupied→available`) keep returning 409 | **No** |
| `Room` SoftDeletes global scope | sweep query + route binding both exclude soft-deleted rooms for free | **No** |
| `DatabaseSchemaTest` | unchanged — no migration | **No** |

**Nothing existing is modified semantically.** The only semantic additions are
one transition edge and one timestamp write; both are additive and cannot
weaken an existing invariant (verified against every STEP 07–12 room-status
test; none asserts `cleaning_started_at` stays null).

---

## 3. Exact Files to Create

- `app/Console/Commands/CompleteRoomCleaning.php` — new command
  `rooms:complete-cleaning` calling the new `RoomService` method.
- `tests/Feature/Room/RoomTurnoverTest.php` — new test file (~17 tests).
- `docs/STEP-13-IMPLEMENTATION-REPORT.md` (written after implementation).

**No new controller, service, request, policy, model, or migration.** The
sweep logic lives as one new method on the existing `RoomService` (it owns the
state machine — adding a second service would be indirection, and a second
state machine is explicitly forbidden).

---

## 4. Exact Files to Modify

| File | Change |
|---|---|
| `app/Services/RoomService.php` | `VALID_TRANSITIONS['cleaning'] = ['maintenance', 'available']`; add `completeReadyCleaning(): int` (§6). `transitionStatus()` already supports the null-employee sweep path. |
| `app/Services/BookingService.php` | in private `transitionRoom()`, when `$toStatus === 'cleaning'` also set `$room->cleaning_started_at = now()` before save. One additive line; the only caller that reaches `cleaning` is `checkOut()`. |
| `routes/console.php` | `Schedule::command('rooms:complete-cleaning')->everyMinute();` (§6). |
| `docs/STEP-13-IMPLEMENTATION-REPORT.md` | written after implementation. |

**No migration.** `cleaning_started_at` already exists; the STATUS enum already
contains `cleaning`; `DatabaseSchemaTest` stays green with zero changes.

---

## 5. Configuration Location / Value for the Cleaning Buffer

- **Single source:** `config('booking.cleaning_buffer_minutes')` →
  `env('CLEANING_BUFFER_MINUTES', 15)` (`config/booking.php:25`).
- `.env:73` and `.env.example:73` already set `CLEANING_BUFFER_MINUTES=15`.
- **The 15-minute value is NOT changed.** The sweep reads the config at
  runtime, then computes `$cutoff = now()->subMinutes(config('booking.cleaning_buffer_minutes'))`.
- Could a scheduler cadence be derived from config? No — cadence is a fixed
  `everyMinute()` registration; the sweep is idempotent regardless of how
  often it runs, so cadence and buffer stay independent (documented §23).

---

## 6. Scheduler / Command Design

### Command: `rooms:complete-cleaning`

`app/Console/Commands/CompleteRoomCleaning.php` — mirrors
`ReleaseExpiredBookingHolds` exactly:

```php
class CompleteRoomCleaning extends Command
{
    protected $signature = 'rooms:complete-cleaning';
    protected $description = 'Transition rooms in cleaning past the buffer to available';

    public function handle(RoomService $service): int
    {
        $count = $service->completeReadyCleaning();
        $this->info("Cleaned {$count} room(s) ready to leave cleaning.");
        return self::SUCCESS;
    }
}
```

### `RoomService::completeReadyCleaning(): int`

1. Compute `$cutoff = now()->subMinutes((int) config('booking.cleaning_buffer_minutes', 15))`.
2. Select candidate ids: `Room::query()->whereNull('deleted_at')` (default
   scope) `->where('status', 'cleaning')` and
   `COALESCE(cleaning_started_at, status_changed_at) <= $cutoff`,
   `->orderBy('id')->pluck('id')` — deterministic ascending order.
3. For each id, run its **own `DB::transaction`** (mirrors
   `releaseExpiredHolds`'s per-row transaction):
   - Re-fetch the row with `lockForUpdate()` (the candidate select above is a
     snapshot; the lock re-read is the authoritative check).
   - Skip if the row is gone (soft-deleted mid-sweep) or `status !== 'cleaning'`
     (staff already early-completed it or flagged maintenance).
   - Re-check the buffer (eligibility is monotonic — time only moves forward —
     but re-check for correctness).
   - `$this->transitionStatus($room, 'available', null, 'Cleaning period complete')`
     — validates against the (extended) matrix, writes the room and a
     `changed_by = null` audit row.
   - `$count++`.
4. Return `$count`. The command prints "Cleaned N room(s)…".

### Schedule registration (`routes/console.php`)

```php
Schedule::command('rooms:complete-cleaning')->everyMinute();
```

Placed beside the existing hourly hold sweep, replacing the placeholder
comment that reserves this slot ("The cleaning-buffer sweep … still belongs
later"). Mirrors the existing `Schedule::events()` test
(`HoldExpiryTest.php:53`) asserting the registration + cron expression.

---

## 7. Manual Staff Early-Completion Behavior

- **Action = existing endpoint** `POST /api/v1/staff/rooms/{room}/status/available`
  (`RoomStatusController::toAvailable` → `RoomService::transitionStatus`).
- Once the `cleaning→available` edge exists, a staff member targeting a
  `cleaning` room gets **200** and the room flips to `available` immediately —
  **no buffer check on the manual path**. Early completion is exactly the point
  of a manual override; the 15-minute rule is the *automated* default, not a
  hard floor against housekeeping finishing a room faster than forecast.
- Audit: `room_status_logs` row `{from_status: cleaning, to_status: available,
  changed_by: <acting employee>, reason: <request reason or null>}` — identical
  to every manual status change today.
- No new endpoint, no new request, no new policy. RBAC stays
  `role:admin,manager,receptionist` + `RoomPolicy::changeStatus`.

---

## 8. Maintenance Interruption Behavior

- `cleaning → maintenance` is **already valid** (`VALID_TRANSITIONS` +
  `RoomStatusTest.php:34`) and is the sanctioned interruption:
  `POST /api/v1/staff/rooms/{room}/status/maintenance` on a `cleaning` room →
  200, audit row written.
- Once a room is `maintenance`, the sweep **cannot** touch it: the candidate
  query filters `status = 'cleaning'`, and maintenance is a different status.
- Returning a maintenance room to service is the existing staff action
  `maintenance → available` (unchanged). So an interrupted cleaning room only
  re-enters `available` via the human, never silently via the scheduler.
- No new `cleaning → maintenance` behavior is added — it already exists.

---

## 9. Room Status Transition Rules (single state machine)

The complete matrix after STEP 13 (one dict, one validator, unchanged shape):

| From | To | Trigger |
|---|---|---|
| `available` | `maintenance` | staff `.../status/maintenance` |
| `available` | `reserved` | booking confirm |
| `reserved` | `available` | booking cancel / refund / hold release |
| `reserved` | `occupied` | booking check-in |
| `occupied` | `cleaning` | booking check-out |
| `cleaning` | **`available`** | **sweep (after buffer) or staff `.../status/available` (early)** |
| `cleaning` | `maintenance` | staff `.../status/maintenance` |
| `maintenance` | `available` | staff `.../status/available` |

Only the **bolded** edge is new. Everything else already exists. The sweep and
the manual endpoint both go through `RoomService::transitionStatus()`, which
validates the edge and writes the audit row — **no transition logic is
implemented inside the command or a controller.**

`isTransitionValid()` is unchanged in behavior — it reads the extended matrix.
Booking-driven transitions (`reserved/occupied/cleaning` destinations) remain
the exclusive domain of `BookingService::transitionRoom()` + this matrix; STEP
13 adds a destination to that same matrix, so the booking engine's
`cleaning→available`-by-sweep never needed booking involvement.

---

## 10. Authorization / RBAC

- **Manual early-complete** (`cleaning→available` via existing endpoint):
  `auth:sanctum` + `actor:staff` + `role:admin,manager,receptionist`, then
  `RoomPolicy::changeStatus` (admin/manager/receptionist) in the controller —
  the existing two-layer pattern, unchanged.
- **Maintenance interrupt** (`cleaning→maintenance`): same as above, unchanged.
- **Sweep command**: no user context. Runs as the scheduler (CLI) user; audit
  rows carry `changed_by = null`, the documented meaning of "system-triggered"
  (`room_status_logs` migration comment + `RoomService::transitionStatus`)
  comment). Artisan commands in this repo are not role-protected (same as
  `bookings:release-expired-holds`).
- **Customer / guest**: customer on staff status routes → 403 (actor middleware);
  guest → 401. No customer-accessible surface touches `cleaning→available`.

---

## 11. API Endpoints

**No new endpoints.**

| Action | Endpoint (existing) | Roles |
|---|---|---|
| Manual early-complete `cleaning→available` | `POST /api/v1/staff/rooms/{room}/status/available` | admin, manager, receptionist |
| Maintenance interrupt `cleaning→maintenance` | `POST /api/v1/staff/rooms/{room}/status/maintenance` | admin, manager, receptionist |
| Automated turnover | `rooms:complete-cleaning` (Artisan, scheduled) | system (no auth) |

The manual early-complete shares the endpoint that already serves
`maintenance→available`; the matrix decides whether a given `from` room is
allowed — this is the existing design (`RoomStatusController` comment: invalid
transitions → 409 from the service, not checked in the controller).

---

## 12. Validation

- **No new request-validation class.** The manual early-complete reuses
  `ChangeStatusRequest` (`reason` nullable string max 255) as-is.
- Room existence / soft-delete handled by route model binding (SoftDeletes →
  404 for deleted rooms).
- The sweep has no client input: dates/times are read from
  `cleaning_started_at` / `status_changed_at` and the config buffer — no
  validation surface.
- All transition legality is enforced by `RoomService::transitionStatus`
  (`VALID_TRANSITIONS`), not by request shape — per Architecture Baseline §8
  (requests validate SHAPE, policies/rules decide permission/state).

---

## 13. Transaction Boundaries

- **Sweep:** one short `DB::transaction` **per room** (mirrors
  `BookingService::releaseExpiredHolds`). Per-room transactions keep lock
  hold-time minimal and mean one bad room can't roll back the whole run — a
  failed room is skipped, the command still reports the successfully cleaned
  count and exits `SUCCESS` (a hard failure inside a row just skips that row;
  the loop catches by letting the exception bubble per-transaction is **not**
  used — instead the re-check under lock makes a bad row a no-op, matching how
  `releaseReservedRooms` no-ops a null/missing room).
- **Booking check-out:** the `cleaning_started_at` stamp lives inside the
  existing check-out `DB::transaction` (via `transitionRoom`), so the room
  status change + audit row + timestamp commit atomically — no new transaction.
- **Manual early-complete:** `RoomService::transitionStatus` does a plain save
  + audit insert today (single-entity, effectively atomic); unchanged.

---

## 14. Concurrency / Locking Considerations

- **Candidate select is a snapshot; the authoritative read is the `lockForUpdate`
  re-fetch inside each room's transaction.** If a concurrent early-complete or
  maintenance flip lands between the select and the lock, the re-check
  (`status !== 'cleaning'` → skip) makes the sweep a no-op on that room. No
  double transition, no invalid matrix call.
- **Deterministic lock order:** rooms processed in ascending `id` order, so two
  overlapping sweeps can never acquire locks in conflicting order (same
  deadlock-avoidance rationale as `BookingService::create`, STEP 09 §4).
- **Idempotency under overlap:** two sweep runs racing the same room serialize on
  the row lock; the loser's re-check sees `available` and skips. Exactly one
  `cleaning→available` audit row per room.
- **sqlite caveat (unchanged, documented):** sqlite ignores `FOR UPDATE`, so the
  functional overlap behavior is covered by the double-run idempotency test;
  the pessimistic lock itself is real only on MySQL — same caveat recorded in
  STEP 09/11 reports.
- **Manual path has no explicit lock today** and needs none: single-row save +
  audit insert, and a modal human action. The sweep's re-check under lock is
  what protects *its* correctness against the manual path.

---

## 15. room_status_logs / Audit Behavior

- **Sweep transition:** `RoomService::transitionStatus($room, 'available', null,
  'Cleaning period complete')` → row `{from_status: cleaning, to_status:
  available, changed_by: null, reason: 'Cleaning period complete',
  created_at: useCurrent}`. `changed_by = null` is the documented
  system-triggered convention (migration comment + service comment) and is
  exactly what `RegisterExpiredHolds`'s sibling sweep relies on.
- **Manual early-complete:** `changed_by` = acting employee, `reason` = request
  body (nullable), same as every existing manual status change.
- **Checkout stamp:** the `occupied→cleaning` audit row is unchanged (reason
  `'Guest checked out'`); STEP 13 only adds the room's `cleaning_started_at`
  timestamp — no extra log row.
- No new log table, no `updated_at` on logs (append-only per
  `DatabaseSchemaTest`).

---

## 16. What Happens When `cleaning_started_at` is null

Eligibility uses **`COALESCE(cleaning_started_at, status_changed_at) <= $cutoff`**:

- **`cleaning_started_at` null but `status_changed_at` present** (any room that
  entered `cleaning` before STEP 13's stamp fix, or any fixture row): the sweep
  falls back to `status_changed_at`, which is guaranteed to be set on entry to
  `cleaning` by both transition helpers. The room is eligible once
  `status_changed_at` is ≥ buffer old. **Read-only rooms in SQL:**
  `whereRaw('COALESCE(cleaning_started_at, status_changed_at) <= ?', [$cutoff])`.
- **Both null:** `COALESCE` is null → not `<= cutoff` → the room is **skipped**.
  It can never be auto-flipped to `available` on a null timestamp; a staff
  member can still early-complete it manually (matrix allows `cleaning→available`).
- After STEP 13, fresh checkouts always set `cleaning_started_at`, so the
  fallback only carries legacy rows.

---

## 17. What Happens When the 15-Minute Threshold Has Not Elapsed

- The sweep's eligibility requires `COALESCE(...) <= now - 15min`. A room whose
  timestamp is younger than the buffer (`>` cutoff) does not match the query →
  **stays `cleaning`**, no transition, no audit row.
- **Exact boundary:** at exactly 15:00 elapsed (`timestamp == cutoff`) the room
  IS eligible (`<=`), so `subMinutes(15)` transitions; `subMinutes(15)->addSecond()`
  does not. The test suite pins both sides.
- The manual early-complete path intentionally has **no** buffer check — staff
  override is a feature, not a bug (§7).

---

## 18. Idempotency / Repeated Scheduler Execution

- The candidate query filters `status = 'cleaning'`; once a room flips to
  `available` it can never be selected again, so a second run processes 0 rooms.
- The matrix validator rejects an invalid second transition anyway
  (`available→available` doesn't exist) — defense in depth.
- The per-room lock + post-lock re-check makes even *concurrent* runs safe (two
  runs → one winner, one no-op).
- Running the command on an empty/fully-cleaned set returns `0` and exits
  `SUCCESS` — safe every minute, forever.
- **Non-regression guard:** the sweep can never select `occupied`, `reserved`,
  `maintenance`, or `available` rooms (hard `status = 'cleaning'` filter), so no
  crank can accidentally make a live/maintenance/reserved room `available`.

---

## 19. Soft-Deleted Rooms

- `Room` uses `SoftDeletes`; the global scope excludes `deleted_at IS NOT NULL`
  rows from **every** model query, including the sweep's candidate select and
  the route binding for the manual endpoint. A soft-deleted `cleaning` room is
  never selected by the sweep and returns **404** on the manual endpoint.
- `transitionStatus`/`transitionRoom` never operate on a trashed room via
  app paths (booking `transitionRoom` already throws if `$room->trashed()`).
- Tested explicitly (§21).

---

## 20. Invalid-State Handling and HTTP Status Codes

| Case | Result |
|---|---|
| Sweep runs; room already moved / not `cleaning` | no-op, skipped silently |
| Manual `.../status/available` on a room in `available`/`occupied`/`reserved` | **409** `BusinessRuleException` (unchanged) |
| Manual `.../status/available` on a `cleaning` room | **200**, room available |
| Manual `.../status/maintenance` on a `cleaning` room | **200** (interrupt, unchanged) |
| Soft-deleted / missing room on manual endpoint | **404** (model binding) |
| Customer actor | **403** (actor middleware) |
| Guest / unauthenticated | **401** |
| Command on empty set | exit 0, "Cleaned 0 room(s)…" |

All error shapes via the standard `{success, message, errors}` envelope
(`bootstrap/app.php` renders, unchanged).

---

## 21. Test Strategy — Exact Scenarios

New file `tests/Feature/Room/RoomTurnoverTest.php` (~17 tests):

1. **Checkout records cleaning + `cleaning_started_at`:** full booking
   lifecycle (create→confirm→check-in→check-out) → room `cleaning`,
   `cleaning_started_at` ≈ now, existing audit rows intact (extend lifecycle
   assertions only in the NEW file; `BookingLifecycleTest` untouched).
2. **Before 15 min → sweep leaves it cleaning:** `cleaning_started_at` =
   `now()->subMinutes(5)` → run command → exit 0, `0` processed, status still
   `cleaning`, no `cleaning→available` log row.
3. **After 15 min → sweep makes it available:** `cleaning_started_at` =
   `now()->subMinutes(16)` → run → room `available`.
4. **Exact boundary — 15:00:** `subMinutes(15)` → becomes `available`.
5. **Exact boundary — 14:59:** `subMinutes(15)->addSecond()` → stays `cleaning`.
6. **Idempotent repeated runs:** run twice → second run processes 0; exactly one
   `cleaning→available` audit row.
7. **Multiple cleaning rooms, mixed ages:** 2 eligible + 2 too-fresh → command
   reports 2; only eligible rooms flipped.
8. **Maintenance interrupt:** room in `cleaning` → staff
   `.../status/maintenance` → 200, `maintenance`; sweep (buffer elapsed on the
   old timestamp) does **not** touch it.
9. **Manual early-complete (staff):** `cleaning` room, buffer NOT elapsed →
   staff `.../status/available` → 200 `available`; audit row has
   `changed_by` = employee.
10. **Roles:** admin, manager, receptionist each early-complete a `cleaning`
    room → 200.
11. **Customer forbidden:** customer token on `.../status/available` for a
    `cleaning` room → 403; guest → 401.
12. **Invalid room state:** `.../status/available` on an `occupied` room →
    409; sweep never flips `occupied`/`reserved`/`available` rooms even with an
    ancient timestamp (non-regression guard: "never make live rooms
    available").
13. **Null `cleaning_started_at` → fallback:** null + `status_changed_at` 30 min
    ago → sweep flips (fallback timer proved).
14. **Both null → skipped:** stays `cleaning`, never auto-flipped.
15. **Soft-deleted room:** soft-delete a `cleaning` room past buffer → sweep
    skips it; manual endpoint → 404.
16. **Audit log (system trigger):** sweep writes
    `{cleaning, available, changed_by: null, reason: 'Cleaning period
    complete'}`.
17. **Command registered + scheduled every minute** (`Schedule::events()` +
    `* * * * *`), mirroring `HoldExpiryTest.php:53`.

**Test-fidelity note:** the sweep's thread/MySQL-lock path is covered by the
double-run idempotency test; for `cleaning_started_at = commit`, command runs
use real time with `Carbon` set on the room row (no `Carbon::setTestNow`
dependency). Eligibility math is the same function the command uses.

**Baseline:** 335 tests / 792 assertions must stay green with **zero changes**
to existing tests.

---

## 22. Regression Protection for STEP 01–12

- `VALID_TRANSITIONS` gains one edge; every existing edge is untouched, so
  `RoomStatusTest` (13 tests), `StaffRoomTest`, `PublicRoomTest` stay green —
  including "occupied→available is rejected" and "cleaning→maintenance works".
- `RoomService::transitionStatus` unchanged; the sweep is a new caller of an
  unchanged method.
- `BookingService::create/confirm/cancelBy*/checkIn/checkOut` transitions and
  statuses are semantically unchanged; `transitionRoom` gains only a timestamp
  write guarded by `toStatus === 'cleaning'`. The full lifecycle test
  (`BookingLifecycleTest`), `StaffBookingTest`, `DoubleBookingTest`,
  `HoldExpiryTest` assert room *statuses*, never
  `cleaning_started_at`-is-null — verified, none break.
- `routes/api.php` untouched; `routes/console.php` only gains one line (the
  existing hourly hold sweep registration + its test are untouched).
- Payment/refund (STEP 10/11) and staff booking (STEP 12) files untouched.
- `DatabaseSchemaTest` unchanged (no migration).
- Full suite must finish **335 + ~17 = ~352 tests green**.

---

## 23. Scheduler Behavior on XAMPP / Cron

- **Runtime:** XAMPP ships no built-in scheduler daemon. The Laravel scheduler
  (`php artisan schedule:run`) must be invoked every minute by an external
  trigger:
  - Dev: `php artisan schedule:work` (long-running loop) in a terminal.
  - Prod/Linux: a crontab line
    `* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1`.
- **On Windows/XAMPP** (this machine): `schedule:work` in a dedicated
  terminal window is the documented dev path; the registered schedule has no
  effect unless one of the two above is running. Same documented caveat as the
  existing hourly hold sweep (`STEP-09-IMPLEMENTATION-REPORT.md` §7).
- **Cadence** `everyMinute()` vs **buffer** 15 min: the gap between a room
  crossing the buffer and the next sweep tick is ≤ 1 minute by design; both
  are a deployment choice and every-minute keeps the boundary tight. The sweep
  is idempotent, so a missed/throttled tick only delays turnover, never
  corrupts state.
- Tests assert schedule *registration* + cron expression, not live cron.

---

## 24. Explicit Out-of-Scope (final checklist)

- ❌ `no_show` automation — enum-only, untouched; not required by STEP 13.
- ❌ Any change to booking create/confirm/cancel/check-in
  (transitionRoom's `cleaning_started_at` stamp is a timestamp write, not a
  behavior change).
- ❌ Any change to payment/refund behavior.
- ❌ A second room-status state machine or duplicated transition logic.
- ❌ New endpoints / FormRequests / policies / controllers.
- ❌ New schema: no columns, no tables, no enum changes
  (`cleaning` already in the rooms enum).
- ❌ Changing the 15-minute buffer value or its config key.
- ❌ Auto-maintenance, auto-booking, invoicing, notifications, dashboard,
  reporting, UI.
- ❌ Deleting or archiving rows (status-driven lifecycle only).

## 25. Wait for Approval

Plan only. No implementation until approved.