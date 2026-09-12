# STEP 10 — Payment & Payment Receipt Management — Implementation Report

Plan is in `docs/STEP-10-PAYMENT-PLAN.md` (written first, per instruction,
including the pre-coding review of the existing payments migration, the
`Payment` stub, the `receipts` disk and the STEP 09 `BookingService`). This
covers what was actually built on top of it.

---

## 1. Files Created

- `app/Services/PaymentService.php` — create / uploadReceipt / verify / reject
- `app/Policies/PaymentPolicy.php` — two-actor policy (§3 matrix)
- `app/Http/Controllers/Api/V1/Customer/PaymentController.php`
- `app/Http/Controllers/Api/V1/Staff/PaymentController.php`
- `app/Http/Requests/Payment/CreatePaymentRequest.php`
- `app/Http/Requests/Payment/UploadReceiptRequest.php`
- `app/Http/Requests/Payment/RejectPaymentRequest.php`
- `app/Http/Requests/Payment/FilterRequest.php`
- `config/payment.php`
- `docs/STEP-10-IMPLEMENTATION-REPORT.md` (this file)
- `tests/Feature/Payment/` — 56 new tests across 6 files:
  `PaymentCreateTest.php`, `ReceiptUploadTest.php`, `ReceiptDownloadTest.php`,
  `StaffPaymentReviewTest.php`, `PaymentVerifyBookingTest.php`,
  `PaymentPolicyTest.php`

## 2. Files Modified

- `app/Models/Payment.php` — real model: `$fillable = ['method']` only;
  `booking()`, `verifiedBy()` (soft-delete-aware), casts (`amount`
  decimal:2, `verified_at` datetime), `isVerified()` / `isRejected()`.
  `status`, `receipt_path`, `verified_at`, `verified_by`, `notes`, `amount`,
  `booking_id` are **never** mass-assignable.
- `app/Services/BookingService.php` — extracted the inner
  `pending_payment → confirmed` transition into
  `confirmWithinTransaction(Booking $booking): void` (no behavior change —
  `confirm()` keeps precheck, expiry auto-cancel-then-409, and wraps the
  transition in its own transaction). Also extracted
  `cancelExpiredHold(Booking $booking): void` so the expiry auto-cancel is
  shared by `confirm()` and `PaymentService::verify()`.
- `routes/api.php` — 10 payment routes (5 customer + 5 staff).
- `.env` / `.env.example` — `PAYMENT_RECEIPT_MAX_KB=4096`.
- `tests/Pest.php` — STEP 10 helpers: `fakeReceiptFile()` (content-genuine
  uploads so the `mimes` allowlist really validates), `makePaymentFor()`.

**No migration** — the STEP 03 `payments` table already satisfied every
requirement (`booking_id` UNIQUE, method/status enums incl. `rejected` and
`refunded`, `verified_by` FK, no `deleted_at`).

## 3. API Endpoints

| Method | URL | Middleware | Roles |
|---|---|---|---|
| POST | `/api/v1/customer/payments` | auth+actor:customer | customer (own booking only) |
| GET | `/api/v1/customer/payments` | auth+actor:customer | customer (own only, `?status=`) |
| GET | `/api/v1/customer/payments/{payment}` | auth+actor:customer | customer (own only) |
| POST | `/api/v1/customer/payments/{payment}/receipt` | auth+actor:customer | customer (own only) |
| GET | `/api/v1/customer/payments/{payment}/receipt` | auth+actor:customer | customer (own only) |
| GET | `/api/v1/staff/payments` | auth+actor:staff | any staff (`?status=&search=&per_page=`) |
| GET | `/api/v1/staff/payments/{payment}` | auth+actor:staff | any staff |
| GET | `/api/v1/staff/payments/{payment}/receipt` | auth+actor:staff | any staff |
| POST | `/api/v1/staff/payments/{payment}/verify` | auth+actor+role | admin, manager, receptionist |
| POST | `/api/v1/staff/payments/{payment}/reject` | auth+actor+role | admin, manager, receptionist |

