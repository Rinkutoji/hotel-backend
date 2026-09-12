# STEP 17 — PROPOSED, NOT APPROVED — Implementation Plan (planning only)

> **Status: PLAN ONLY — PROPOSED, NOT APPROVED.** No code, migration, test,
> route, config, `.env`, or behavior change is authorized by this document.
> This is a *proposed* STEP-17 scope derived **only** from explicitly deferred
> items **already documented** in STEP 08–16. Nothing here is invented, and no
> unsourced requirement appears.
>
> **Verified: no official STEP 17 spec exists.** `docs/` (STEP 02–16 only),
> the whole repo tree, and every sibling folder under
> `/Applications/XAMPP/xamppfiles/htdocs` contain **zero** references to a
> STEP 17 plan, report, or scope file (`grep -E "STEP 1[7]" -r /.../htdocs`
> → 0 matches; `find` for `*step-17*` → 0). Like STEP 16 (per its own §0.1
> header), this plan derives scope from the documented deferred-items catalog
> (STEP-16-IMPLEMENTATION-PLAN.md §0.1) and stops for review against the
> current regression baseline **421 tests / 1,232 assertions / 0 failures**.

---

## 0. Pre-Coding Review — Where a STEP 17 could come from

### 0.1 The deferred-items catalog (verbatim reference, not re-derived)

STEP-16-IMPLEMENTATION-PLAN.md §0.1 (:22–40) is the project's own complete
catalog of every "deliberately out of scope / deferred" line in STEP 09–15.
STEP 16 consumed catalog item **#1 (Invoices)**. The catalog's *remaining*
items, with their original citations (each verified against the current
`docs/`):

| # | Deferred item | Documented in (verified lines) | Status today |
|---|---|---|---|
| 2 | **Pricing tiers / discounts** | STEP-09-BOOKING-PLAN.md:49; STEP-12-IMPLEMENTATION-PLAN.md:83 / :436; STEP-15-IMPLEMENTATION-PLAN.md:667 | Never implemented — the **first-listed** item in STEP 09's money out-list |
| 3 | Notifications / emails | STEP-10-PAYMENT-PLAN.md:56; STEP-11-IMPLEMENTATION-PLAN.md:83; STEP-12-…:87; STEP-13-…:106 (:110 "notifications, dashboard, reporting, UI"); STEP-14-PLAN.md:115 / :467; STEP-15-…:150 / :668 | Never implemented |
| 4 | Idempotency keys | STEP-09-BOOKING-PLAN.md:50; STEP-12-…:86; STEP-15-…:668; STEP-16-…:143 | Never implemented |
| 5 | Staff creating payments for a booking; editing/deleting payments | STEP-10-PAYMENT-PLAN.md:56–57 | Never implemented (no `cash` payment can be recorded today — STEP-16 §0.2 :75–77) |
| 6 | Partial refunds / customer-initiated refunds / refund approval workflows / refund-on-no_show | STEP-11-IMPLEMENTATION-PLAN.md:78–83 (:322 "refund on no_show → 409"); STEP-15-IMPLEMENTATION-PLAN.md:38–39, :657 | Never implemented (1:1 full-amount, verified-payment, staff-only only) |
| 7 | Payment gateways / 3rd-party providers / webhooks / real money movement | STEP-10-PAYMENT-PLAN.md:55–56; STEP-11-…:78; STEP-15-…:655–656 | Out **by design** (see §0.3) |
| 8 | Dashboard / reporting / UI | STEP-12-…:87; STEP-13-…:106/:110; STEP-14-PLAN.md:115/:467; STEP-15-…:150/:668 | Out **by design** (pure-API backend, see §0.3) |

Additional explicitly-documented deferrals outside the STEP-16 catalog
(verified directly in source docs, included so the full eligible set is
visible — **not** recommended topics below):

| # | Deferred item | Documented in (verified) | Wording |
|---|---|---|---|
| 9 | Staff booking for **no customer** (anonymous / walk-in) | STEP-12-IMPLEMENTATION-PLAN.md:84–85 | "requires a customer row; explicitly out of scope" |
| 10 | **Restorable room types** (permanent, not delete-permanent) | STEP-08-ROOM-IMAGE-PLAN.md:97 | "If room types are ever restorable in a **future step**…" |
| 11 | Taxed invoices / PDF / emailed invoice documents | STEP-16-IMPLEMENTATION-PLAN.md:131–133; STEP-16-…REPORT §4 | "invoice = non-tax"; "no PDF/file generation, no emailing" |

