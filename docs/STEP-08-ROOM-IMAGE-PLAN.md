# STEP 08 — Room Type Image Management Plan (review before implementation below)

## 1–4. Review (per the required pre-coding checklist)

**`room_images` migration, exact fields:** `id`, `room_type_id` (FK,
`cascadeOnDelete`), `path`, `sort_order` (default 0), `created_at`,
`updated_at`. **No `caption`, no `is_primary`, no soft-delete column.**
Confirmed by reading the actual STEP 03 migration, not assumed.

**`RoomType`/`RoomTypePolicy`:** `RoomType` already has `rooms()`; no
`images()` relationship yet (added below). `RoomTypePolicy` is the
established shape to mirror: `viewAny`/`view` true for any `Employee`,
`create`/`update`/`delete` restricted to `admin`/`manager`.

**Storage config:** `public` disk already correctly configured since
STEP 02 (`storage_path('app/public')`, URL via `APP_URL/storage`,
symlink already declared in `config('filesystems.links')`) — nothing to
change there. The symlink itself likely hasn't been *created* yet
(`php artisan storage:link`), since nothing has written to public
storage before this step — that's a required one-time local command,
listed in §9.

## 2 (of the deliverable). API Contract

| Method | URL | Auth | Roles |
|---|---|---|---|
| GET | `/api/v1/public/room-types/{roomType}/images` | None | — |
| GET | `/api/v1/staff/room-types/{roomType}/images` | sanctum+actor | any employee |
| POST | `/api/v1/staff/room-types/{roomType}/images` | +role | admin, manager |
| PUT | `/api/v1/staff/room-images/{image}` | +role | admin, manager |
| DELETE | `/api/v1/staff/room-images/{image}` | +role | admin, manager |

`{roomType}` and `{image}` are both implicit bindings — soft-deleted
room types 404 automatically (existing `SoftDeletes` global-scope
behavior, nothing new needed). Images are ordered `sort_order` asc,
`id` asc as tie-breaker, on every list endpoint.

## 3. Authorization Matrix

| | Admin | Manager | Receptionist | Customer |
|---|:---:|:---:|:---:|:---:|
| View (public) | – | – | – | ✅ |
| View (staff) | ✅ | ✅ | ✅ | – |
| Upload | ✅ | ✅ | ❌ | ❌ |
| Update (sort_order) | ✅ | ✅ | ❌ | ❌ |
| Delete | ✅ | ✅ | ❌ | ❌ |

No operational carve-out for receptionist here (unlike Room's status
changes in STEP 07) — the spec is flat: receptionist forbidden on every
management action, matching what's written above exactly.

## 4 (of the deliverable). Upload Validation Rules

```
image: required, file, image, mimes:jpg,jpeg,png,webp, max:5120 (5MB)
```

**Why `image` + `mimes:` together, not just one:** the bare `image` rule
alone would also accept `gif`, `bmp`, and — importantly — **`svg`**,
which isn't in the approved format list and is a real, known risk even
for "just" static file serving (an SVG can embed `<script>` content that
executes if a browser ever renders it directly). Stacking `mimes:jpg,jpeg,png,webp`
on top restricts to exactly the approved four.

**"Do not trust only the filename extension" — confirmed how this is
actually satisfied, not just asserted:** Laravel's `mimes`/`image` rules
detect the real format from the file's content (via Symfony's
mime-type guesser inspecting actual bytes), not the client-supplied
filename or `Content-Type` header. A `.php` file renamed to `photo.jpg`
fails validation — its *content* isn't a real image, regardless of what
its name claims. The stored filename is generated the same
content-derived way (`$file->extension()`, never the original upload
name), so the two can't disagree.

`max:5120` (5MB) is a chosen default, not a number given in the spec —
flagged, easy to change.

## 5. Storage Lifecycle

**Upload:** file is written to `room-types/{roomType->id}/{uuid}.{ext}`
on the `public` disk *before* the DB row is created. If the DB insert
then fails for any reason, the just-written file is deleted before
re-throwing — this is the one genuine orphan-file risk in the whole
flow (a file with zero DB reference at all), and it's closed.