Money/status/verification fields are never accepted anywhere; requests only
carry `booking_id`+`method` (create), `receipt` file (upload), `reason`
(reject), and filters (`status`/`search`/`per_page` capped at 50).

## 4. Payment State Transitions (implemented)

```
pending ──upload──▶ receipt_uploaded ──staff verify──▶ verified  (booking confirms atomically)
                          │      ▲
                          │      └──customer re-upload── rejected (file replaced)
                          └──staff reject──▶ rejected (notes = reason, file kept)
```
- `verified → refunded` — reserved (no endpoint; refunds out of scope).
- Approved decision honored: `rejected` is a **persistent**, observable
  state; re-upload performs `rejected → receipt_uploaded` on the same row;
  there is **no** automatic `rejected → pending` reset.
- Invalid transitions → 409 `BusinessRuleException`. `verified`/`refunded`
  are terminal (no upload/re-verify/reject).

## 5. Security & Money Guarantees

- **Server-side money:** `amount = booking.total_amount` read from a
  `lockForUpdate` row inside the create transaction. A customer sending
  `amount`, `status`, `verified_at`, `verified_by`, `customer_id` gets them
  silently ignored (verified by `PaymentCreateTest`).
- **1:1 booking↔payment:** service pre-check + `exists` re-check under lock
  (409) with the DB UNIQUE constraint as the backstop.
- **Ownership, no `customer_id` column:** every customer payment query is
  scoped `whereHas('booking', customer_id = me)` + `findOrFail` → another
  customer always gets a clean 404 (create, show, upload, download).
- **Verify is atomic** (one `DB::transaction`, both rows locked): payment
  `received_uploaded→verified` + `verified_at/verified_by` set + booking
  `pending_payment→confirmed` + rooms `available→reserved` + `room_status_logs`
  audit row — all commit or roll back together. Reject and upload are each
  single transactions; upload replaces the old file only after the new file
  is stored and the row updated (orphan-file cleanup on DB failure).
- **Privacy:** receipts only on the private `receipts` disk at deterministic
  `{booking_number}/{uuid}.{ext}` (uuid, never client filename), served only
  through authenticated, policy-checked routes; missing file / no path → 404.
- **Expired hold:** create/upload remain possible while the booking is still
  `pending_payment`; verify hits the STEP 09 rule — booking auto-cancelled
  (committed in its own transaction) then 409.
- **Terminal booking** (`cancelled`/`checked_out`) → 409 on create/upload/
  verify/reject.

## 6. Test Count / Result

- Full suite: **298 passed** (669 assertions) — 242 pre-existing STEP 01–09
  tests stayed green + **56 new STEP 10 tests**, 0 failures.
- Runtime ~2.4s (sqlite; tests use `Storage::fake('receipts')`).

## 7. Lint Result

`php -l` clean on all created/modified PHP files (20 files). No temporary or
debug files remain (`tests/Feature/Payment/` has no Debug file; none added).

## 8. Issues Discovered (and how they were resolved)

1. **`tests/Pest.php` helper:** `Payment::create(['method'])` fired the
   INSERT before `booking_id` was assignable (NOT NULL) → switched to
   `new Payment(['method'])` + direct assignment + `save()`.
2. **Staff `verify` controller:** `$this->user()` is not available from
   `AuthorizesRequests` — switched to injecting `Request` and
   `$request->user()`.
3. **403-vs-404 on customer receipt route:** `SubstituteBindings` runs before
   route middleware in the pipeline, so a staff token hitting
   `/customer/payments/{missingId}/receipt` yields 404 (binding) instead of
   403 (actor). The staff-403 test now uses a real payment, matching how
   every existing route behaves.
4. **Duplicate payment in `PaymentPolicyTest`** from calling
   `staffOf('admin')` twice per loop → created each staff row once and reused.
