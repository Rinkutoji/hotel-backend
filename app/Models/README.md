# app/Models

Empty in STEP 02 - no domain tables exist yet (STEP 03 onward). The genuine
Laravel skeleton's default `User.php` is intentionally left in place (see
docs/STEP-02-IMPLEMENTATION-REPORT.md) even though the Architecture
Baseline uses separate `customers`/`employees` tables instead of a generic
`users` table - removing/replacing it is a STEP 03/04 decision, not made
here.

Convention once domain models start landing here: one Eloquent model per
table, `$fillable` explicitly declared on every model (never `$guarded =
[]`), relationships defined as methods, no business logic beyond
relationships and simple accessors/casts - real logic belongs in
`app/Services`.
