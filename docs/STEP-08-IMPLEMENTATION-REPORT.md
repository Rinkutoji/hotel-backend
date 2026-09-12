# STEP 08 — Room Type Image Management — Implementation Report

Plan is in `docs/STEP-08-ROOM-IMAGE-PLAN.md` (written first, per
instruction, including the pre-coding review of the existing migration/
model/policy/storage config). This covers what was actually built on
top of it.

---

## 1. Files Created / Modified

**Created:**
- `app/Models/RoomImage.php`
- `app/Policies/RoomImagePolicy.php`
- `app/Services/RoomImageService.php`
- `app/Http/Requests/RoomImage/{Upload,Update}Request.php`
- `app/Http/Controllers/Api/V1/Public/RoomImageController.php`
- `app/Http/Controllers/Api/V1/Staff/RoomImageController.php`
- `tests/Feature/RoomImage/{Public,Staff}RoomImageTest.php` — 31 new tests

**Modified:**
- `app/Models/RoomType.php` — added `images()` relationship
  (`sort_order` asc, `id` asc built into the relationship itself, so
  every consumer gets correct ordering for free)
- `routes/api.php` — added public and staff room-image routes

**No migration changes** — the existing `room_images` schema from
STEP 03 already had every field needed (`room_type_id`, `path`,
`sort_order`), confirmed by direct review before writing anything.

## 2. API Endpoints

| Method | URL | Auth | Roles |
|---|---|---|---|
| GET | `/api/v1/public/room-types/{roomType}/images` | None | — |
| GET | `/api/v1/staff/room-types/{roomType}/images` | sanctum+actor | any employee |
| POST | `/api/v1/staff/room-types/{roomType}/images` | +role | admin, manager |
| PUT | `/api/v1/staff/room-images/{image}` | +role | admin, manager |
| DELETE | `/api/v1/staff/room-images/{image}` | +role | admin, manager |

## 3. Upload Validation

`image`: required, file, `image` (genuine image data), `mimes:jpg,jpeg,png,webp`
(explicit allowlist — deliberately narrower than the bare `image` rule,
which would also accept `gif`/`bmp`/`svg`; `svg` specifically excluded
since it can embed script content), `max:5120` (5MB, a chosen default).
Both `image` and `mimes` detect the real format from file content, not
the filename or client-supplied `Content-Type` — confirmed in the plan,
not just asserted.

## 4. Storage Behavior

Filename is always generated (`Str::uuid() . '.' . $file->extension()`,
extension guessed from content) — the original upload name is never
used anywhere. Files land at `room-types/{roomTypeId}/{uuid}.{ext}` on
the `public` disk. If the DB insert fails after the file is written, the
file is deleted before re-throwing (closes the one real zero-reference
orphan-file risk). URL returned to clients is a computed accessor
(`Storage::disk('public')->url($path)`); the raw `path` column is
`$hidden` and never appears in any response.

## 5. Authorization Behavior

`role:admin,manager` middleware + `RoomImagePolicy` re-check in the
controller, same two-layer pattern as every management module since
STEP 06. No receptionist carve-out this time (unlike Room's status
changes) — receptionist is forbidden on every write action here,
matching the flat matrix given.

## 6. Image Lifecycle

**Upload → normal state → (parent room type soft-deleted) → (explicit
delete).** Full reasoning is in plan §5–§6; the short version: a
soft-deleted room type's images are left completely alone — no code
needed, since they stay validly FK-attached (the parent row still
exists) and become unreachable only through the *public* API, as a
side effect of the same `SoftDeletes`-global-scope + route-model-binding
mechanic used everywhere else. Explicit image deletion deletes the DB
record first, then the file — deliberately, since that ordering's
failure mode (an orphaned file) is strictly less harmful than the
reverse (a DB record pointing at a missing file). `is_primary` was
considered and **not added** — `sort_order` already gives an implicit
"first/primary image" for free; reasoning is in plan §7.

## 7. Tests Added (31 new — 178 total across the whole suite)

Public (5): list, sort_order-then-id ordering with a tie-break case,
raw path never exposed, soft-deleted/non-existent room type → 404.
Staff (26): upload by admin/manager, forbidden for receptionist/
customer/guest (403/403/401), non-image file rejected, valid-but-
unapproved format (GIF) rejected specifically to prove the `mimes:`
allowlist does real work beyond the generic `image` rule, oversized
rejected, missing file rejected, upload-to-deleted-type 404, file
genuinely stored + DB record genuinely created + URL genuinely correct,
delete removes the physical file, deleting an image whose file was
already missing doesn't error, list/update/delete role permissions,
cross-room-type data isolation for both update and delete, full
unauthenticated 401 coverage.

## 8. Migration Changes

**None.** Confirmed necessary before writing any code (plan §1–4) — the
approved `room_images` schema already had everything STEP 08 needed.

## 9. Exact Commands

```bash
php artisan storage:link
php artisan test --filter=RoomImage
php artisan test
```

`storage:link` is a **one-time local setup step**, not a test
prerequisite — the test suite uses `Storage::fake('public')`, which
bypasses the real symlink entirely. It's required so uploaded images are
actually reachable over HTTP in real local/production use.

Expected: **178 passed** (147 from STEP 02–07 + 31 new).

## 10. Verification Results

| Item | Status |
|---|---|
| `room_images` schema reviewed before any code was written, confirmed sufficient | **TESTED** — read the actual migration, not assumed |
| `mimes`/`image` validation rules use content-based detection, not filename trust | **DESIGNED** — this is standard, long-stable Laravel/Symfony behavior (not a recent version-specific change like Sanctum's migration behavior was), not independently re-verified via search this step |
| All new/modified files pass `php -l` | **TESTED**, in this sandbox |
| Full-project syntax sweep after this step | **TESTED**, clean |
| `php artisan test` → 178 passed | **NOT VERIFIED** — same sandbox limitation as every prior step (no Packagist access here). Needs your run. |

## 11. Known Limitations

- **`UploadedFile::fake()->image('room.gif')` relies on Laravel's test
  helper correctly generating valid GIF binary data via GD** to prove
  the `mimes:` allowlist rejects it specifically (not just "not an
  image at all"). This is well-established, commonly-used Laravel
  testing functionality, but I couldn't execute it here to confirm —
  if that one test behaves unexpectedly, it's the most likely place to
  look first.
- **No rate limiting specific to uploads** — the general `throttle:api`
  from STEP 02 still applies, but nothing extra was added for
  upload-specific abuse (repeated large-file uploads). Not asked for
  this step; worth a look before production, alongside the same note
  made in STEP 04 about auth-endpoint-specific throttling.
- **`is_primary` genuinely not implemented** (plan §7) — flagging again
  here since it's explicitly one of the things this step asked to
  either build or explain; recommendation is in the plan, not a schema
  change.
- **No image resizing/thumbnailing** — not requested, not built; every
  uploaded image is stored and served at its original dimensions.

STOPPING HERE. STEP 09 has not started. Waiting for your migration/test
results before anything further.
