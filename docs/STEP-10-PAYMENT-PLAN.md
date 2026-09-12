# STEP 10 — Payment & Payment Receipt Management Plan (review before implementation below)

## 0. Pre-coding Review (read the actual project, not assumptions)

**Schema — complete, no migration needed.** STEP 03 created the full
`payments` table and every constraint this step needs (verified against the
actual migration file and `DatabaseSchemaTest`):

- `payments`: `booking_id` FK `cascadeOnDelete` **UNIQUE** (enforces the
  approved 1:1 booking↔payment at the DB level), `amount` decimal(10,2),
  `method` enum `[bank_transfer, cash]`, `status` enum
  `[pending, receipt_uploaded, verified, rejected, refunded]` default
  `pending`, `receipt_path` nullable, `verified_by` FK→employees nullable
  `nullOnDelete`, `verified_at` timestamp nullable, `notes` text nullable,
  `timestamps`. **No `deleted_at`** — lifecycle is status transitions
  (STEP 03 §6). `status` index already present.
- `bookings` already has `payment()` → `Payment::class` (no longer a latent
  error since STEP 09); `booking.status` sits OUTSIDE `$fillable` already.

**Existing `Payment` model** is a minimal stub ("Full Payment module is STEP
0X") with `booking_id, amount, method, status, receipt_path, verified_by,
verified_at, notes` in `$fillable`. STEP 10 replaces this with the real
model: **`status`, `verified_at`, `verified_by` must NOT be mass-assignable**
(requirement 5) — same pattern as `Booking.status` / `Room.status`: every
status/verification change goes through `PaymentService` via direct property
assignment + `save()`, never from client input.

**Storage:** `config/filesystems.php` already has the `receipts` disk —
private, no `url`, no symlink in `links` (never publicly reachable). Every
read goes through an authenticated, policy-checked route. Exactly the
Architecture Baseline §14/§18 model.

**STEP 09 conflict to reconcile (flag):** booking `pending_payment → confirmed`
is currently the staff `confirm` endpoint's job (role admin/manager/receptionist).
STEP 10 adds a second trigger: `payment verified → booking confirmed`. Both stay;
they share one code path (`BookingService` confirm logic extracted so the same
transition runs inside the payment-verification transaction).

**Existing conventions reused:** `actor:customer`/`actor:staff` middleware,
`role:admin,manager,receptionist`, `ApiFormRequest` (shape-only), `ApiResponse`
envelope, `BusinessRuleException → 409`, two-actor `$user` policy pattern,
`lockForUpdate` + `DB::transaction` from STEP 09, `whereHas(...)->findOrFail()`
ownership scoping (404-unless-own), Pest helpers in `tests/Pest.php`.

## 1. Scope

**In (working end-to-end MVP):** customer creates one payment for one of
their own bookings (amount locked to the booking's server-computed total),
customer uploads a receipt (private disk), customer lists/views/downloads own
payments, staff list/view/download any payment, staff verify (auto-confirms
the booking atomically) and reject, one-payment-per-booking prevention,
server-side money/ownership/status hardening, RBAC, transaction safety.

**Out (deliberately):** refunds **processing** (the `refunded` status exists
in the enum but has no endpoint or money-movement logic), payment gateways /
3rd-party providers, webhooks, invoices, notifications/emails, staff creating
payments for a booking, editing an existing payment, deleting payments,
check-in/check-out/cleaning (their STEP).

## 2. API Contract

### Customer — `/api/v1/customer/payments` — group middleware `['auth:sanctum', 'actor:customer']`

| Method | URL | Request | Notes |
|---|---|---|---|
| POST | `/payments` | `{booking_id, method}` | 201 `pending`; amount = `booking.total_amount`; 409 if a payment already exists for that booking |
| GET | `/payments` | `?status=` | own only, paginated (15) |
| GET | `/payments/{payment}` | – | 404 unless own |
| POST | `/payments/{payment}/receipt` | multipart `receipt` file | `pending`/`rejected`/`receipt_uploaded` → `receipt_uploaded`; re-upload replaces file |
| GET | `/payments/{payment}/receipt` | – | stream/download own receipt from the private disk |

### Staff — `/api/v1/staff/payments` — group middleware `['auth:sanctum', 'actor:staff']`

| Method | URL | Route middleware | Notes |
|---|---|---|---|
| GET | `/payments` | none | all, paginated (15), `?status=&search=` (booking_number / customer name / email) |
| GET | `/payments/{payment}` | none | any staff, eager `booking.customer`, `verifiedBy` |
| GET | `/payments/{payment}/receipt` | none | any staff, stream from private disk |
| POST | `/payments/{payment}/verify` | `role:admin,manager,receptionist` | `receipt_uploaded` → `verified` (verified_at/verified_by) **and** booking `pending_payment → confirmed` (one transaction) |
| POST | `/payments/{payment}/reject` | `role:admin,manager,receptionist` | `receipt_uploaded` → `rejected`, `reason` required → saved to `notes` |

All business-state conflicts → **409 Conflict** (`BusinessRuleException`).
Wrong actor → 403 (actor/role middleware); guest → 401; owns-nothing found →
404. Money/status/verified_at are **never** accepted on any request.

## 3. Authorization Matrix

| | Admin | Manager | Receptionist | Customer (own) | Guest |
|---|:---:|:---:|:---:|:---:|:---:|
| Create payment (their booking) | – | – | – | ✅ | ❌ |
| List/view/download own payment + receipt | – | – | – | ✅ | ❌ |
| Staff list/view/download any payment/receipt | ✅ | ✅ | ✅ | – | ❌ |
| Verify payment | ✅ | ✅ | ✅ | ❌ | ❌ |
| Reject payment | ✅ | ✅ | ✅ | ❌ | ❌ |

`PaymentPolicy` follows the STEP 09 two-actor pattern (untyped `$user`, branch
on `Customer`/`Employee`): `create` → Customer only; `viewAny` → Employee only;
`view` → Customer must own the **payment's booking** (`booking.customer_id`),
Employee → any staff; `verify`/`reject` → Employee with role
`admin,manager,receptionist` (matches STEP 09's confirm/check-in role set —
rejection is the operational flip side of review; refunds/management past this
are out of scope). Two-layer enforcement on staff routes: `role:*` middleware
**and** `$this->authorize()`. Customer ownership enforced **twice**: policy +
query scoping → a bad route can't leak another customer's payments.

## 4. Payment Status Lifecycle

Approved DB enum: `pending, receipt_uploaded, verified, rejected, refunded`.

```
pending ──upload receipt──▶ receipt_uploaded ──staff verify──▶ verified ──(future)──▶ refunded
                              │      ▲
                              │      └──customer re-upload── rejected
                              └──staff reject──▶ rejected
```

| From | To | Trigger |
|---|---|---|
| `pending` | `receipt_uploaded` | customer uploads receipt |
| `receipt_uploaded` | `verified` | staff verify — sets `verified_at`, `verified_by`; booking confirms atomically |
| `receipt_uploaded` | `rejected` | staff reject — `notes` = reason, keeps file for audit |
| `rejected` | `receipt_uploaded` | customer uploads a corrected receipt (replaces file) |
| `verified` | `refunded` | **reserved; no endpoint** (refunds out of scope) |

Invalid transition → **409**. `verified`/`refunded` are terminal for this step
(no upload/re-verify/reject).

**Interpretation of requirement "rejection -> pending" (flag):** after a
rejection the payment returns to the *re-submittable* (pending-attention) flow
— the booking stays `pending_payment`, no second payment row is ever created
(1:1 rule), the customer re-uploads on the **same** payment row
(`rejected → receipt_uploaded`). `rejected` is kept as a **persistent,
observable** state because the approved STEP 03 enum defines it as a durable
value; an auto-reset `rejected → pending` would make `rejected` permanently
unobservable. If you want a literal auto-reset to `pending` instead, say so at
approval and I'll adjust the transition table (a `rejected → pending` staff or
system step; the enum stays valid either way).

## 5. Creating a Payment — Server-Side Money

- `POST /customer/payments` takes **only** `booking_id` + `method`.
- Controller scopes the booking by the current customer
  (`Booking::where('customer_id', $user->id)->findOrFail($booking_id)` → 404
  for another customer's booking), then `authorize('create', ...)`.
- `PaymentService::create(Booking $booking, string $method)`:
  - opens a transaction, **locks the booking row** (`lockForUpdate`),
  - checks `Payment::where('booking_id', ...)->exists()` → **409**
    ("A payment already exists for this booking") — the DB UNIQUE is the
    backstop (req. 13),
  - `amount = $booking->total_amount` (typed `decimal:2`, never floats,
    never from client — `amount` is NOT fillable),
  - `status = 'pending'` via direct assignment.
- If the booking is terminal (`cancelled`/`checked_out`) → 409.
- `Payment::$fillable = ['method']` only. No client key can set
  `customer_id` (column doesn't exist — ownership always resolves through the
  booking), `booking_id` (service sets it), `amount`, `status`,
  `verified_at`, `verified_by`, `receipt_path`.

## 6. Receipts — Validation, Private Storage, Serving

- **Validation (`UploadReceiptRequest`):** `receipt` required, `file`,
  `mimes:jpeg,png,pdf`, `max:{config('payment.receipt_max_kb', 4096)}`.
- **Storage (`PaymentService::uploadReceipt`):** path
  `"{booking.number}/{uuid}.{extension}"` on the **`receipts` disk**
  (`storage/app/private/receipts`) — private, no public URL. `receipt_path`
  stores the disk-relative path, never an absolute path or URL.
- **Re-upload** (from `receipt_uploaded` or `rejected`): replaces the file —
  old file deleted from the disk, path updated.
- **Reject** keeps the rejected file on disk (audit trail).
- **Serving:** `GET …/payments/{payment}/receipt` streams via
  `Storage::disk('receipts')->response($path)` through an authenticated +
  policy-checked route only. No public filesystem link, no `url` anywhere.
- Upload is allowed while the booking is `pending_payment` or `confirmed`;
  terminal booking → 409.

## 7. Verify and the Booking Transition (Transaction Boundaries)

`PaymentService::verify(Payment $payment, Employee $employee)`:

1. Pre-check outside the transaction: `status === 'receipt_uploaded'` else
   409; booking terminal (`cancelled/checked_out`) → 409.
2. **Expired hold:** if booking `isExpired()` (past `payment_deadline_at`
   while still `pending_payment`) → auto-cancel the booking (committed in its
   own transaction, mirroring STEP 09 `confirm`) then throw **409**
   ("booking has expired and has been automatically cancelled").
3. **One transaction** covering, atomically:
   - lock the **payment row** (`lockForUpdate`),
   - lock the **booking row** (`lockForUpdate`) — serializes double-verify,
   - set `status = 'verified'`, `verified_at = now()`,
     `verified_by = $employee->id` (direct assignment),
   - booking `pending_payment → confirmed`: room transitions
     `available → reserved` + `room_status_logs` audit row — via the shared
     `BookingService` confirm logic, **inside this same transaction**
     (room/booking/payment all roll back together; req. 14).
   - If the booking is already `confirmed` (staff confirmed independently):
     no booking change — verify is safe/idempotent for the payment.

**`BookingService` refactor (careful, no behavior change):** extract the inner
transition (status check, `status=confirmed`, room `reserved` transitions) into
`confirmWithinTransaction(Booking $booking): void` — no transaction wrapper, no
prechecks. `confirm()` keeps its current public behavior (precheck, expiry
auto-cancel-then-409, wraps `confirmWithinTransaction` in its own transaction).
`PaymentService::verify` calls the same `confirmWithinTransaction` inside *its*
transaction. All existing STEP 09 booking/room tests must stay green.

**Reject** (`PaymentService::reject(Payment, Employee, string $reason)`):
`status === 'receipt_uploaded'` else 409; booking must be `pending_payment`
(a payment for an already-confirmed booking can't be "rejected" into limbo —
if it wasn't needed it's non-actionable; 409 instead); set `status='rejected'`,
`notes=$reason` in a transaction. Reject sets nothing on `verified_at`.

## 8. Models / Service / Policy / Requests

**Model (modified) `Payment`:** `$fillable = ['method']`; add
`verifiedBy()` → belongsTo `Employee` (with soft-delete), keep
`booking()`; casts `amount → decimal:2`, `verified_at → datetime`; helpers
`isVerified()`/`isRejected()`; **no SoftDeletes** (schema says so).

**Service (new) `PaymentService`:** `create()`, `uploadReceipt()`,
`verify()`, `reject()` as above. All status/verification writes are direct
property assignment (never mass-assignment).

**Policy (new) `PaymentPolicy`:** §3.

**Controllers (new):** `Api/V1/Customer/PaymentController`
(`store/index/show/uploadReceipt/downloadReceipt`),
`Api/V1/Staff/PaymentController`
(`index/show/downloadReceipt/verify/reject`).

**Requests (new, all extend `ApiFormRequest`):**
`Payment/CreatePaymentRequest` (`booking_id` required int; `method` required
`in:bank_transfer,cash` — ownership checked in the controller, not the shape
rule), `Payment/UploadReceiptRequest` (file), `Payment/RejectPaymentRequest`
(`reason` required string max 255), `Payment/FilterRequest`
(`status` in the 5 enum values; `search` max 255; `per_page` capped like the
existing room convention — safe max 50, else 422).

## 9. Files New / Modified

| File | Change |
|---|---|
| `app/Models/Payment.php` | real model: `$fillable=['method']`, relations, casts, helpers |
| `app/Services/PaymentService.php` | new (create / upload / verify / reject) |
| `app/Services/BookingService.php` | extract `confirmWithinTransaction()` (no behavior change) |
| `app/Policies/PaymentPolicy.php` | new (two-actor, §3) |
| `app/Http/Controllers/Api/V1/Customer/PaymentController.php` | new |
| `app/Http/Controllers/Api/V1/Staff/PaymentController.php` | new |
| `app/Http/Requests/Payment/{CreatePayment,UploadReceipt,RejectPayment,Filter}Request.php` | new |
| `config/payment.php` + `.env` / `.env.example` | `receipt_max_kb` (4096), allowed mimes |
| `routes/api.php` | payment routes (§2) |
| `docs/STEP-10-IMPLEMENTATION-REPORT.md` | written after implementation |

**No migration.** Schema already satisfies everything — verified against the
actual `2025_02_01_000009_create_payments_table.php` migration and the
`DatabaseSchemaTest` assertions (`payments.booking_id` unique, no
`deleted_at`).

## 10. Edge Cases & Security Cases

- **Ownership without a `customer_id` column:** all customer payment queries
  scope via `whereHas('booking', fn ($q) => $q->where('customer_id', $user->id))`
  + `findOrFail()` → another customer always gets **404**, never 403/200.
- **Guests → 401**; wrong actor (staff on customer routes, customer on staff
  routes) → **403**; wrong role (e.g. a lone receptionist vs verify — allowed,
  but a bogus role) → **403**; a customer attempting verify/reject → **403**.
- **Duplicate payment for one booking → 409** (service check + DB UNIQUE).
- **Terminal booking** (`cancelled`/`checked_out`) → create/upload/verify/reject
  all **409**.
- **Expired hold** (`pending_payment`, deadline passed, not yet swept): create/
  upload still possible while booking remains `pending_payment`; verify hits the
  STEP 09 rule → booking auto-cancelled → **409**.
- **Amount/status injection:** client-sent `amount`, `status`, `verified_at`,
  `verified_by`, `customer_id` silently ignored (not fillable; service derives
  values). Test proves it.
- **Double-verify / verify on verified → 409**; upload/reject on verified →
  **409**; re-upload on verified → **409** (terminal).
- **Reject on an already-confirmed booking → 409** (nothing to reject).
- **Files:** extension+mime whitelist, size cap; deterministic path
  (`booking_number/uuid.ext`) — no user-supplied filename or path traversal;
  private disk only; missing `receipt_path` on download → 404; deleted/absent
  file → 404.
- **Transaction safety:** verify = single transaction (payment + booking +
  room + audit log). Reject = single transaction. Upload = file write +
  transaction, old file cleanup on replace.
- **Tests use `Storage::fake('receipts')`** so nothing touches the real disk.

## 11. Test Plan (~40–46 tests)

`tests/Feature/Payment/`:

- **`PaymentCreateTest`** — golden path (status `pending`, `amount` =
  booking total, method stored); amount-injection silently ignored; duplicate
  payment → 409; another customer's booking → 404; non-existent booking →
  404; cancelled booking → 409; method/booking_id validation → 422; guest → 401;
  staff → 403.
- **`ReceiptUploadTest`** — `pending→receipt_uploaded`; `rejected→receipt_uploaded`;
  re-upload replaces file (old removed); file stored on `receipts` disk + path
  persisted; bad mime / too large / missing → 422; own-only → 404; verified →
  409; terminal booking → 409; guest/staff matrix.
- **`ReceiptDownloadTest`** — customer downloads own (200, PDF content-type);
  other customer → 404; staff can download any; missing file → 404; guest 401.
- **`StaffPaymentReviewTest`** — list all + pagination meta + `?status=`
  filter + `?search=`; show with booking/customer/verifier; receptionist,
  manager, admin all verify; reject requires reason (422) and sets
  `rejected` + notes; invalid transition → 409; role/actor 403s.
- **`PaymentVerifyBookingTest`** — verify → booking `confirmed` + room
  `reserved` + room-status-log row (transaction proves atomic); booking already
  confirmed → verify still ok, no double room transition; cancelled booking →
  409; expired booking → auto-cancel + 409; second verify → 409.
- **`PaymentPolicyTest`** — direct policy unit checks for the matrix (§3);
  customer cross-ownership 404; token/actor separation.

Existing 242 tests must stay green (BookingService refactor is behavior-
neutral; Payment fillable narrowing affects nothing external).

## 12. Wait for Approval

Plan only. No implementation until approved.