**Normal state:** file + DB row exist together; reachable via both
public and staff list endpoints while the parent room type is active.

**Room type soft-deleted (the "explain the intended lifecycle"
requirement):** **nothing happens to its images automatically, and
nothing needs to.** The `room_type_id` FK stays completely valid (the
parent row still physically exists, just flagged `deleted_at`) — this
is not orphaning. The images become unreachable through the *public*
API purely as a side effect of existing behavior: implicit route-model-
binding on `{roomType}` already 404s for a soft-deleted parent (the same
`SoftDeletes` global-scope mechanic used everywhere else in this
project). If room types are ever restorable in a future step, their
images come back attached and intact, with no extra code needed now.

**Explicit image deletion (staff action):** the **DB record is deleted
first**, then the file is deleted as a best-effort second step. This
ordering is deliberate — see the dedicated section below.

## 6. Filesystem/Database Consistency (the transaction question)

A database transaction can only guarantee atomicity for the *database*
side — it cannot roll back a filesystem operation, so true two-phase
atomicity between MySQL and disk isn't achievable with a transaction
wrapper alone. What matters is the **order**, chosen for the least-bad
failure mode:

- **DB first, then file (chosen):** if file deletion then fails or the
  file's already gone, the result is an orphaned file — wasted disk
  space, but nothing user-facing is broken, since nothing references it
  anymore.
- **File first, then DB (rejected):** if the DB delete then failed, the
  result would be a DB record pointing at a file that no longer exists —
  a broken reference a real user could hit and get a 404 on.

The DB deletion is wrapped in `DB::transaction()` (mostly for clarity of
intent here, given it's currently a single query); the file deletion
that follows is wrapped in its own try/catch and reported, never
allowed to turn into a 500 for the caller — directly satisfying "handle
missing physical files safely."

## 7. Primary Image — Explicitly Not Added, With Reasoning

The schema has no `is_primary` column, and none is added. Whether it
*would* be justified: `sort_order` already gives an implicit "primary
image" mechanism for free — whichever image has the lowest `sort_order`
is the natural first/hero image by convention, with zero extra schema.
A dedicated `is_primary` boolean would only earn its place if multiple
simultaneously-"featured" images were needed independent of their
position in the ordering, which nothing here calls for. Recommendation:
don't add it.

## 8. Models / Service / Policy

`RoomImage` (new) — `$fillable = ['room_type_id', 'path', 'sort_order']`,
`$hidden = ['path']` (raw storage paths aren't exposed — "do not expose
filesystem paths directly"), `$appends = ['url']` via a computed
accessor (`Storage::disk('public')->url($this->path)`). `RoomType`
gains `hasMany(RoomImage::class)`.

`RoomImageService`: `upload()`, `delete()` (the ordering from §6),
`updateSortOrder()`.

`RoomImagePolicy`: same shape as `RoomTypePolicy`/`RoomPolicy` —
`viewAny`/`view` true for any `Employee`, `create`/`update`/`delete`
restricted to `admin`/`manager`, no exceptions.

## 9. Test Plan

~32 tests across `tests/Feature/RoomImage/{Public,Staff}RoomImageTest.php`.
Real file-upload testing throughout via Laravel's `Storage::fake('public')`
+ `UploadedFile::fake()->image(...)` — genuinely valid generated images
that exercise the real validation path, not stubbed responses. Covers:
public list + ordering + deleted-room-type 404, upload by
admin/manager/rejected for receptionist/customer/guest, MIME/extension/
size/non-image rejection (including a `.gif` specifically, to prove the
`mimes:` allowlist is doing real work beyond the generic `image` rule),
file-actually-stored + DB-record-created + URL-correctness, delete
removes the physical file, deleting an already-missing physical file
doesn't 500, sort-order update, cross-room-type data isolation, and the
full 401/403 authorization matrix.

**One-time local setup needed:** `php artisan storage:link` — hasn't
been necessary until this step, since nothing wrote to public storage
before now.

---

Implementation follows below.
