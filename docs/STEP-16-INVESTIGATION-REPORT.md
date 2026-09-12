# STEP 16 — Investigation Report

## 1. Repository Evidence

### Documents found in `docs/`

| File | Lines | Exists? |
|---|---|---|
| `STEP-16-IMPLEMENTATION-PLAN.md` | 593 | **YES** |
| `STEP-16-IMPLEMENTATION-REPORT.md` | 209 | **YES** |
| `STEP-17-IMPLEMENTATION-PLAN.md` | 335 | YES (references STEP 16) |
| `STEP-17-IMPLEMENTATION-REPORT.md` | 210 | YES (references STEP 16) |
| `STEP-15-IMPLEMENTATION-PLAN.md` | 715 | YES (out-list cites invoices) |
| `STEP-15-IMPLEMENTATION-REPORT.md` | 187 | YES |

### Application code referencing STEP 16

| File | STEP 16 content |
|---|---|
| `app/Models/Invoice.php` | Immutable billing record, `belongsTo(Payment)` |
| `app/Models/InvoiceItem.php` | Persisted line-item snapshot |
| `app/Services/InvoiceService.php` | `generateForPayment()` + `INV-` number generator |
| `app/Services/PaymentService.php` | Invoice generation appended inside `verify()` transaction |
| `app/Models/Payment.php` | `invoice(): hasOne` relation |
| `app/Policies/InvoicePolicy.php` | Two-actor read-only policy |
| `app/Http/Controllers/Api/V1/Customer/InvoiceController.php` | Customer read-only index/show |
| `app/Http/Controllers/Api/V1/Staff/InvoiceController.php` | Staff read-only index/show |
| `app/Http/Requests/Invoice/FilterRequest.php` | Shape-only index filters |
| `routes/api.php` | 4 read-only invoice routes (lines 94-96, 225-227) |

### Migrations referencing STEP 16

| File | Content |
|---|---|
| `database/migrations/2025_02_10_000001_create_invoices_table.php` | `invoices` table (1:1 with payments) |
| `database/migrations/2025_02_10_000002_create_invoice_items_table.php` | `invoice_items` snapshot table |

### Tests referencing STEP 16

| File | Tests |
|---|---|
| `tests/Feature/Invoice/InvoiceGenerationTest.php` | 11 tests (generation, snapshots, no-invoice paths, double-verify, atomic rollback, injection, refund survival, schema) |
| `tests/Feature/Invoice/StaffInvoiceTest.php` | 8 tests (index/search/roles/filter/401-403/shape/refund) |
| `tests/Feature/Invoice/CustomerInvoiceTest.php` | 7 tests (own-only, 404 on foreign, actor 403s, refund, empty list, guest 401) |

**Total new STEP 16 tests: 26**

### No other references found

- "DebtCollector": 0 matches across entire repo
- "MainStream": 0 matches across entire repo
- TODO/FIXME in project code: 0 matches (only in `vendor/`)
- Git history: not available (not a git repository)

---

## 2. All Plausible STEP 16 Candidates

There is only **one** candidate. The evidence is conclusive:

### Candidate: Invoices (billing records for verified payments)

| Criterion | Evidence |
|---|---|
| **Source document** | `docs/STEP-16-IMPLEMENTATION-PLAN.md` — 593 lines, titled "STEP 16 — Invoices (billing records for verified payments)" |
| **Implementation report** | `docs/STEP-16-IMPLEMENTATION-REPORT.md` — 209 lines, confirms all §B decisions approved, all code built, 421 tests green |
| **Explicitly deferred?** | Yes — catalog item #1 in STEP-16-PLAN §0.1 (:33), sourced from STEP-10-PAYMENT-PLAN.md:56, STEP-12-PLAN.md:86, STEP-13-PLAN.md:106, STEP-15-PLAN.md:150/667 |
| **Already implemented?** | **YES — fully implemented and verified** (421 tests / 1232 assertions / 0 failures at completion) |
| **STEP 17 depends on it?** | **YES** — STEP-17-PLAN.md:16 sets baseline to "421 tests / 1,232 assertions" (the STEP 16 completion state); STEP-17-REPORT.md:135 references STEP 16 invoice invariant |
| **Confidence** | **HIGH — conclusive** |

---

## 3. Evidence for and Against Each Candidate

### Invoices (the only candidate)

**Evidence FOR:**
1. Full 593-line implementation plan exists at `docs/STEP-16-IMPLEMENTATION-PLAN.md`, line 1: `"STEP 16 — Invoices (billing records for verified payments) — Implementation Plan"`
2. Full 209-line implementation report exists at `docs/STEP-16-IMPLEMENTATION-REPORT.md`, line 1: `"STEP 16 — Invoices (billing records for verified payments) — Implementation Report"`
3. 13 application files implement the feature (models, service, policy, controllers, requests, migrations)
4. 26 tests exist in `tests/Feature/Invoice/` across 3 test files
5. STEP 17 plan explicitly references STEP 16 completion state as its baseline
6. STEP 16 plan §0.1 details the exact deferred-items catalog derivation
7. STEP 15 plan §25 out-list: "❌ No pricing tiers, discounts, invoices, ledgers, notifications..." — invoices were explicitly deferred from STEP 15