### 0.2 Which candidates are eligible (and which are not)

- **Eligible (deferred, never implemented, not out-by-design):** items
  **2, 3, 4, 5, 6** of the catalog, plus walk-in (9), room-type restore (10),
  taxed/PDF/email invoices (11).
- **Not eligible as STEP-17 scope:** items **7** (gateways/webhooks/real money
  — see §0.3) and **8** (dashboard/reporting/UI — see §0.3), because every
  source line marks them out *by design*, not "deferred pending a later step".

### 0.3 Out-by-design boundaries (cited; must not become STEP-17 scope)

- **Real-money movement / gateways / webhooks:** STEP-10-PAYMENT-PLAN.md:54–58
  ("payment gateways / 3rd-party providers, webhooks"); STEP-11-…:78 ("actual
  money movement / payment gateway integration"); STEP-15-…:655–656 ("No
  real-money movement, no payment gateway"). The project simulates money with
  server-side integer-cent bookkeeping and **no** third-party dependency.
- **Dashboard / reporting / UI:** STEP-12-…:87 ("Dashboard / reporting / UI —
  out of scope (not required by this step)"); STEP-14-PLAN.md:115; STEP-13-…:106;
  STEP-15-…:668. The deliverable is a pure JSON API.
- **Tax/TVA schema:** STEP-16-IMPLEMENTATION-PLAN.md:131 ("No taxes/TVA (no
  tax schema exists in this project; invoice = non-tax)"; §0.3 :94 "STEP 03
  deliberately created no `tax_types`/`booking_taxes`/`invoices`").

### 0.4 Recommended STEP-17 topic and rationale

**PROPOSED recommendation (approval item D-S0): Pricing tiers / discounts**
(catalog item #2).

Rationale — same criteria STEP-16 used to pick Invoices (STEP-16 §0.2 :46–61):

1. It is now the **only remaining *money-domain business* deferral** that
   recurs across multiple STEP out-lists (STEP 09:49, STEP 12:83/:436, STEP
   15:667 — plus the catalog) and is **not** an engineering-hardening or
   out-by-design item (unlike #3/#4/#7/#8).
2. STEP-16 §0.2 itself names it first among the *approvable* successors:
   "the item listed **first** in the STEP 09 money out-list"
   (STEP-16-IMPLEMENTATION-PLAN.md:66–69).
3. It completes the server-side pricing story: pricing → booking snapshot →
   payment amount → invoice total are already one chain (verified §3); the
   tiers feature is the missing head of that chain.

**Documented risk (must be accepted by the approver, not hidden):**
STEP-16-IMPLEMENTATION-PLAN.md:66–69 documents pricing-tier work as **"the
highest-regression-risk feature in the remaining catalog"** — it modifies
`BookingService::create()` pricing, `searchAvailable()` pricing, the
`booking_rooms.price_per_night` snapshot, the `payments.amount = total`
invariant, **and** STEP 15's late-fee snapshot formula. The plan below
therefore treats every one of those interactions as a §B approval item with
recommended defaults from documented precedent — no invented numbers.

**Eligible alternatives (any is approvable; §B D-S0):**
- **D-S0(b) Staff payment creation / editing / deletion** (catalog #5;
  STEP-10:56–57) — fills "no cash payment can be recorded today"
  (STEP-16 §0.2 :75–77). Small vertical, low regression risk; closest to
  Invoices' "clean vertical" profile (STEP-16 §0.2 :55–57).
- **D-S0(c) Refunds expansion** (catalog #6; STEP-11:78–83, :322) — partial
  refunds, customer-initiated refunds, refund approval workflows, refund on
  no_show. Money-domain, but touches the most-guarded `PaymentService::refund`
  + `payments.amount` + STEP-15 fee interactions (all undefined → decisions).
- **D-S0(d) In-app notifications** (catalog #3) — DB-backed records, staff and
  customer list+mark-read, hooks on booking/payment/verification events.
  Platform feature, no money-domain completion value (STEP-16 §0.2 :70–74).
- Everything else (idempotency keys #4, walk-in #9, restorable room types #10,
  taxed/PDF/email invoices #11) is a documented candidate but no plan branch
  is offered for them in this document (they are outside the money-domain
  recommendation and listed here only so the approver sees the full eligible
  set with citations).

### 0.5 Verified facts this plan builds on (dependencies on STEP 09–16)

Baseline: **421 tests / 1,232 assertions / 0 failures** (STEP 16 run).

| Fact | Verified location |
|---|---|
| Server-side pricing: `pricePerNightCents = (int) round(roomType.base_price * 100)`; snapshot into `booking_rooms.price_per_night`; `total_amount` summed in integer cents | app/Services/BookingService.php:112–135 (STEP 09) |
| `searchAvailable()` presents pricing to the customer at search time | STEP-09-BOOKING-PLAN.md §5; BookingService (STEP 09) |
| `payments.amount = bookings.total_amount` (locked read) | app/Services/PaymentService.php:57 (STEP 10) |
| Full-amount verified-payment-only refund engine | app/Services/PaymentService.php:193–231 (STEP 11) |
| Late-cancel fee = `late_cancel_fee_nights × Σ round(booking_rooms.price_per_night × 100)` | app/Services/BookingService.php:474–486 (STEP 15) |
| Invoice `total_amount = payment.amount`; line totals from `booking_rooms.price_per_night` snapshots | app/Services/InvoiceService.php:27–51 (STEP 16) |
| Money is never accepted from request bodies; server-computed only | STEP-03 §4; BookingService:121; PaymentService:53 |
| Base price lives on `room_types.base_price` (`decimal(10,2)`), not per-room | app/Models/RoomType.php (STEP 06); STEP-03 migration `…000005` |
| Auction ladder / rebate / batching machinery used for invoices etc. | Invoices now 1:1-per-payment (STEP 16); no promo/discount table exists |

### 0.6 What a pricing-tiers STEP 17 would close (documented gap only)

- Today a booking's total is **always** `Σ nightly base_price` — there is no
  documented, server-side way to price a stay by stay-length tiers, weekday/
  season, or guest-count, and no discount mechanism worth a mention anywhere
  in STEP 02–16 (verified: "pricing tiers/discounts" appears **only** in
  out-lists). Every business definition that a STEP 17 would need is therefore
  an **unresolved §B decision** — exactly the STEP-15 situation for fees
  (STEP-15-IMPLEMENTATION-PLAN.md:31–43 flag the same pattern).

---

## 1. Exact Objective and Scope (draft — decision items in §B)

**Objective (proposed):** add server-side **pricing tiers and discounts** to
booking price computation — the head of the money chain — while keeping every
downstream invariant (`payments.amount = bookings.total_amount`, invoice
`total_amount = payment.amount`, late-fee snapshot formula) intact, additive,
and fully regression-tested.

**In (working MVP, every mechanism line an approval item):**
- Additive schema for a discount/tier definition (shape = decision D-2),
  applied inside the existing server-side integer-cent total computation
  (BookingService:112–135), **never** from request input.
- Transparent pricing: customer sees the applied tier/discount and the
  resulting nightly snapshot at booking/search time (response-bearing surface
  = decision D-4).
- The `booking_rooms.price_per_night` snapshot continues to be the **final
  charged** nightly rate, so downstream (payment amount, invoice items,
  late-fee formula) need no new code paths (D-6).

**Out (deliberately — every line is a documented deferral, not an omission):**
- ❌ No payment-gateway / webhook / real-money integration (STEP-10:54–58,
  STEP-11:78, STEP-15:655–656 — out by design).
- ❌ No dashboard / reporting / UI (STEP-12:87, STEP-13:106, STEP-14:115,
  STEP-15:668 — pure-API by design).
- ❌ No notifications / email of discounts or anything else (STEP-10:56,
  STEP-11:83, STEP-12:87, STEP-13:106, STEP-14:115/467, STEP-15:150/668).
- ❌ No idempotency keys (STEP-09:50, STEP-12:86, STEP-15:668).
- ❌ No refund-engine change, no partial/customer/auto refunds, no refund
  approval workflows (STEP-11:78–83/:322, STEP-15:38–39/:657).
- ❌ No staff payment creation / editing / deletion (STEP-10:56–57).
- ❌ No taxes/TVA, no PDF/email invoice documents (STEP-16:131–133).
- ❌ No schedule/console-command changes, no `.env` change unless a §B decision
  needs it (env() only in config per STEP 02 rule), no destructive schema
  change, no enum changes.

---

## 2. What the Repository Already Says (evidence)

| Topic | Finding | Source (verified) |
|---|---|---|
| Existing pricing | `Σ round(base_price×100)` → `booking_rooms.price_per_night` → `bookings.total_amount` | app/Services/BookingService.php:112–135; STEP-09 §5 |
| Money invariant | `payments.amount = locked bookings.total_amount` | app/Services/PaymentService.php:57; STEP-10 §1–§4 |
| Invoice invariant | `invoices.total_amount = payments.amount`; items from `booking_rooms.price_per_night` | app/Services/InvoiceService.php:27–51; STEP 16 |
| Late-fee formula | `fee_nights × Σ round(booking_rooms.price_per_night ×100)` | app/Services/BookingService.php:474–486; STEP-15 |
| Discounts / tiers | "pricing tiers/discounts" appears **only** in out-lists — zero definition anywhere | STEP-09:49, STEP-12:83/:436, STEP-15:667 |
| No discount schema | STEP 03 created exactly 10 tables, none promotional; STEP 16 added invoices/invoice_items only | STEP-03 §1; migrations `…000009`, `2025_02_10_*` |
| Numbering/money precedent | decimal(10,2); integer cents; server-computed; never client money | STEP-03 §4; BookingService:121; STEP-16 |
| Decision discipline | Every unresolved business number is a §B approval item (STEP 15 §27 precedent) | STEP-15-IMPLEMENTATION-PLAN.md:167–170 |

**Conclusion (same shape as STEP 15's):** the *architecture and mechanism* of a
pricing tier are fully precedented (server-side integer cents; snapshot at
booking; value object or table defined additively). The *business definitions*
(tier formula, discount scoping, applicability, fee/refund interaction) are
**not documented** and are §B approval items.

---

## 3–12. Draft Design (against recommended topic; all numbered items are §B decisions)

> None of the following commits a behavior. Each `[D-n]` is a §B approval item
> with a recommended value derived from documented precedent only.

### 3. Where pricing is computed today (the 4 touchpoints a STEP 17 touches)

1. **Booking create** — `BookingService::create()`: for each selected room,
   `pricePerNightCents = round(base_price×100)`; sums `totalCents` →
   `bookings.total_amount`; snapshots `booking_rooms.price_per_night`
   (BookingService:112–135). **[D-3]** decides where tiers are applied (before
   or instead of this snapshot).
2. **Availability search pricing** — `searchAvailable()` presents nightly/​total
   prices pre-booking. **[D-4]** decides whether search shows discounted rates.
3. **Payment** — `payments.amount = locked bookings.total_amount`
   (PaymentService:57); must remain true **after** any tier applies (i.e.
   `total_amount` is the *discounted* total). No code change expected; a test
   pins it.
4. **Downstream readers** — invoice `total_amount = payment.amount` and line
   totals from `booking_rooms.price_per_night` (STEP 16); late-fee formula from
   the same snapshot (STEP 15). **[D-6]** decides whether these should read the
   final discounted nightly snapshot (recommended) or need any derived column.

### 4. Proposed schema shape (recommended, additive only)

A single new additive table is proposed, shape = approval item **[D-2]**:
- `discounts` (or `pricing_tiers`, name = decision) keyed to stay
  criteria with a server-side integer-cents discount (absolute or percentage,
  = decision **[D-3b]**). No enum change, no column on existing tables, no
  `deleted_at` (soft-delete convention only if approved — STEP 08 precedent
  exists for soft-deletable masters).

### 5. Formula (recommended from precedent)

Discount applied **in integer cents** before the booking snapshot
(BookingService:112–135), so `booking_rooms.price_per_night` stores the final
charged rate; `total_amount` sum is unchanged in form. The exact discount
object, scoping (stay nights / weekend / season / guest count), and stacking
rules are **[D-3 / D-3c]** — undefined today and not invented here.

### 6. Interaction with STEP 15 late-cancel fee (documented risk)

STEP-15's fee reads `booking_rooms.price_per_night` (BookingService:486).
Recommended **[D-6]:** the fee formula and snapshot are untouched (they already
read the final charged nightly rate once [D-3] stores the discounted snapshot),
so no STEP-15 behavior/text changes — regression-pinned by the existing
late-fee test file's 24 tests. Any alternative (fee on pre-discount rate) is an
explicit override decision.

### 7. Interaction with STEP 16 invoices (documented invariant)

`invoices.total_amount = payments.amount` (InvoiceService:37). Recommended
**[D-6]:** invariant untouched — invoice equals the discounted charged amount,
and line items show the discounted snapped nightly rates. Lines like
"original price / discount shown per line" would be a deliberate expansion of
the STEP-16 line-item shape and is **not** proposed (needs explicit approval
if wanted).

### 8. Interaction with refunds and no-show (documented boundaries)

Refund engine is full-amount-on-verified-payment only (STEP 11); partial
refunds explicitly out of scope (STEP-15:38–39). Recommended: STEP 17 introduces
**no** refund change — the discounted total is simply what a refund returns
(= `payments.amount`).

### 9. Transaction / concurrency

Pricing input is server-side configuration (reads), applied inside the existing
single `BookingService::create()` transaction — no new locking. The discount
definition table is created/updated by staff/admin in a new small admin surface
(surface = **[D-5]**) using the existing `role:*`+`authorize()` two-layer
convention (STEP 05/10 precedent).

### 10. API surface (recommended)

- Customer: search + booking-create responses may carry an applied discount
  summary; the request body **never** accepts money or a discount code blind
  (codes = decision **[D-7]**, out of the working MVP by default — a code is a
  *kind of* discount, but an "accept a code from the client" endpoint is a
  new client-facing trust surface the repo has never shipped).
- Staff/admin: CRUD for the discount/tier definitions (role gating = **[D-5]**).
- All responses stay inside the existing `ApiResponse` envelope; 401/403/404/409
  mapping unchanged (bootstrap/app.php).

### 11. Test matrix (draft, mirrors STEP 15/16 discipline)

Integer-cents math incl. fractions; snapshot stores discounted nightly rate;
`payments.amount == bookings.total_amount` invariant; invoice `total_amount ==
payment.amount`; late-fee formula unchanged reading discounted snapshot;
authorization matrix (who may create/update tiers); validation of the additive
table; no-request-money injection; regression of the full suite (421 →
expected ~450).

### 12. Explicitly not touched (absolute list)

`PaymentService` (unless D-6 override approved — not recommended), refund/
no-show/cancel engines, STEP 13 sweeps, `DatabaseSchemaTest` expectations need
zero edits (additive table per STEP 14/16 precedent), every existing endpoint
response shape, every scheduled command, `config/*` and `.env*` (unless a §B
decision requires a documented env knob for the tier parameters — STEP 02 rule:
env() only inside config).

---

## B. Unresolved Business Decisions — MUST BE APPROVED BEFORE ANY IMPLEMENTATION

Per project discipline (STEP 14 §3 / STEP 15 §27 / STEP 16 §B), nothing is
decided by assumption. Recommended value first; every alternative is explicit.

| # | Decision | Recommended | Alternatives |
|---|---|---|---|
| **D-S0** | **STEP 17 topic** | **Pricing tiers / discounts** (catalog #2, first-listed in STEP 09's money out-list) | (b) Staff payment creation/edit/delete (STEP-10:56–57); (c) Refunds expansion — partial/customer/approval/no_show (STEP-11:78–83/:322); (d) In-app notifications (catalog #3); (e) none — stop the step sequence |
| D-1 | Deliverable status | Plan-only until review; implementation deferred until approval of THIS document | — |
| D-2 | Discount/tier storage | **New additive table** (`discounts` or `pricing_tiers`), no columns added anywhere else | Config-only constants; computed inline (no schema) |
| D-3 | Discount formula & position | **Server-side integer cents, applied before the booking snapshot** so `booking_rooms.price_per_night` = final charged nightly rate; exact tier criteria + percentage/absolute + stacking = sub-decisions D-3b/D-3c (recommended: single discount per booking, percentage-of-nightly, no stacking — conservative) | any other formula — **undefined in the repo, must be specified here** |
| D-4 | Search pricing | Show discounted totals in `searchAvailable()` pricing output | Hide discounts until booking (opaque) |
| D-5 | Tier admin surface | **Staff/admin-only CRUD**, `role:*` + `authorize()` two-layer (STEP 05/10 convention); roles = admin+manager | admin-only; config-driven instead of API |
| D-6 | Downstream invariants | **Unchanged**: `payments.amount = discounted bookings.total_amount`; invoice `total_amount = payment.amount`; late-fee formula reads the discounted snapshot (zero changes to STEP 11/15/16 engines) | Fee computed on pre-discount rate; invoice itemized with original + discount lines (both expand existing engines) |
| D-7 | Client discount codes | **Out of working MVP** (no client-supplied money/benefit entry; the repo has never had one) | Explicit code-redeem endpoint (new trust surface) |

**Approve D-S0 and D-1…D-7 (or pick an alternative topic), then STOP again
for the implementation brief.** No migration, no code, no tests, no routes,
no behavior changes until this document is approved.

---

## 13. Stop for Review

This is a **proposal**. The only artifact produced is this document. Work on
STEP 17 does **not** begin — a separate approved implementation brief is
required first, exactly as STEP 14–16 were each approved before their
implementation.