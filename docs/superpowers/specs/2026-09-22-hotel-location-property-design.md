# Hotel Location / Property Management — Design Specification

- **Date:** 2026-09-22
- **Status:** Confirmed by user (chat review), pending spec approval
- **Scope:** Add multi-property (Property → Building → Floor → Room) management to the existing Hotel Management System (Laravel backend + React frontend)
- **Hard constraints (from the user's attached spec):**
  - This is an existing production-style application. **Do NOT rebuild, replace, or create a separate demo.** The feature extends the existing system.
  - Existing production data must not be lost. No silent deletion or modification of existing business records.
  - Do not add `property_id` / `building_id` redundantly to bookings, payments, invoices, or customers — property is always resolved through **Room → Building → Property**.
  - Do not trust the frontend to determine the property. The backend validates and enforces in SQL.
  - Keep the existing auth, role middleware, policies, and API conventions.
  - Do not break existing Dashboard, Analytics, Reports, Rooms, Bookings, Payments, Invoices, Guests, or Calendar functionality.

---

## 1. Goal

Add hotel locations/properties as a first-class entity: staff can manage multiple properties, buildings, and per-building rooms; the public booking site becomes property-aware; and every existing aggregate surface (dashboard, analytics, reports, exports) can be filtered by property/building/room type with the filtering done **server-side in SQL**.

---

## 2. Current architecture (inspection findings)

- **No existing location concept.** There is no Property, Hotel, Building, Location, or Floor table, model, route, or page in the backend or frontend. Nothing is duplicated or replaced.
- `rooms`: `room_number` string **globally UNIQUE** today; `floor` nullable free-text string; `room_type_id` FK; status enum; SoftDeletes.
- Bookings reference rooms only via `booking_rooms` pivot (price snapshot rows). Property is derivable only through the room.
- `room_types` is global master data shared by all rooms (kept global).
- `employees`: `role` (`admin` / `manager` / `receptionist`), `department_id`, `status`. **No property assignment today.**
- Analytics/Reports/Dashboard: `AnalyticsService::analytics()/dashboard()` and `ReportService` accept only `(from, to, group_by)`; all aggregation is server-side SQL. `DashboardController` reuses `AnalyticsService::dashboard()`; CSV export flattens the same payload.
- Authorization: two-layer convention — `role:*` route middleware + policy re-check inside the controller (`RoomPolicy`, `AnalyticsPolicy`, `ReportPolicy`, `EmployeePolicy`).
- Frontend: staff SPA (`hotel-frontend`, React + react-router-dom) with Sidebar nav (Dashboard, Bookings, Rooms, Room Types, Payments, Invoices, Discounts, Staff, Reports, Analytics, Calendar); `ReportFilters` uses URL search params; `useAnalytics` / `useDashboardOverview` hooks take `{from, to, groupBy}`; `dashboardApi` / `analyticsApi` / `reportApi` axios clients.
- `config/booking.php` holds business-rule numbers (hold hours, cleaning buffer, stay caps, late-cancel windows). No currency config exists; `properties.currency` is stored data only (default `USD`), never used for conversion.

---

## 3. Approved decisions

1. **Room numbers:** per-building uniqueness. Drop the global unique index; add `UNIQUE(building_id, room_number)`. "Room 101" may exist in each building. Existing room numbers are unchanged.
2. **Floors:** no Floor table. `rooms.floor` stays a free-text string column; `buildings.floor_count` is informational; floor groupings are derived from rooms.
3. **Staff scoping:** nullable `employees.property_id`.
   - **Admin → all properties** (unrestricted).
   - **Manager → all properties** (unrestricted).
   - **Receptionist → assigned property only — always.** A receptionist with no property assignment is a validation error on employee create/update; existing receptionists are backfilled to "Main Hotel" during migration. Scoping is enforced **server-side in SQL** and cannot be bypassed by request params or URL tampering.
4. **Map:** optional OpenStreetMap **iframe embed** on the property detail page — zero npm packages, zero API keys; hidden when the property has no coordinates.
5. **No redundant columns:** bookings, payments, invoices, customers are untouched by this feature; property resolves through Room → Building → Property.
6. **Room types stay global** master data.
7. **Backfill targets:** default property `Main Hotel` (code `MAIN`) and building `Main Building` (code `MAIN` inside that property). All existing rooms and all existing receptionists are assigned there.

---

## 4. Data model

### 4.1 New table `properties`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | required, indexed (search) |
| code | string | required, **UNIQUE** (e.g. `MAIN`) |
| description | text | nullable |
| address | string | nullable |
| city | string | nullable, indexed (search) |
| province_state | string | nullable, indexed (search) |
| country | string | nullable, indexed (search) |
| postal_code | string | nullable |
| latitude | decimal(9,6) | nullable — validated numeric, never free-form text |
| longitude | decimal(9,6) | nullable — validated numeric |
| phone | string | nullable |
| email | string | nullable, validated email format |
| website | string | nullable, validated URL format |
| check_in_time | time | nullable |
| check_out_time | time | nullable |
| timezone | string | nullable (IANA name) |
| currency | char(3) | default `USD`, informational only |
| status | enum(`active`,`inactive`) | default `active` |
| timestamps, softDeletes | | status inactive ≠ deleted; soft-delete keeps history |

### 4.2 New table `buildings`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| property_id | FK → properties | `restrictOnDelete` (properties soft-delete anyway) |
| name | string | required |
| code | string | required; **UNIQUE(property_id, code)** |
| description | text | nullable |
| floor_count | unsignedSmallInteger | nullable, informational |
| status | enum(`active`,`inactive`) | default `active` |
| timestamps, softDeletes | | |

Indexes: `property_id`.

### 4.3 `rooms` — modified

- **ADD** `building_id` FK → buildings (`restrictOnDelete`), `NOT NULL` after backfill, indexed.
- **DROP** unique index on `room_number`.
- **ADD** `UNIQUE(building_id, room_number)`.
- `floor` column unchanged (string, nullable). All other columns/behavior unchanged.

### 4.4 `employees` — modified

- **ADD** `property_id` FK → properties, **nullable at the DB level** (back-compat). Enforced by validation/business rules: receptionists must have one; admin/manager are stored with `NULL` (unrestricted).

### 4.5 Untouched tables

`bookings`, `booking_rooms`, `payments`, `invoices`, `invoice_items`, `customers`, `room_types`, `room_images`, `room_status_logs`, `discounts`, `departments` — no new columns. Property is always derived.

---

## 5. Migration plan (ordered, data-preserving)

1. `create_properties_table` (per §4.1).
2. `create_buildings_table` (per §4.2).
3. `add_building_id_to_rooms_table`:
   a. Add nullable `building_id` FK + index.
   b. Data step (in the migration, idempotent): create `Main Hotel` (`MAIN`) if absent; create `Main Building` (`MAIN`) under it; backfill **every existing room** (including soft-deleted) to that building.
   c. Make `building_id` `NOT NULL`.
   d. Drop the global `rooms.room_number` unique index.
   e. Add composite unique `(building_id, room_number)`.
4. `add_property_id_to_employees_table`:
   a. Add nullable `property_id` FK.
   b. Data step: backfill **every existing receptionist** (role = `receptionist`) to `Main Hotel`. Admin/manager stay `NULL`.
5. Rollback (`down()`) restores the global room_number unique index and re-drops the composite (data column removals are additive-safe).

No existing row is deleted or renumbered. Property soft-delete is used everywhere (never hard delete), so `restrictOnDelete` FKs never fire in normal operation.

---

## 6. Model relationships

- `Property`: `hasMany(Building)`, `hasManyThrough(Room, Building)`, `hasMany(Employee)`, scope `active()`.
- `Building`: `belongsTo(Property)`, `hasMany(Room)`.
- `Room`: **NEW** `belongsTo(Building)`; **NEW** convenience `property()` via `hasOneThrough(Property, Building)`.
- `Booking`: scope `byProperty(?int $propertyId)` — `whereHas('bookingRooms.room.building', fn($q) => $q->where('property_id', $id))`; scope `byBuilding(?int)`, `byRoomType(?int)` (via `bookingRooms`).
- `Payment`: `belongsTo(Booking)` (existing 1:1); scopes via `whereHas('booking.bookingRooms.room.building', …)`.
- `Invoice`: `belongsTo(Payment)`; scopes via `whereHas('payment.booking.bookingRooms.room.building', …)`.
- `Employee`: **NEW** `belongsTo(Property)` (nullable).
- `RoomType`: unchanged (global).

---

## 7. API plan

All endpoints follow the existing `/api/v1`, `ApiResponse {success,message,data}` envelope, two-layer auth, and FormRequest validation conventions.

### 7.1 New endpoints (staff)

| Endpoint | Roles (route middleware) | Notes |
|---|---|---|
| `GET /staff/properties` | all staff | receptionists: returns **only** their assigned property; admin/manager: full list + filters |
| `POST /staff/properties` | `role:admin` | `PropertyPolicy@create` re-check |
| `GET /staff/properties/{property}` | all staff | receptionist: 403 unless it is their property |
| `PUT /staff/properties/{property}` | `role:admin` | policy re-check |
| `DELETE /staff/properties/{property}` | `role:admin` | soft delete only (never hard delete); buildings/rooms remain attached and staff management continues; the public side already excludes the property via its soft-deleted scope and `status = active` filter |
| `GET /staff/properties/{property}/buildings` | all staff | nested list; receptionists scoped via property check |
| `POST /staff/properties/{property}/buildings` | `role:admin` | `BuildingPolicy@create` re-check |
| `GET/PUT/DELETE /staff/buildings/{building}` | view: all staff; write: `role:admin` | top-level by id, matching the `room-images` convention |

**Property filters on the properties list** (spec's Location search): `name`, `code`, `city`, `province_state`, `country`, `status`; paginated like `RoomService::paginate`.

**Property detail payload** (`GET /staff/properties/{id}`):
- hotel info: name, code, description, status, check_in_time, check_out_time, timezone, currency
- address: address, city, province_state, country, postal_code
- contacts: phone, email, website
- location: latitude, longitude (nulls allowed)
- buildings: each with id, name, code, status, floor_count, `room_count`, current `occupied`, `occupancy_pct`
- summary: `room_count`, `occupied`, `occupancy_pct`, `verified_revenue_total` (all-time verified payments attributed through rooms), `active_bookings` (holding statuses)
- Receptionist: only reachable for their own property (403 otherwise).

### 7.2 New endpoint (public)

| Endpoint | Notes |
|---|---|
| `GET /public/properties` | no auth; `status = active` only; minimal fields (id, name, code, city, country) for the booking-site selector |

### 7.3 Existing endpoints — new optional filter params

All filters are **validated ints**, applied **server-side in SQL**; a filter id that does not exist returns 422 (never silently ignored). Receptionists **never** honor these params — their scope is fixed server-side.

| Endpoint | New params |
|---|---|
| `GET /staff/rooms` | `property_id`, `building_id` (via `rooms.building_id` / building property) |
| `GET /staff/bookings` | `property_id`, `building_id` (via bookingRooms → room → building) |
| `GET /staff/payments` | `property_id` |
| `GET /staff/invoices` | `property_id` |
| `GET /staff/calendar/bookings` | `property_id` |
| `GET /staff/dashboard` + `/export` | `property_id` |
| `GET /staff/analytics` | `property_id`, `building_id`, `room_type_id` |
| `GET /staff/reports/*` + `/{report}/export` | `property_id`, `building_id`, `room_type_id` (where meaningful per report) |
| `GET /public/rooms` | `property_id` (booking-site search) |
| `POST /public/availability` | optional `property_id` in payload |

**Exports respect filters:** the CSV export renders exactly the same filtered SQL payload as the on-screen data (existing pattern).

### 7.4 Booking integrity rules (backend — never trusted from client)

- `BookingService::create()`:
  - All requested rooms must resolve to the **same property** (via room → building → property). Mixed-property room sets → 422 business error.
  - If the payload carries `property_id` and/or `building_id`, the rooms must match them (and building.property must match property) — invalid combinations (e.g. Property A + Building B + a room from Property C) → 422.
  - Rooms of inactive properties or inactive buildings → rejected.
- `BookingService::searchAvailable()` (public availability):
  - If `property_id` provided: only rooms of that property are considered; others are omitted from results.
  - Always excludes rooms whose property or building is inactive.
- Staff booking lifecycle actions (confirm/cancel/check-in/check-out): receptionists can only act on bookings whose rooms belong to their property (policy-level check via BookingPolicy + property scope).

### 7.5 Employee CRUD changes (admin-only)

- `POST/PUT /staff/employees` accept optional `property_id`.
- Business rule validation (`StoreEmployeeRequest` / `UpdateEmployeeRequest`):
  - role `receptionist` → `property_id` **required** (must exist, not soft-deleted);
  - role `admin` or `manager` → `property_id` must be absent or null (forced to null).
- Response includes `property_id`.

---

## 8. Property scoping & authorization

### 8.1 Effective-property-filter resolution (single backend helper)

A shared resolver (e.g. `PropertyScope` service / controller trait) returns the **effective property filter** for every staff list endpoint:

- `admin` / `manager` → the validated optional request param (`property_id`), or `null` = all properties.
- `receptionist` → **always** `$employee->property_id` (from the authenticated user row, re-read per request); request params are **ignored** — URL tampering cannot widen scope. If somehow null (legacy data edge), the request fails closed with 403.

This resolver is the only place scope is decided; each service method takes the resolved `?int $propertyId` and applies it as a SQL `whereHas`/join. No frontend-only filtering anywhere.

### 8.2 Access matrix

| Capability | Admin | Manager | Receptionist |
|---|---|---|---|
| View properties | all | all | own only |
| CRUD properties | ✓ | — | — |
| CRUD buildings | ✓ | — | — |
| View rooms | all (+ filters) | all (+ filters) | own property only |
| Create/edit/delete rooms | ✓ | ✓ | — (unchanged role matrix) |
| Room status transitions | ✓ | ✓ | ✓ (own property rooms only) |
| Bookings list / lifecycle | all | all | own property only |
| Payments list / verify / reject / receipt | all | all | own property only |
| Refunds | ✓ | ✓ | — (unchanged) |
| Invoices | all | all | own property only |
| Dashboard / Analytics / Reports (+ exports) | all + filters | all + filters | — (unchanged role matrix) |
| Calendar | all | all | own property only |
| Employees CRUD | ✓ | — | — (unchanged) |

- New `PropertyPolicy` and `BuildingPolicy` follow the two-layer convention: `role:*` route middleware + `$this->authorize()` re-check in the controller.
- `BookingPolicy` / `PaymentPolicy` gain a property-consistency check for receptionist actions (booking/payment's rooms must belong to the actor's property).
- Room write actions for receptionists were already disallowed; unchanged.

---

## 9. Dashboard / Analytics / Reports integration

- `AnalyticsService::analytics()` and `dashboard()` gain optional `?int $propertyId = null, ?int $buildingId = null, ?int $roomTypeId = null` parameters (admin/manager only — controllers pass the validated params).
- Filtering is applied **inside every aggregation query**:
  - booking-scoped queries via `bookingsIn()` + the property/building/room-type join;
  - payment-scoped queries (verified/refunded/status counts) via the booking join chain (`payments → bookings → booking_rooms → rooms → buildings`);
  - `room_occupancy` / `room_types` occupancy snapshots via rooms → buildings;
  - dashboard inventory/today KPIs likewise.
- `ReportService` gains the same optional params on every report method; CSV export reuses the same method call so exports always match on-screen data.
- Frontend: dashboard gets the **global property selector** (All Properties + active list) — a dropdown that appends `property_id` to the request and refetches; **no page reload**. Analytics page and ReportFilters gain property/building/room-type selects via URL search params (same pattern as today's date/status filters).

---

## 10. Frontend plan

- **Sidebar:** new item **"Properties"** → `/staff/properties` (visible to all staff roles; receptionists land on their own property).
- **New routes:**
  - `/staff/properties` — list (search/filter: name, code, city, province, country, status; paginated)
  - `/staff/properties/new` — create form (admin)
  - `/staff/properties/:id` — detail (info, address, contacts, map toggle, buildings, summary cards)
  - `/staff/properties/:id/edit` — edit form (admin)
  - `/staff/properties/:id/buildings/new` — building create (admin)
  - `/staff/buildings/:id/edit` — building edit (admin)
- **PropertyDetail:** OSM iframe embed, **optional** (toggle button), only when lat/lng are set; iframe `src` built from coordinates, no API key, no package.
- **RoomForm:** required building select (grouped by property); backend validates.
- **Rooms list:** property + building filter dropdowns (admin/manager; receptionists see their scope without selectors).
- **Dashboard:** property selector in the header of the overview section; selection drives `useDashboardOverview` params; CSV export passes the same param.
- **Analytics:** property (+ building) filter row; `useAnalytics` params extended.
- **ReportFilters:** property/building/room-type selects added to URL search params; exports/Print inherit them.
- New `src/api/propertyApi.js` following the existing axios client conventions; new hooks (`useProperties`, `usePropertyDetail`) following the lint-clean fetch pattern already used by `useDashboardOverview`/`useAnalytics`.

---

## 11. Testing plan

### Backend (Pest feature tests)

1. **Property CRUD + validation:** admin create/list/show/update/soft-delete; code uniqueness; required name/code; lat/lng decimal range + format validation; email/URL format; status enum; list filters (name/code/city/province/country/status); manager/receptionist denied writes (403).
2. **Building CRUD:** nested create under property; unique code per property (same code allowed in different properties); update/delete admin-only.
3. **Rooms:** create requires valid `building_id`; duplicate `101` rejected in the same building; `101` allowed in a different building; room list `property_id`/`building_id` filters return correct subsets.
4. **Booking integrity:** mixed-property room set → 422; `property_id` mismatch (Room from Property C with Property A claimed) → 422; inactive property/building rooms rejected; availability with `property_id` returns only that property's rooms and omits inactive-property rooms.
5. **Receptionist scoping:** seeded second property; receptionist assigned to Property A sees only Property A rooms/bookings/payments/invoices/calendar; request `property_id=B` is ignored (still A's data); property detail for B → 403; booking lifecycle on Property B's booking → 403; employee create with receptionist + no property → 422; admin/manager employee with property → forced null / rejected.
6. **Dashboard/Analytics/Reports filtering:** seed bookings/payments across two properties; assert `property_id` produces different SQL-derived counts for summary, trends, status counts, revenue, occupancy, room-type stats; report endpoints + CSV exports respect filters.
7. **Migration/backfill:** after `migrate:fresh`, all rooms belong to Main Hotel/Main Building; composite unique index present; global unique index gone; receptionists backfilled; duplicate room numbers across buildings insertable.
8. **Regression:** full existing suite (517 tests) stays green — no existing endpoint behavior changes without a filter.

### Frontend (Vitest + React Testing Library)

- `propertyApi` client tests (endpoints, params, error mapping).
- PropertiesList (search/filter/pagination), PropertyForm (validation messages, admin-only route guard), PropertyDetail (map toggle renders iframe only when enabled + coords present; summary cards).
- RoomForm building select (grouped options, required validation).
- Dashboard property selector (changing selection refetches with `property_id`; export passes it).
- ReportFilters/Analytics property+building selects (URL params round-trip).
- Existing 704 tests stay green; `oxlint` clean; `vite build` passes.

### Full gate (before "done")

`php artisan test` · Pint (modified files) · `vitest run` · `npm run lint` · `npm run build` — all green; dev DB migrated; browser smoke test of the golden paths (property CRUD, dashboard filter, availability, receptionist isolation).

---

## 12. Out of scope (explicit non-goals)

- Floor table / per-floor management (floors remain a room column).
- Per-property room types or pricing (room types stay global).
- Currency conversion, timezone-aware scheduling logic, or check-in/out time enforcement (stored data only).
- Multi-tenancy for customers (customers are global).
- Map providers beyond the OSM iframe; no Leaflet package.
- Any change to booking pricing, discounts, payment verification, or invoice issuance flows.

---

## 13. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Global room_number uniqueness swap could fail on duplicate rows | Migration first backfills all rooms to one building (numbers stay unique); composite index added only after; rollback restores the old index |
| Receptionist scope bypass via URL params | Effective filter resolved from the authenticated row server-side; params ignored for receptionists; policy checks on lifecycle actions |
| Report/analytics SQL joins degrade performance | Indexes on `rooms.building_id`, `buildings.property_id` + composite indexes following the existing `analytics_indexes` pattern; filters optional — no-param queries stay as fast as today |
| Frontend regression | Property filters are additive; default (no filter) renders exactly today's data; full existing test suites run before completion |
| Property soft-delete leaves rooms bookable via public site | Public endpoints filter `status = active` on property and building; availability/booking validation rejects inactive chains |

---

## 14. Definition of done

1. Migrations create the tables/columns and backfill exactly as specified, with `down()` safe.
2. All new endpoints + filter params implemented with validation, two-layer auth, and ApiResponse envelopes.
3. Receptionist scoping enforced server-side; booking integrity rules in `BookingService`.
4. Dashboard/Analytics/Reports (+ exports) support property/building/room-type filters in SQL.
5. Frontend pages/components/routes per §10; no existing page regressed.
6. All tests, lint, and builds green (backend + frontend); dev DB migrated; browser smoke test passed.