**Evidence AGAINST: none.**

---

## 4. Whether the Candidate Is Already Implemented

**YES — fully implemented.**

From `docs/STEP-16-IMPLEMENTATION-REPORT.md`:
- §9 Verification: "421 passed / 1232 assertions / 0 failures (baseline 395 / 1082 — every pre-existing test green, zero edits to them)"
- §6: All §B decisions (D-S0 through D-6) approved and implemented, with one deliberate D-3 override (persisting line-item snapshots)
- §3: `PaymentService.php` modified with one appended call inside existing verify transaction
- §2: Both migrations applied and `Ran` in dev DB

**Test count progression confirms implementation:**
| Step | Tests | Assertions | Source |
|---|---|---|---|
| STEP 15 complete | 395 | 1,082 | STEP-15-REPORT.md:6 |
| STEP 16 complete | 421 (+26) | 1,232 (+150) | STEP-16-REPORT.md:6, §9 |
| STEP 17 complete | 447 (+26) | 1,386 (+154) | STEP-17-REPORT.md:6, §9 |

---

## 5. Relationship Between STEP 15, STEP 16, and STEP 17

```
STEP 15 (Late-Cancellation Fees)
  ├── defers: invoices, pricing tiers, notifications (§25 out-list)
  └── baseline: 371 tests / 958 assertions
  
STEP 16 (Invoices)
  ├── derives scope from deferred catalog (§0.1)
  ├── consumes deferred item #1 (Invoices)
  ├── defers: pricing tiers, notifications, idempotency keys, etc. (§0.1 table)
  └── baseline → completion: 421 tests / 1,232 assertions

STEP 17 (Pricing Tiers / Discounts)
  ├── derives scope from STEP 16's deferred catalog (§0.1)
  ├── consumes deferred item #2 (Pricing tiers / discounts)
  ├── baseline is STEP 16 completion state
  └── completion: 447 tests / 1,386 assertions
```

STEP 16 is the **direct predecessor** of STEP 17 and the **direct successor** of STEP 15. STEP 17's plan explicitly depends on STEP 16's completion state and references STEP 16's invoice invariant as a downstream reader.

---

## 6. Strongest Conclusion

**STEP 16 = Invoices (billing records for verified payments)**

This is not a candidate or inference — it is conclusively documented and implemented. The repository contains:
- A complete implementation plan (`STEP-16-IMPLEMENTATION-PLAN.md`)
- A complete implementation report (`STEP-16-IMPLEMENTATION-REPORT.md`)
- 13 application files implementing the feature
- 26 dedicated tests
- Integration points in `PaymentService`, `Payment` model, and `routes/api.php`

STEP 16 generated immutable invoice records (`INV-XXXXXXXX`) atomically inside `PaymentService::verify()`, with a 1:1 relationship to payments, read-only customer and staff endpoints, and a D-3 override to persist line-item snapshots in an `invoice_items` table.

---

## 7. Confidence Level

**HIGH — conclusive.** There is zero ambiguity. The plan, report, code, tests, and cross-references from STEP 15 and STEP 17 all unambiguously identify STEP 16 as Invoices.

---

## 8. Remaining Ambiguity

**None regarding what STEP 16 was.** 

The only minor observation: the user's prompt states "A STEP 16 implementation/verification report is missing from our current project history" — yet the repository contains both `STEP-16-IMPLEMENTATION-PLAN.md` and `STEP-16-IMPLEMENTATION-REPORT.md`. This suggests the prompt may be referencing a different tracking system (e.g., a checklist or session log) that lacks a STEP 16 entry, but the actual repository artifacts are complete and unambiguous.

---

## 9. Recommendation for the Next Action

STEP 16 is already fully implemented. No further action is required for STEP 16 itself.

If the goal is to verify STEP 16's current state matches its report, run:
```bash
php artisan test --filter=Invoice
```
This should show 26 tests passing with 150 assertions, matching the STEP-16-REPORT §9 numbers.

The next unimplemented step in the sequence would be **STEP 18** (if one is planned). The remaining deferred items from the catalog (per STEP-16-PLAN §0.1 and STEP-17-PLAN §0.1) are:
- Notifications / emails
- Idempotency keys
- Staff payment creation / editing / deletion
- Partial refunds / customer-initiated refunds / refund approval workflows
- Walk-in (anonymous) staff bookings
- Restorable room types
- Taxed invoices / PDF / emailed invoice documents

---

*Report generated from repository evidence only. No code was modified.*