5. **Small plan deviation (safety-relevant, flagged):** plan §3 prose said
   `viewAny → Employee only`, but §2 + the §3 matrix grant customers their own
   list. Implemented `viewAny` as `Customer || Employee`, with the customer
   list additionally scoped by `whereHas('booking', customer_id)` so it can
   only ever see its own rows. Also `uploadReceipt` is customer-owned only in
   the policy (the matrix shows staff `–`; there is no staff upload route),
   and `PaymentService::reject()` takes no `Employee` argument because a
   rejection sets only `status` + `notes` — `verified_at/by` are untouched and
   identity is enforced by the `role` middleware + `PaymentPolicy`.
6. **Confirm-vs-verify sharing:** the exact same `pending_payment → confirmed`
   (+ room transitions + audit log) path now runs for staff `confirm` and
   payment `verify`; both STEP 09 and STEP 10 test suites pass unchanged.

## 9. Conformance Verification (STEP 10 review pass)

Independent reconciliation of the existing STEP 10 module against
`docs/STEP-10-PAYMENT-PLAN.md` (per the STEP 09→10 hand-off). No application
code was changed — this is a verification record.

| Plan § | Requirement | Verified evidence | Result |
|---|---|---|---|
| §2 API | customer create/index/show + receipt upload/download, 201/404/409 | `Customer/PaymentController`, PaymentCreateTest (11), ReceiptUploadTest (12), ReceiptDownloadTest (6) | ✓ |
| §2 API | staff index/show/download + verify/reject, role matrix | `Staff/PaymentController`, StaffPaymentReviewTest (11), PaymentVerifyBookingTest (10) | ✓ |
| §3 Authz | two-actor `PaymentPolicy`; roles admin/manager/receptionist for verify/reject | `app/Policies/PaymentPolicy.php`, PaymentPolicyTest (6) | ✓ |
| §4 Lifecycle | pending→receipt_uploaded→verified/rejected, rejected→re-upload, verified terminal | ReceiptUploadTest, PaymentVerifyBookingTest, StaffPaymentReviewTest | ✓ |
| §5 Money | `amount = booking.total_amount` under `lockForUpdate`; injection ignored; 1:1 duplicate 409 + DB UNIQUE backstop | `PaymentService::create`, PaymentCreateTest | ✓ |
| §6 Receipts | private `receipts` disk, mimes/size, deterministic `{number}/{uuid}.{ext}`, old-file replace, missing→404 | `UploadReceiptRequest`, `PaymentService::uploadReceipt`, ReceiptUploadTest, ReceiptDownloadTest | ✓ |
| §7 Verify | atomic payment-verified + booking-confirmed + room-reserved + audit row via shared `confirmWithinTransaction`; expiry auto-cancel→409; double-verify 409 | `PaymentService::verify`, `BookingService::confirmWithinTransaction`, PaymentVerifyBookingTest | ✓ |
| §7 Reject | only `receipt_uploaded`, booking `pending_payment`, reason→notes (no `verified_at/by`) | `PaymentService::reject`, StaffPaymentReviewTest | ✓ |
| §8 Model | `Payment::$fillable = ['method']` only; `verifiedBy()` soft-delete-aware; casts; helpers | `app/Models/Payment.php` | ✓ |
| §9/No migration | no schema change; `payments` migration from STEP 03 satisfies everything | `ls database/migrations/` = 13 (unchanged) | ✓ |
| §10 Edge | cross-ownership 404, guests 401, wrong actor 403, terminal booking 409, verification 409s | covered across the 56 tests | ✓ |

**Flagged-deviation review (report §5):** the three documented deviations
(`viewAny` Customer||Employee, `uploadReceipt` customer-only, `reject()` no
`Employee` argument) were re-checked against the plan's §2 contract + §3
matrix and are confirmed defensible readings with no scope creep; no changes
made.

**Fences honored:** STEP 11 refund module (`RefundTest`, 19) left untouched
and green; STEP 12 staff-create absent (artifacts removed earlier); STEP 13
cleaning sweep untouched; no migration; no STEP 09 booking/room behavior
modified.

**Verification result:** `php artisan test` → **319 passed (732 assertions), 0
failures** (~3.2s, sqlite). `php -l` clean on every STEP 10 PHP file.
`php artisan route:list --path=payment` shows the 5 customer + 5 staff STEP 10
routes (+1 STEP 11 refund route). No temporary/debug files.