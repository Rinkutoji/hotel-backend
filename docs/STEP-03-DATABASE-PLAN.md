# STEP 03 — Database Plan (review before implementation below)

## 1. Final Table List

Exactly the 10 approved tables — nothing added, nothing skipped:

`departments`, `customers`, `employees`, `room_types`, `rooms`,
`room_images`, `bookings`, `booking_rooms`, `payments`, `room_status_logs`

## 2. Relationship Summary

| Relationship | Type | Enforced by |
|---|---|---|
| departments → employees | 1─N | `employees.department_id` FK, nullable, `restrictOnDelete` |
| customers → bookings | 1─N | `bookings.customer_id` FK, `restrictOnDelete` |
| bookings → booking_rooms | 1─N | `booking_rooms.booking_id` FK, `cascadeOnDelete` |
| rooms → booking_rooms | 1─N | `booking_rooms.room_id` FK, `restrictOnDelete` |
| room_types → rooms | 1─N | `rooms.room_type_id` FK, `restrictOnDelete` |
| room_types → room_images | 1─N | `room_images.room_type_id` FK, `cascadeOnDelete` |
| room_types → booking_rooms (snapshot) | 1─N | `booking_rooms.room_type_id` FK, `restrictOnDelete` |
| bookings → payments | **1─1** | `payments.booking_id` FK, **UNIQUE**, `cascadeOnDelete` |
| employees → payments (verifier) | 1─N | `payments.verified_by` FK, nullable, `nullOnDelete` |
| rooms → room_status_logs | 1─N | `room_status_logs.room_id` FK, `cascadeOnDelete` |
| employees → room_status_logs (actor) | 1─N | `room_status_logs.changed_by` FK, nullable, `nullOnDelete` |

## 3. Migration Order

FK-dependency order — each table only references tables created before it:

1. `departments` (no dependencies)
2. `customers` (no dependencies)
3. `employees` (→ departments)
4. `room_types` (no dependencies)
5. `rooms` (→ room_types)
6. `room_images` (→ room_types)
7. `bookings` (→ customers)
8. `booking_rooms` (→ bookings, rooms, room_types)
9. `payments` (→ bookings, employees)
10. `room_status_logs` (→ rooms, employees)

## 4. Important Constraints

**Unique:**
- `departments.name`, `room_types.name` — no duplicate reference data
- `customers.email`, `employees.email` — standard account uniqueness
- `rooms.room_number` — no duplicate physical rooms
- `bookings.booking_number` — human-facing reference code
- `payments.booking_id` — **enforces the approved 1─1 booking↔payment relationship at the DB level**, not just convention
- `booking_rooms(booking_id, room_id)` composite — prevents the same room being added twice within one booking (a real data-integrity bug this catches; **not** the same thing as full double-booking prevention across different bookings, which stays app-level per the Architecture Baseline — MySQL has no date-range exclusion constraint)

**Delete behavior:**
- `restrictOnDelete`: `employees→departments`, `rooms→room_types`, `booking_rooms→rooms`, `booking_rooms→room_types` — anything that would silently orphan or destroy historical data is blocked at the DB level, not just by convention
- `cascadeOnDelete`: `booking_rooms→bookings`, `room_images→room_types`, `payments→bookings`, `room_status_logs→rooms` — child records with no independent meaning once their parent is gone
- `nullOnDelete`: `payments.verified_by`, `room_status_logs.changed_by` — losing the employee record shouldn't destroy the payment/log, just the attribution

**Money:** every currency column is `decimal(10,2)` — `room_types.base_price`, `bookings.total_amount`, `booking_rooms.price_per_night`, `payments.amount`. Never float/double for money.

**Dates vs. timestamps:** `bookings.check_in_date`/`check_out_date` are `date` (night-level booking, no time component). `booking_rooms.actual_check_in_at`/`actual_check_out_at`, `rooms.status_changed_at`/`cleaning_started_at`, `payments.verified_at` are `timestamp` (the real moment something happened).

**Enums (native MySQL ENUM, via Laravel's `enum()`):** `rooms.status`, `bookings.status`, `payments.status`, `payments.method`, `employees.role`, `customers.status`, `employees.status` — all exactly the approved value lists, no extras.

## 5. Index Strategy

FK columns are auto-indexed by MySQL/InnoDB when the constraint is created — no manual duplicate needed. Added on top of that:

- `rooms.status` — hot-path query ("find available rooms")
- `bookings.status` — frequently filtered
- `bookings(check_in_date, check_out_date)` composite — supports the availability/overlap query
- `employees.role` — permission-matrix filtering
- `payments.status` — staff verification queue
- `room_images(room_type_id, sort_order)` composite — ordered gallery fetch
- `room_status_logs.room_id`, `room_status_logs.created_at` — history lookups and chronological reports

## 6. Remaining Database Decisions

Flagging these explicitly rather than deciding silently:

1. **`bookings` and `payments` do NOT get `deleted_at`.** Their lifecycle is already modeled by the `status` enum (`cancelled`, `refunded`, etc.) — there's no described scenario where a booking or payment row is ever actually deleted, only status-transitioned. Adding soft-deletes on top would be a redundant, unused mechanism (STEP 03's own "do not create unnecessary [fields]" rule). `customers`, `employees`, `rooms`, `room_types` keep soft-deletes exactly as the Architecture Baseline specified.
2. **`room_status_logs` has no `updated_at`** — only `created_at`. It's an append-only audit trail; a log row is never modified after creation, so a second timestamp column would just sit unused.
3. **`room_status_logs.from_status`/`to_status` are plain `string(20)`, not the same native ENUM as `rooms.status`.** A supplementary audit table shouldn't be schema-coupled to the live status enum — if a status value is ever added later, the audit table shouldn't need its own migration just to stay able to log it.
4. **`booking_rooms.room_type_id` stays a real, enforced FK** (not a loose denormalized value) even though it's conceptually a "snapshot." This is safe because `room_types` uses soft-delete, never hard-delete in normal operation — so the FK never dangles, and we keep full referential integrity on the snapshot.
5. **Default assumptions made without an explicit spec** (flagging so you can override): `customers.phone`/`address` nullable, `rooms.floor` nullable, `room_types.name` unique. Reasonable defaults, not architecture calls I'm confident enough to treat as settled.
6. **No seed data at all**, not even reference data like a starter department list. Nothing was "absolutely necessary" for migrations to run and test, and guessing at a real department list felt like inventing business data rather than designing a database. Deferred to whichever step actually owns that decision.
7. **Resolves the STEP 02 flag:** the default `users`/`cache`/`jobs` migrations, `User` model, and `UserFactory` are removed (not just dormant) — our schema uses `customers`/`employees` instead of a generic `users` table, and `CACHE_STORE=file`/`QUEUE_CONNECTION=sync` means the cache/jobs tables were never going to be used. `config/auth.php` is deliberately **left untouched** — reconfiguring guards/providers is authentication behavior, STEP 04's scope, not database design.

---

Implementation follows below.
