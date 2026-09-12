# Frontend Integration Plan — Phase 1: Architecture & API Contract

> **Status:** Phase 1 (plan) and Phase 2 (authentication frontend) COMPLETE.
> **Backend baseline:** 430 tests / 1,322 assertions / 0 failures
> **Date:** 2026-09-10

---

## 0.1a. Phase 2 Completion Report (Authentication Frontend)

Implemented 2026-09-10 in the sibling project at `/Applications/XAMPP/xamppfiles/htdocs/hotel-frontend`.

**Delivered**
- Vite + React 19 + Tailwind scaffold (`npm run dev` on port `5173`), deps: `axios ^1.7.9`, `react-router-dom ^7.1.5`, `vitest ^3.2.7` + Testing Library + `axios-mock-adapter`.
- `src/api/client.js` — axios instance; request interceptor attaches `Authorization: Bearer <token>` from localStorage; response interceptor clears auth + dispatches `auth:unauthorized` window event on any 401.
- `src/api/authApi.js` — `customerAuth` (register/login/logout/me via `/customer/auth/*`, `/customer/me`) and `staffAuth` (login via `/admin/auth/login`, logout via `/staff/auth/logout`, me via `/staff/me`).
- `src/context/AuthContext.jsx` + `src/context/auth-context.js` + `src/hooks/useAuth.js` — session state `{ user, token, actor }` persisted under `hotel_auth` in localStorage; hydration on refresh validates the stored actor via its `/me` endpoint (customer data is the entity directly under `data`; staff employee likewise); `customerLogin/customerRegister/staffLogin/logout/refreshUser/hasRole`.
- `src/routes/ProtectedRoute.jsx` (unauthenticated → login w/ `state.from`; actor-crossing redirects; optional role lists → Forbidden) and `src/routes/PublicOnlyRoute.jsx` (authed customer → `/profile`, staff → `/staff/dashboard`).
- Route trees `src/routes/CustomerApp.jsx` (`/*`) and `src/routes/StaffApp.jsx` (`/staff/*`) wired in `src/App.jsx`. Guest routes wrapped in `PublicLayout`; staff under `StaffLayout` (responsive sidebar; nav filters Discounts to admin/manager).
- Pages: customer Home/Login/Register/Profile; staff Login + Dashboard placeholder; `ComingSoon` placeholders for future staff modules; `Forbidden`/`NotFound`.

**Verification (actual results)**
- Frontend lint (`npm run lint`, oxlint): clean, no warnings.
- Frontend tests (`npm run test`, Vitest 3.2.7): **6 files / 34 tests passed** — authStorage, apiError, apiClient (headers + 401 handling), AuthContext (hydration, login/register, 401 event, logout), ProtectedRoute, PublicOnlyRoute.
- Frontend build (`npm run build`): production build succeeds (dist, ~106 kB gzipped JS).
- Backend regression (`php artisan test`): **430 passed / 1,322 assertions / 0 failures** — matches baseline, no regressions, no backend code modified.

**Notes / deviations from this plan**
- Route guards enforce role lists before render, but the backend remains the source of truth (§11.3 unchanged).
- Staff `/me` and customer `/me` return the entity directly under `data` (no `{customer}`/`{employee}` wrapper) — confirmed against `Api/V1/{Customer,Staff}/AuthController::me`.
- `auth-context.js` named lowercase (not `authContext`) to avoid a Vite case-insensitive-module collision with `AuthContext.jsx` on macOS.
- React Router v7 descendant `<Routes>` used instead of the plan's per-route `lazy()` wrappers; `main.jsx` hosts `BrowserRouter` + `AuthProvider`.

---

## 0.1. Backend Audit Findings (Critical)

Before detailing the frontend plan, several findings from the source inspection
must be documented:

### 0.1 STEP-12 (Staff Booking on Behalf) — NOT Implemented

The `docs/STEP-12-IMPLEMENTATION-REPORT.md` exists and claims 335 tests with a
`POST /api/v1/staff/bookings` endpoint. However, the actual current code does
**NOT** contain this feature:

- `Staff/BookingController.php` has **no `store()` method** (only index/show/
  confirm/cancel/checkIn/checkOut).
- `BookingPolicy.php` has **no `createForCustomer()` method**.
- `StaffCreateBookingRequest.php` **does not exist**.
- `routes/api.php` has **no `POST /staff/bookings`** route.

**Impact on frontend:** The staff "New Booking" / walk-in booking feature cannot
be integrated until the backend adds this endpoint. The frontend must NOT
implement a staff booking creation UI that calls a non-existent endpoint.
This feature must be added to the backend first (or deferred).

### 0.2 Employee Roles — Hardcoded Enum

Employee roles are `['admin', 'manager', 'receptionist']` — stored in the
`employees.role` enum column. There is no `departments` CRUD surface exposed
via API (the table exists but no endpoints read/write it).

### 0.3 Booking Statuses — Complete List

```
pending_payment, confirmed, checked_in, checked_out, cancelled, no_show
```

### 0.4 Payment Statuses — Complete List

```
pending, receipt_uploaded, verified, rejected, refunded
```

### 0.5 Room Statuses — Complete List

```
available, reserved, occupied, cleaning, maintenance
```

### 0.6 Payment Methods — Complete List

```
bank_transfer, cash
```

---

## 1. Frontend Project Structure

```
hotel-frontend/
├── index.html
├── package.json
├── vite.config.js
├── tailwind.config.js
├── postcss.config.js
├── .env.development
├── .env.production
├── public/
│   └── favicon.ico
└── src/
    ├── main.jsx                      # Entry point, router mount
    ├── App.jsx                       # Router layout definitions
    ├── api/
    │   ├── client.js                 # Axios instance + interceptors
    │   ├── auth.js                   # login/register/logout/me endpoints
    │   ├── rooms.js                  # Public room/room-type/image endpoints
    │   ├── bookings.js               # Customer booking endpoints
    │   ├── payments.js               # Customer payment endpoints
    │   ├── invoices.js               # Customer invoice endpoints
    │   ├── staffBookings.js          # Staff booking endpoints
    │   ├── staffPayments.js          # Staff payment endpoints
    │   ├── staffRooms.js             # Staff room CRUD + status endpoints
    │   ├── staffRoomTypes.js         # Staff room type CRUD endpoints
    │   ├── staffRoomImages.js        # Staff room image endpoints
    │   ├── staffInvoices.js          # Staff invoice endpoints
    │   └── staffDiscounts.js         # Staff discount CRUD endpoints
    ├── context/
    │   └── AuthContext.jsx           # Auth state (token, user, role)
    ├── hooks/
    │   ├── useAuth.js                # Convenience wrapper for AuthContext
    │   └── usePagination.js          # Reusable pagination state
    ├── components/
    │   ├── common/
    │   │   ├── LoadingSpinner.jsx
    │   │   ├── EmptyState.jsx
    │   │   ├── ErrorMessage.jsx
    │   │   ├── Pagination.jsx
    │   │   ├── Modal.jsx
    │   │   ├── ConfirmDialog.jsx
    │   │   └── StatusBadge.jsx
    │   ├── customer/
    │   │   ├── Navbar.jsx
    │   │   └── Footer.jsx
    │   └── staff/
    │       ├── Sidebar.jsx
    │       ├── Topbar.jsx
    │       └── DashboardLayout.jsx
    ├── layouts/
    │   ├── PublicLayout.jsx          # Customer public-facing layout
    │   └── StaffLayout.jsx          # Staff sidebar+topbar layout
    ├── pages/
    │   ├── customer/
    │   │   ├── Home.jsx
    │   │   ├── Login.jsx
    │   │   ├── Register.jsx
    │   │   ├── RoomTypes.jsx         # Browse room types
    │   │   ├── RoomTypeDetail.jsx    # Single room type + images
    │   │   ├── Rooms.jsx             # Browse rooms
    │   │   ├── RoomDetail.jsx        # Single room
    │   │   ├── Availability.jsx      # Search availability
    │   │   ├── BookingCreate.jsx     # Create booking form
    │   │   ├── BookingList.jsx       # My bookings
    │   │   ├── BookingDetail.jsx     # Single booking
    │   │   ├── PaymentCreate.jsx     # Create payment for a booking
    │   │   ├── PaymentDetail.jsx     # Single payment + upload receipt
    │   │   ├── InvoiceList.jsx       # My invoices
    │   │   ├── InvoiceDetail.jsx     # Single invoice
    │   │   └── Profile.jsx           # View profile (from /me)
    │   └── staff/
    │       ├── Login.jsx
    │       ├── Dashboard.jsx
    │       ├── BookingList.jsx
    │       ├── BookingDetail.jsx
    │       ├── RoomList.jsx
    │       ├── RoomForm.jsx          # Create/edit room
    │       ├── RoomTypeList.jsx
    │       ├── RoomTypeForm.jsx      # Create/edit room type
    │       ├── RoomTypeImages.jsx    # Manage images for a type
    │       ├── CustomerList.jsx      # Placeholder (no endpoint yet)
    │       ├── PaymentList.jsx
    │       ├── PaymentDetail.jsx
    │       ├── InvoiceList.jsx
    │       ├── InvoiceDetail.jsx
    │       └── DiscountList.jsx
    ├── routes/
    │   ├── CustomerRoutes.jsx        # All customer route definitions
    │   └── StaffRoutes.jsx           # All staff route definitions
    ├── utils/
    │   ├── formatters.js             # Currency, date, status formatters
    │   ├── validators.js             # Client-side form validation helpers
    │   └── constants.js              # Status maps, role maps
    └── lib/
        └── helpers.js                # General utility functions
```

---

## 2. Exact Backend API Inventory

Base URL: `http://localhost:8000/api/v1`

### 2.1 Health

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/health` | No | — | — | — | — | `{success, message, data: {app, status, timestamp, database: {connected, message}}}` | 200 |

### 2.2 Public — Room Types

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/public/room-types` | No | — | — | — | `search`, `min_price`, `max_price`, `min_adults`, `min_children`, `sort` (name\|base_price\|created_at), `direction` (asc\|desc), `per_page` (1-50) | `{success, message, data: [...RoomType], meta}` | 200 |
| GET | `/public/room-types/{roomType}` | No | — | — | — | — | `{success, message, data: RoomType}` | 200 |
| GET | `/public/room-types/{roomType}/images` | No | — | — | — | — | `{success, message, data: [RoomImage]}` | 200 |

**RoomType fields:** `id, name, description, base_price, capacity_adults, capacity_children, amenities (array), created_at, updated_at`
**RoomImage fields:** `id, room_type_id, sort_order, url (computed), created_at, updated_at` (note: `path` is hidden)

### 2.3 Public — Rooms

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/public/rooms` | No | — | — | — | `search`, `room_type_id`, `floor`, `status` (available\|reserved\|occupied\|cleaning\|maintenance), `sort` (room_number\|floor\|status\|created_at), `direction`, `per_page` | `{success, message, data: [...Room], meta}` | 200 |
| GET | `/public/rooms/{room}` | No | — | — | — | — | `{success, message, data: Room (with roomType)}` | 200 |

**Room fields:** `id, room_number, room_type_id, floor, status, status_changed_at, cleaning_started_at, created_at, updated_at` (+ `roomType` relation)

### 2.4 Public — Availability

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| POST | `/public/availability` | No | — | — | `{check_in_date, check_out_date, rooms: [roomId, ...]}` | — | `{success, message, data: [{id, room_number, floor, status, room_type_id, price_per_night, discount?: {...}}]}` | 200 |

**Validation:** `check_in_date` required date after_or_equal:today; `check_out_date` required date after:check_in_date; `rooms` required array min:1 max:5 distinct integers existing in rooms table.

**Discount info (STEP 17):** When a pricing tier applies, each room item includes a `discount` key with `{name, min_nights, percent_off, discounted_price_per_night, original_total, discounted_total}`. Absent = no qualifying tier.

### 2.5 Customer — Auth

| Method | URL | Auth | Actor | Role | Body | Response | Status |
|--------|-----|------|-------|------|------|----------|--------|
| POST | `/customer/auth/register` | No | — | — | `{name, email, password, password_confirmation, phone?, address?}` | `{success, message, data: {customer, token}}` | 201 |
| POST | `/customer/auth/login` | No | — | — | `{email, password}` | `{success, message, data: {customer, token}}` | 200 |
| POST | `/customer/auth/logout` | Yes | customer | — | — | `{success, message}` | 200 |
| GET | `/customer/me` | Yes | customer | — | — | `{success, message, data: Customer}` | 200 |

**Customer fields:** `id, name, email, phone, address, status, created_at, updated_at` (password never returned)

**Validation errors (422):** `{success: false, message: "The given data was invalid.", errors: {field: [messages]}}`

### 2.6 Customer — Bookings

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/customer/bookings` | Yes | customer | — | — | `status` (pending_payment\|confirmed\|checked_in\|checked_out\|cancelled\|no_show) | `{success, message, data: [...Booking], meta}` | 200 |
| POST | `/customer/bookings` | Yes | customer | — | `{check_in_date, check_out_date, rooms: [roomId, ...]}` | — | `{success, message, data: Booking (+ discount?: {...})}` | 201 |
| GET | `/customer/bookings/{booking}` | Yes | customer | — | — | — | `{success, message, data: Booking (with bookingRooms.room, bookingRooms.roomType, customer)}` | 200 |
| POST | `/customer/bookings/{booking}/cancel` | Yes | customer | — | `{reason?: string}` | — | `{success, message, data: Booking}` | 200 |

**Booking fields:** `id, booking_number, customer_id, check_in_date, check_out_date, status, total_amount, payment_deadline_at, cancelled_at, cancellation_reason, no_show_at, late_fee_amount, late_fee_applied_at, created_at, updated_at`

**BookingRoom fields:** `id, booking_id, room_id, room_type_id, price_per_night, actual_check_in_at, actual_check_out_at, room (relation), roomType (relation)`

**IMPORTANT:** Customer can only cancel `pending_payment` bookings. Attempting to cancel `confirmed` → **409**.

### 2.7 Customer — Payments

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/customer/payments` | Yes | customer | — | — | `status` (pending\|receipt_uploaded\|verified\|rejected\|refunded), `per_page` | `{success, message, data: [...Payment], meta}` | 200 |
| POST | `/customer/payments` | Yes | customer | — | `{booking_id, method: "bank_transfer"\|"cash"}` | — | `{success, message, data: Payment}` | 201 |
| GET | `/customer/payments/{payment}` | Yes | customer | — | — | — | `{success, message, data: Payment (with booking, verifiedBy)}` | 200 |
| POST | `/customer/payments/{payment}/receipt` | Yes | customer | — | `receipt` (file: jpeg/png/pdf, max 4MB) — **multipart/form-data** | — | `{success, message, data: Payment}` | 200 |
| GET | `/customer/payments/{payment}/receipt` | Yes | customer | — | — | — | **File download** (binary) or `{success: false, message: "Receipt not found."}` | 200/404 |

**Payment fields:** `id, booking_id, amount, method, status, receipt_path (hidden from API), verified_by, verified_at, notes, created_at, updated_at`

**IMPORTANT:** Customer can only create payment for their own bookings. Amount is server-locked to `booking.total_amount`. Status/verification fields are never accepted from client.

### 2.8 Customer — Invoices

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/customer/invoices` | Yes | customer | — | — | `per_page` | `{success, message, data: [...Invoice], meta}` | 200 |
| GET | `/customer/invoices/{invoice}` | Yes | customer | — | — | — | `{success, message, data: Invoice (with payment.booking.customer, items)}` | 200 |

**Invoice fields:** `id, invoice_number, payment_id, total_amount, issued_at, created_at, updated_at`
**InvoiceItem fields:** `id, invoice_id, room_number, room_type_name, price_per_night, nights, line_total, created_at, updated_at`

### 2.9 Staff — Auth

| Method | URL | Auth | Actor | Role | Body | Response | Status |
|--------|-----|------|-------|------|------|----------|--------|
| POST | `/admin/auth/login` | No | — | — | `{email, password}` | `{success, message, data: {employee, token}}` | 200 |
| POST | `/staff/auth/logout` | Yes | staff | — | — | `{success, message}` | 200 |
| GET | `/staff/me` | Yes | staff | — | — | `{success, message, data: Employee}` | 200 |

**Employee fields:** `id, name, email, phone, role, department_id, status, created_at, updated_at` (password never returned)
**Employee roles:** `admin`, `manager`, `receptionist`

**NOTE:** Staff login URL is `/admin/auth/login`, NOT `/staff/auth/login`. This is by design (STEP 04).

### 2.10 Staff — Room Types

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/staff/room-types` | Yes | staff | any | — | `search`, `min_price`, `max_price`, `min_adults`, `min_children`, `sort`, `direction`, `per_page` | `{success, message, data: [...RoomType], meta}` | 200 |
| GET | `/staff/room-types/{roomType}` | Yes | staff | any | — | — | `{success, message, data: RoomType}` | 200 |
| POST | `/staff/room-types` | Yes | staff | admin,manager | `{name, description?, base_price, capacity_adults, capacity_children, amenities?: [...]}` | — | `{success, message, data: RoomType}` | 201 |
| PUT | `/staff/room-types/{roomType}` | Yes | staff | admin,manager | `{name, description?, base_price, capacity_adults, capacity_children, amenities?: [...]}` | — | `{success, message, data: RoomType}` | 200 |
| DELETE | `/staff/room-types/{roomType}` | Yes | staff | admin,manager | — | — | `{success, message}` | 200 |
| GET | `/staff/room-types/{roomType}/images` | Yes | staff | any | — | — | `{success, message, data: [RoomImage]}` | 200 |
| POST | `/staff/room-types/{roomType}/images` | Yes | staff | admin,manager | `image` (file: jpg/jpeg/png/webp, max 5MB) — **multipart/form-data** | — | `{success, message, data: RoomImage}` | 201 |

### 2.11 Staff — Room Images (top-level)

| Method | URL | Auth | Actor | Role | Body | Response | Status |
|--------|-----|------|-------|------|------|----------|--------|
| PUT | `/staff/room-images/{image}` | Yes | staff | admin,manager | `{sort_order: integer}` | `{success, message, data: RoomImage}` | 200 |
| DELETE | `/staff/room-images/{image}` | Yes | staff | admin,manager | — | `{success, message}` | 200 |

### 2.12 Staff — Rooms

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/staff/rooms` | Yes | staff | any | — | `search`, `room_type_id`, `floor`, `status`, `sort`, `direction`, `per_page` | `{success, message, data: [...Room], meta}` | 200 |
| GET | `/staff/rooms/{room}` | Yes | staff | any | — | — | `{success, message, data: Room (with roomType)}` | 200 |
| POST | `/staff/rooms` | Yes | staff | admin,manager | `{room_number, room_type_id, floor}` | — | `{success, message, data: Room}` | 201 |
| PUT | `/staff/rooms/{room}` | Yes | staff | admin,manager | `{room_number, room_type_id, floor}` | — | `{success, message, data: Room}` | 200 |
| DELETE | `/staff/rooms/{room}` | Yes | staff | admin,manager | — | — | `{success, message}` | 200 |
| POST | `/staff/rooms/{room}/status/maintenance` | Yes | staff | admin,manager,receptionist | `{reason?: string}` | — | `{success, message, data: Room}` | 200 |
| POST | `/staff/rooms/{room}/status/available` | Yes | staff | admin,manager,receptionist | `{reason?: string}` | — | `{success, message, data: Room}` | 200 |

### 2.13 Staff — Bookings

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/staff/bookings` | Yes | staff | any | — | `status`, `from` (date), `to` (date), `search` (booking_number, customer name/email) | `{success, message, data: [...Booking], meta}` | 200 |
| GET | `/staff/bookings/{booking}` | Yes | staff | any | — | — | `{success, message, data: Booking (with bookingRooms.room, bookingRooms.roomType, customer)}` | 200 |
| POST | `/staff/bookings/{booking}/confirm` | Yes | staff | admin,manager,receptionist | — | — | `{success, message, data: Booking}` | 200 |
| POST | `/staff/bookings/{booking}/cancel` | Yes | staff | admin,manager,receptionist | `{reason?: string}` | — | `{success, message, data: Booking}` | 200 |
| POST | `/staff/bookings/{booking}/check-in` | Yes | staff | admin,manager,receptionist | — | — | `{success, message, data: Booking}` | 200 |
| POST | `/staff/bookings/{booking}/check-out` | Yes | staff | admin,manager,receptionist | — | — | `{success, message, data: Booking}` | 200 |

**Note:** `POST /staff/bookings` (create on behalf) does NOT exist. See §0.1.

### 2.14 Staff — Payments

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/staff/payments` | Yes | staff | any | — | `status`, `search` (booking_number, customer name/email), `per_page` | `{success, message, data: [...Payment], meta}` | 200 |
| GET | `/staff/payments/{payment}` | Yes | staff | any | — | — | `{success, message, data: Payment (with booking.customer, verifiedBy)}` | 200 |
| GET | `/staff/payments/{payment}/receipt` | Yes | staff | any | — | — | **File download** (binary) or 404 | 200/404 |
| POST | `/staff/payments/{payment}/verify` | Yes | staff | admin,manager,receptionist | — | — | `{success, message, data: Payment}` | 200 |
| POST | `/staff/payments/{payment}/reject` | Yes | staff | admin,manager,receptionist | `{reason: string}` | — | `{success, message, data: Payment}` | 200 |
| POST | `/staff/payments/{payment}/refund` | Yes | staff | admin,manager | `{reason: string}` | — | `{success, message, data: Payment}` | 200 |

**IMPORTANT:** Verify also confirms the booking and generates an invoice atomically (STEP 16). Reject requires a reason. Refund also cancels the booking atomically (STEP 11).

### 2.15 Staff — Invoices

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/staff/invoices` | Yes | staff | any | — | `search` (booking_number, customer name/email), `per_page` | `{success, message, data: [...Invoice], meta}` | 200 |
| GET | `/staff/invoices/{invoice}` | Yes | staff | any | — | — | `{success, message, data: Invoice (with payment.booking.customer, items)}` | 200 |

### 2.16 Staff — Discounts

| Method | URL | Auth | Actor | Role | Body | Query | Response | Status |
|--------|-----|------|-------|------|------|-------|----------|--------|
| GET | `/staff/discounts` | Yes | staff | admin,manager | — | `search`, `per_page` | `{success, message, data: [...Discount], meta}` | 200 |
| POST | `/staff/discounts` | Yes | staff | admin,manager | `{name, min_nights, percent_off}` | — | `{success, message, data: Discount}` | 201 |
| GET | `/staff/discounts/{discount}` | Yes | staff | admin,manager | — | — | `{success, message, data: Discount}` | 200 |
| PUT | `/staff/discounts/{discount}` | Yes | staff | admin,manager | `{name, min_nights, percent_off}` | — | `{success, message, data: Discount}` | 200 |
| DELETE | `/staff/discounts/{discount}` | Yes | staff | admin,manager | — | — | `{success, message}` | 200 |

**Discount fields:** `id, name, min_nights, percent_off, created_at, updated_at`

---

## 3. Authentication Architecture

### 3.1 Two Separate Auth Systems

The backend uses a **single Sanctum guard** with a **polymorphic `tokenable`**
relationship. Tokens belong to either a `Customer` or an `Employee` model.
The `actor` middleware checks which model type the token belongs to.

**Customer auth:**
- Register: `POST /api/v1/customer/auth/register` → returns `{customer, token}`
- Login: `POST /api/v1/customer/auth/login` → returns `{customer, token}`
- Logout: `POST /api/v1/customer/auth/logout` (requires auth:customer)
- Me: `GET /api/v1/customer/me` (requires auth:customer)

**Staff auth:**
- Login: `POST /api/v1/admin/auth/login` → returns `{employee, token}`
- Logout: `POST /api/v1/staff/auth/logout` (requires auth:staff)
- Me: `GET /api/v1/staff/me` (requires auth:staff)
- **No staff registration** — accounts created by admin (not yet implemented)

### 3.2 Token Handling

- Token format: `plainTextToken` (Sanctum personal access token)
- Token expiry: `SANCTUM_EXPIRATION` env var (default 10080 min = 7 days)
- Token is sent as: `Authorization: Bearer {token}`
- **Current token:** only the current session's token is revoked on logout
  (not all tokens)
- Token is **NOT** tied to a specific actor type at creation — the polymorphic
  `tokenable` determines actor type

### 3.3 Token Persistence Strategy

```
localStorage key: "hotel_auth"
Value: {
  token: string,        // Bearer token
  user: Customer|Employee,
  actor: "customer" | "staff"
}
```

- Store token in `localStorage` (persists across tabs/sessions, simpler for
  university project; not ideal for production but appropriate here)
- On app load, read from localStorage, call `/me` endpoint to validate
  If `/me` returns 401, clear localStorage and redirect to login

### 3.4 Actor / Role Separation

- **Customer routes** require `actor:customer` middleware → a staff token
  cannot access customer endpoints (403)
- **Staff routes** require `actor:staff` middleware → a customer token
  cannot access staff endpoints (403)
- **Role middleware** (`role:admin,manager`) further restricts within staff
  → a receptionist gets 403 on admin/manager-only routes

### 3.5 Protected Route Strategy

```jsx
// Customer protected route
<PrivateRoute actor="customer">
  <BookingList />
</PrivateRoute>

// Staff protected route (any staff role)
<PrivateRoute actor="staff">
  <StaffDashboard />
</PrivateRoute>

// Staff route requiring specific roles
<PrivateRoute actor="staff" roles={["admin", "manager"]}>
  <DiscountList />
</PrivateRoute>
```

- `PrivateRoute` checks: (1) token exists, (2) user loaded via `/me`,
  (3) actor matches, (4) role in allowed list (if specified)
- On 401: clear auth, redirect to login
- On 403: redirect to appropriate unauthorized page or staff dashboard

---

## 4. Axios Architecture

### 4.1 Base Configuration

```js
// src/api/client.js
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1',
  headers: { 'Accept': 'application/json' },
});
```

### 4.2 Request Interceptor

```js
api.interceptors.request.use((config) => {
  const auth = JSON.parse(localStorage.getItem('hotel_auth'));
  if (auth?.token) {
    config.headers.Authorization = `Bearer ${auth.token}`;
  }
  return config;
});
```

### 4.3 Response Interceptor (401 Handling)

```js
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('hotel_auth');
      // Dispatch custom event or use router to redirect
      window.dispatchEvent(new Event('auth:unauthorized'));
    }
    return Promise.reject(error);
  }
);
```

### 4.4 Common API Error Shape

All errors from the backend follow one envelope:

```json
{
  "success": false,
  "message": "string",
  "errors": null | { "field": ["message"] }
}
```

The Axios interceptor should normalize this for consistent UI handling.

### 4.5 File Upload Handling

For receipt uploads and room image uploads, use `FormData`:

```js
const formData = new FormData();
formData.append('receipt', file);  // or 'image' for room images
await api.post(`/customer/payments/${id}/receipt`, formData, {
  headers: { 'Content-Type': 'multipart/form-data' },
});
```

### 4.6 File Download Handling

Receipt downloads return binary content. Use `responseType: 'blob'`:

```js
const response = await api.get(`/customer/payments/${id}/receipt`, {
  responseType: 'blob',
});
// Create download link
const url = window.URL.createObjectURL(new Blob([response.data]));
```

---

## 5. State Management

### 5.1 AuthContext (React Context API)

```js
// AuthContext provides:
{
  user: Customer | Employee | null,
  token: string | null,
  actor: "customer" | "staff" | null,
  isAuthenticated: boolean,
  isCustomer: boolean,
  isStaff: boolean,
  hasRole: (roles: string[]) => boolean,  // staff only
  login: (credentials, actor) => Promise,
  register: (data) => Promise,
  logout: () => Promise,
  refreshUser: () => Promise,  // call /me
}
```

- One context, **shared** between customer and staff
- `actor` field distinguishes the two (stored alongside token)
- No Redux needed — this project is simple enough for Context + local state

### 5.2 Local Component State

Use for:
- Form state (controlled inputs)
- UI state (modals, active tab, expanded accordion)
- Single-page data that doesn't need sharing (a detail view's loaded data)

### 5.3 Custom Hooks

| Hook | Purpose |
|------|---------|
| `useAuth()` | Convenience wrapper for AuthContext consumer |
| `usePagination()` | Manages page/per_page state, provides page change handlers |
| `useFetch(url, deps)` | Simple data-fetching hook with loading/error/data states |

### 5.4 API Services

Each `src/api/*.js` file is a thin function-per-endpoint wrapper. No state
management inside them — they return Promises. The component/hook manages
the loading/error/data lifecycle.

---

## 6. Route Architecture

### 6.1 Customer Routes

```
/                           → Home (public)
/login                      → Customer login (public)
/register                   → Customer register (public)
/rooms                      → Browse rooms (public)
/rooms/:id                  → Room detail (public)
/room-types                 → Browse room types (public)
/room-types/:id             → Room type detail + images (public)
/availability               → Search availability (public)
/bookings/new               → Create booking (auth: customer)
/bookings                   → My bookings list (auth: customer)
/bookings/:id               → Booking detail (auth: customer)
/payments/new/:bookingId    → Create payment (auth: customer)
/payments/:id               → Payment detail + receipt upload (auth: customer)
/invoices                   → My invoices (auth: customer)
/invoices/:id               → Invoice detail (auth: customer)
/profile                    → Profile view (auth: customer)
```

### 6.2 Staff Routes

```
/staff/login                → Staff login (public)
/staff/dashboard            → Dashboard (auth: staff)
/staff/bookings             → Booking list (auth: staff, any role)
/staff/bookings/:id         → Booking detail (auth: staff, any role)
/staff/rooms                → Room list (auth: staff, any role)
/staff/rooms/new            → Create room (auth: staff, admin/manager)
/staff/rooms/:id/edit       → Edit room (auth: staff, admin/manager)
/staff/room-types           → Room type list (auth: staff, any role)
/staff/room-types/new       → Create room type (auth: staff, admin/manager)
/staff/room-types/:id/edit  → Edit room type (auth: staff, admin/manager)
/staff/room-types/:id/images → Manage images (auth: staff, admin/manager)
/staff/payments             → Payment list (auth: staff, any role)
/staff/payments/:id         → Payment detail (auth: staff, any role)
/staff/invoices             → Invoice list (auth: staff, any role)
/staff/invoices/:id         → Invoice detail (auth: staff, any role)
/staff/discounts            → Discount list (auth: staff, admin/manager)
/staff/discounts/new        → Create discount (auth: staff, admin/manager)
/staff/discounts/:id/edit   → Edit discount (auth: staff, admin/manager)
```

### 6.3 Route Protection Rules

| Scenario | Behavior |
|----------|----------|
| Unauthenticated user hits `/bookings` | Redirect to `/login` |
| Unauthenticated user hits `/staff/dashboard` | Redirect to `/staff/login` |
| Customer token hits `/staff/dashboard` | Redirect to `/` (or unauthorized page) |
| Staff token hits `/bookings` (customer) | Redirect to `/staff/dashboard` |
| Receptionist hits `/staff/discounts` | 403 page (role insufficient) |
| Guest hits `/availability` | Allowed (public) |

### 6.4 Lazy Loading / Code Splitting

```js
// Customer section
const CustomerRoutes = lazy(() => import('./pages/customer/CustomerRoutes'));

// Staff section
const StaffRoutes = lazy(() => import('./pages/staff/StaffRoutes'));
```

Split at the section level (customer vs staff), not at individual page level.
This keeps two chunks: one for customer, one for staff.

---

## 7. UI / Layout Architecture

### 7.1 Customer Layout (`PublicLayout.jsx`)

```
┌──────────────────────────────────────────────┐
│  Navbar (logo, nav links, login/register     │
│  or profile dropdown)                        │
├──────────────────────────────────────────────┤
│                                              │
│  <Outlet /> (page content)                   │
│                                              │
├──────────────────────────────────────────────┤
│  Footer (hotel info, links)                  │
└──────────────────────────────────────────────┘
```

- Responsive: hamburger menu on mobile, inline nav on desktop
- Navbar shows different items based on auth state:
  - Guest: Home, Rooms, Room Types, Availability, Login, Register
  - Customer: Home, Rooms, My Bookings, My Invoices, Profile, Logout

### 7.2 Staff Layout (`StaffLayout.jsx`)

```
┌─────────┬────────────────────────────────────┐
│         │  Topbar (user name, role, logout)  │
│ Sidebar ├────────────────────────────────────┤
│         │                                    │
│ Dashboard│  <Outlet /> (page content)        │
│ Bookings │                                    │
│ Rooms    │                                    │
│ Room Types│                                   │
│ Payments │                                    │
│ Invoices │                                    │
│ Discounts│                                    │
│         │                                    │
└─────────┴────────────────────────────────────┘
```

- Sidebar is fixed on desktop (≥1024px), collapses to hamburger on tablet/mobile
- Sidebar items filtered by role: Discounts visible only to admin/manager
- Active route highlighted in sidebar
- Topbar always shows logged-in employee name and role badge

### 7.3 Responsive Breakpoints (Tailwind)

| Breakpoint | Width | Behavior |
|------------|-------|----------|
| Mobile | < 640px | Single column, hamburger nav, stacked forms |
| Tablet | 640-1023px | Two-column where appropriate, collapsible sidebar |
| Desktop | ≥ 1024px | Full sidebar, multi-column layouts |

---

## 8. Feature Integration Order

### Phase A — Foundation (start here)

1. Vite + React project scaffolding
2. Tailwind CSS setup
3. Axios client with interceptors
4. AuthContext + AuthProvider
5. React Router setup (customer + staff route trees)
6. Route guards (PrivateRoute component)
7. Layout components (PublicLayout, StaffLayout)
8. Common components (LoadingSpinner, EmptyState, ErrorMessage, StatusBadge)
9. Environment configuration (.env.development, .env.production)

### Phase B — Authentication

10. Customer login page
11. Customer register page
12. Staff login page
13. Profile page (customer `/me`)
14. Session restore on refresh (localStorage → `/me` call)
15. Logout behavior

### Phase C — Public Browsing

16. Room types list page (with filters: price, capacity)
17. Room type detail page + images
18. Rooms list page (with filters: type, floor, status)
19. Room detail page
20. Availability search page

### Phase D — Customer Booking Flow

21. Booking creation form (from availability results)
22. Customer bookings list
23. Customer booking detail
24. Customer booking cancellation

### Phase E — Customer Payment & Invoices

25. Payment creation (choose method)
26. Payment detail + receipt upload
27. Receipt download
28. Customer invoices list
29. Customer invoice detail (line items)

### Phase F — Staff Booking & Operations

30. Staff bookings list (with filters: status, date range, search)
31. Staff booking detail (with action buttons: confirm, cancel, check-in, check-out)
32. Staff booking lifecycle actions

### Phase G — Staff Payment & Refund

33. Staff payments list (with filters: status, search)
34. Staff payment detail
35. Receipt download (staff view)
36. Payment verification / rejection
37. Refund action (admin/manager only)

### Phase H — Staff Room Management

38. Staff rooms list (with filters)
39. Staff room create/edit form
40. Staff room status transitions (maintenance/available)
41. Staff room type list
42. Staff room type create/edit form
43. Staff room type image management (upload, reorder, delete)

### Phase I — Staff Invoices & Discounts

44. Staff invoices list (with search)
45. Staff invoice detail
46. Staff discount list
47. Staff discount create/edit form
48. Staff discount delete

### Phase J — Dashboard & Polish

49. Staff dashboard (summary stats — may need new backend endpoint or
    use existing list endpoints for counts)
50. Responsive polish pass
51. Error boundary
52. Loading skeleton components

---

## 9. Booking Frontend Data Flow

### 9.1 Customer Booking Flow

```
1. Browse rooms / room types (public pages)
         │
         ▼
2. Search availability: POST /public/availability
   {check_in_date, check_out_date, rooms: [roomIds]}
         │
         ▼
3. See available rooms with pricing (server-computed)
   + discount info if applicable
         │
         ▼
4. Select rooms → fill booking form
   (check_in_date, check_out_date, room selections)
         │
         ▼
5. Submit: POST /customer/bookings
   {check_in_date, check_out_date, rooms: [roomIds]}
   → 201: booking created, status = "pending_payment"
   → booking.payment_deadline_at set (2h hold by default)
         │
         ▼
6. Create payment: POST /customer/payments
   {booking_id, method: "bank_transfer" | "cash"}
   → 201: payment created, status = "pending"
         │
         ▼
7. Upload receipt: POST /customer/payments/{id}/receipt
   (multipart: receipt file — jpeg/png/pdf, max 4MB)
   → 200: payment status → "receipt_uploaded"
         │
         ▼
8. Staff reviews: POST /staff/payments/{id}/verify  (or /reject)
   → verify: payment "verified" + booking "confirmed" + invoice generated
   → reject: payment "rejected" (customer must re-upload)
         │
         ▼
9. Booking confirmed → rooms → "reserved"
         │
         ▼
10. Check-in: POST /staff/bookings/{id}/check-in
    → booking "checked_in" + rooms "occupied"
         │
         ▼
11. Check-out: POST /staff/bookings/{id}/check-out
    → booking "checked_out" + rooms → "cleaning"
         │
         ▼
12. Invoice visible: GET /customer/invoices/{id}
```

### 9.2 Cancellation Flows

**Customer cancels (pending_payment only):**
```
POST /customer/bookings/{id}/cancel {reason?}
→ booking "cancelled", rooms released to "available"
```

**Staff cancels (pending_payment or confirmed):**
```
POST /staff/bookings/{id}/cancel {reason?}
→ booking "cancelled"
→ rooms released (reserved → available)
→ if confirmed + inside late-cancel window:
   late_fee_amount + late_fee_applied_at recorded
```

**Staff refunds (verified payment):**
```
POST /staff/payments/{id}/refund {reason: required}
→ payment "refunded"
→ booking "cancelled"
→ rooms released
→ full amount refunded (no partial)
```

### 9.3 Automated Flows (no frontend action)

- **Hold expiry:** `pending_payment` bookings past `payment_deadline_at` → auto
  cancelled by hourly sweep (`bookings:release-expired-holds`)
- **No-show:** `confirmed` bookings with `check_in_date < today` → auto marked
  `no_show`, rooms released (`bookings:mark-no-show`, hourly)
- **Cleaning:** `checked_out` rooms → `available` after `cleaning_buffer_minutes`
  (15 min default, `rooms:complete-cleaning`, hourly)
- **Late fee:** computed server-side during staff cancellation of `confirmed`
  bookings inside the late-cancel window (24h before check-in day)

### 9.4 Booking Status Display Map

| Status | Label | Color | Customer-visible | Staff-visible |
|--------|-------|-------|------------------|---------------|
| `pending_payment` | Pending Payment | Yellow | Yes | Yes |
| `confirmed` | Confirmed | Green | Yes | Yes |
| `checked_in` | Checked In | Blue | Yes | Yes |
| `checked_out` | Checked Out | Gray | Yes | Yes |
| `cancelled` | Cancelled | Red | Yes | Yes |
| `no_show` | No Show | Red | Yes | Yes |

---

## 10. File Uploads

### 10.1 Room Type Images (Staff Only)

- **Endpoint:** `POST /staff/room-types/{roomType}/images`
- **Field name:** `image`
- **Accepted types:** jpg, jpeg, png, webp
- **Max size:** 5MB (5120 KB)
- **Format:** `multipart/form-data`
- **Response:** RoomImage with computed `url` field (public URL via Storage::disk('public'))
- **Usage:** Staff upload → public pages display images via the `url` field

### 10.2 Payment Receipts (Customer Only)

- **Endpoint:** `POST /customer/payments/{payment}/receipt`
- **Field name:** `receipt`
- **Accepted types:** jpeg, png, pdf
- **Max size:** 4MB (4096 KB)
- **Format:** `multipart/form-data`
- **Response:** Updated payment object
- **Download (customer):** `GET /customer/payments/{id}/receipt` → binary blob
- **Download (staff):** `GET /staff/payments/{id}/receipt` → binary blob
- **Storage:** Private disk (`storage/app/private/receipts/`). The `receipt_path` field
  is hidden from API responses. Only the file download endpoint serves the content.

### 10.3 Profile Images

**Not supported.** No backend endpoint accepts profile image uploads.
Do not implement a profile image upload in the frontend.

---

## 11. Permissions Matrix

### 11.1 Customer Actions

| Action | Customer |
|--------|----------|
| Browse rooms/room types | Yes (public) |
| Search availability | Yes (public) |
| Create booking | Yes (own) |
| View own bookings | Yes |
| Cancel own booking (pending_payment only) | Yes |
| Create payment | Yes (own booking) |
| Upload receipt | Yes (own payment) |
| Download receipt | Yes (own payment) |
| View own invoices | Yes |
| View profile | Yes |

### 11.2 Staff Actions

| Action | Receptionist | Manager | Admin |
|--------|-------------|---------|-------|
| View room types | Yes | Yes | Yes |
| Create/edit/delete room types | No | Yes | Yes |
| View rooms | Yes | Yes | Yes |
| Create/edit/delete rooms | No | Yes | Yes |
| Change room status (maintenance/available) | Yes | Yes | Yes |
| Upload/manage room type images | No | Yes | Yes |
| View bookings | Yes | Yes | Yes |
| Confirm booking | Yes | Yes | Yes |
| Cancel booking | Yes | Yes | Yes |
| Check-in / Check-out | Yes | Yes | Yes |
| View payments | Yes | Yes | Yes |
| Verify / reject payment | Yes | Yes | Yes |
| Refund payment | No | Yes | Yes |
| View invoices | Yes | Yes | Yes |
| View/create/edit/delete discounts | No | Yes | Yes |

### 11.3 Frontend Rule

The frontend **hides** buttons/links for actions the user's role cannot perform.
This is a UX convenience only — **the backend is the final authority.**
A user who bypasses the UI (e.g., via browser dev tools) will receive a 403.

---

## 12. Error Handling

### 12.1 HTTP Status Code Mapping

| Code | Meaning | Frontend Action |
|------|---------|-----------------|
| 200 | Success | Process response data |
| 201 | Created | Process response data, show success toast |
| 401 | Unauthenticated | Clear auth, redirect to login |
| 403 | Forbidden | Show "You don't have permission" message; if staff, redirect to dashboard |
| 404 | Not Found | Show "Resource not found" page or toast |
| 409 | Conflict (Business Rule) | Show server `message` (e.g., "Room is not available", "Invalid status transition") |
| 422 | Validation Error | Show field-level errors from `errors` object |
| 500 | Server Error | Show generic "Something went wrong" message |

### 12.2 Error Response Normalization

All backend errors follow:
```json
{
  "success": false,
  "message": "Human-readable message",
  "errors": null | { "field_name": ["Error message 1", "Error message 2"] }
}
```

The Axios response interceptor should detect `success === false` and pass
the error through for component-level handling.

---

## 13. Loading / Empty / Error States

Every page that fetches data from the API must handle four states:

| State | Component | Notes |
|-------|-----------|-------|
| Loading | `<LoadingSpinner />` | Shown while API request is in-flight |
| Empty | `<EmptyState message="No bookings yet" />` | Shown when API returns empty array |
| Error | `<ErrorMessage message={error} />` | Shown on 4xx/5xx responses |
| Success | Page content | Rendered with data from API |

**Examples:**

| Page | Empty Message | Notes |
|------|--------------|-------|
| Customer Booking List | "You haven't made any bookings yet" | |
| Staff Booking List | "No bookings found" | Include filter reset link |
| Staff Payment List | "No payments found" | |
| Invoice List | "No invoices yet" | |
| Room Type List | "No room types configured" | Staff only |
| Discount List | "No discounts configured" | Admin/manager only |

---

## 14. API Response Normalization

**Do NOT invent a new response format.** Use the exact Laravel response
structure documented in `ApiResponse.php`:

### Success (single resource):
```json
{
  "success": true,
  "message": "OK",
  "data": { ... }
}
```

### Success (paginated):
```json
{
  "success": true,
  "message": "OK",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "per_page": 15,
    "total": 42,
    "last_page": 3
  }
}
```

### Error:
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."],
    "password": ["The password confirmation does not match."]
  }
}
```

**Frontend wrapper (optional):** You may create a thin wrapper that
extracts `response.data.data` and `response.data.meta` for convenience,
but the raw response should remain accessible.

---

## 15. Environment Configuration

### 15.1 `.env.development`

```
VITE_API_BASE_URL=http://localhost:8000/api/v1
```

### 15.2 `.env.production`

```
VITE_API_BASE_URL=https://your-production-domain.com/api/v1
```

### 15.3 Usage

```js
const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';
```

Vite exposes `VITE_`-prefixed env vars to the client bundle at build time.
Never put secrets in `VITE_` env vars — they are embedded in the built JS.

---

## 16. Security

### 16.1 Token Handling

- Store in `localStorage` (acceptable for university project scope)
- Always send via `Authorization: Bearer {token}` header
- Clear on 401 response or explicit logout
- Never log tokens to console in production builds
- Token expiry is 7 days (backend configurable)

### 16.2 Client-Side Trust Boundaries

| Field | Trusted from Client? | Why |
|-------|---------------------|-----|
| Booking dates | Yes (validated by backend) | Shape only; server enforces business rules |
| Booking room IDs | Yes (validated by backend) | Existence checked server-side |
| Booking total_amount | **NO** | Server-computed from room snapshots |
| Booking status | **NO** | Server-controlled lifecycle |
| Payment amount | **NO** | Server-locked to booking total |
| Payment status | **NO** | Server-controlled lifecycle |
| Payment receipt_path | **NO** | Server-managed storage path |
| Booking owner (customer_id) | **NO** | Customer route: derived from token; Staff route: `customer_id` only in staff create (not yet implemented) |
| Room status | **NO** | Only set via explicit status endpoints |
| Invoice data | **NO** | Server-generated, immutable |
| Late fee amount | **NO** | Server-computed during cancellation |
| Employee role | **NO** | From server `/me` response only; never sent in requests |

### 16.3 Authorization Enforcement

- Frontend hides UI elements the user's role cannot access
- Backend enforces all authorization via middleware + policies
- Never send API requests for actions the UI hides (defense in depth)

### 16.4 Receipt Handling

- Receipt file is stored on a **private** disk (not publicly accessible)
- Download requires authentication + ownership check (customer) or any
  staff role (staff)
- Never expose `receipt_path` in the UI (it is hidden from API responses
  via `$hidden` on the Payment model)
- Use the download endpoint + blob for viewing/downloading receipts

### 16.5 Staff Route Protection

- All staff routes are behind `actor:staff` middleware
- Discount management additionally requires `role:admin,manager`
- Refund requires `role:admin,manager` (admin + manager only)
- Frontend should filter sidebar/nav items by role, but backend is final authority

---

## 17. Responsive UX

### 17.1 Desktop (≥1024px)

- Customer: full navbar, multi-column room grids, sidebar filters
- Staff: fixed sidebar, full data tables, side-by-side forms

### 17.2 Tablet (640-1023px)

- Customer: collapsible navbar, two-column room grids
- Staff: collapsible sidebar (hamburger toggle), tables scroll horizontally

### 17.3 Mobile (< 640px)

- Customer: hamburger menu, single-column layout, full-width forms
- Staff: hamburger sidebar overlay, stacked cards instead of tables where
  appropriate, action buttons in dropdown menus

### 17.4 Key Responsive Components

- **Navbar:** Hamburger on mobile, inline links on desktop
- **Staff sidebar:** Overlay on mobile/tablet, fixed on desktop
- **Data tables:** Horizontal scroll on mobile, full display on desktop
- **Booking detail:** Stacked layout on mobile, side-by-side on desktop
- **Forms:** Full-width inputs on mobile, inline where appropriate on desktop
- **Room grid:** 1 column mobile, 2 tablet, 3-4 desktop
- **Status badges:** Consistent sizing across breakpoints

---

## 18. Testing Strategy

### 18.1 Route Guard Tests

- Unauthenticated redirect to login for protected routes
- Customer redirect away from staff routes
- Staff redirect away from customer routes
- Role-based access (receptionist blocked from admin/manager pages)

### 18.2 Authentication Tests

- Customer register flow (success + validation errors)
- Customer login flow (success + invalid credentials + inactive account)
- Staff login flow (success + invalid credentials + inactive account)
- Token persistence across page refresh
- Session restore via `/me` endpoint
- Logout clears token and redirects

### 18.3 API Service Tests

- Axios interceptors attach Authorization header
- 401 interceptor clears auth and redirects
- Pagination helpers correctly extract meta
- File upload sends FormData with correct content type
- File download handles blob response

### 18.4 Booking Flow Tests

- Availability search returns correct rooms
- Booking creation sends correct shape
- Booking list renders with correct statuses
- Booking detail shows correct relations
- Booking cancel button only appears for pending_payment status
- Late cancel fee displays when present

### 18.5 Payment Upload Tests

- Receipt upload sends FormData
- Upload success updates payment status in UI
- Receipt download triggers file save
- Invalid file type rejected client-side before upload

### 18.6 Staff Authorization UI Tests

- Discounts link hidden for receptionist
- Room create button hidden for receptionist
- Refund button hidden for receptionist
- Verify/reject buttons visible for receptionist

### 18.7 Error Handling Tests

- 401 clears auth state
- 403 shows permission message
- 404 shows not-found message
- 422 displays field validation errors
- 409 displays business rule conflict message
- 500 shows generic error

---

## 19. Out-of-Scope (Phase 1)

The following are **explicitly NOT** part of this frontend integration phase:

1. **Staff booking on behalf** — STEP-12 backend endpoint (`POST /staff/bookings`)
   does not exist in the current codebase. Must be implemented in the backend
   first.
2. **Staff user management** — no endpoint exists to create/list/edit staff
   accounts from the UI.
3. **Department management** — no API endpoints exist.
4. **Dashboard statistics** — no summary/statistics endpoint exists. The dashboard
   page is a placeholder until backend endpoints are added.
5. **Notifications / email** — not implemented in backend.
6. **Real-time updates** — no WebSocket/polling. Pages refresh on action.
7. **Advanced search / date range picker libraries** — basic HTML date inputs
   first; enhance later.
8. **PDF invoice generation/download** — backend serves invoice data as JSON only.
9. **Print-friendly invoice view** — implement basic display first.
10. **Internationalization (i18n)** — English only.
11. **Dark mode** — not in Phase 1.
12. **End-to-end Cypress/Playwright tests** — unit/component tests first.
13. **Admin panel for managing employees** — no API surface.
14. **Customer profile editing** — no update endpoint exists for customer profile.
15. **Room availability calendar view** — basic list/table first.
16. **Payment gateway integration** — the backend has none; this is bank transfer
    / cash only with manual receipt upload.
17. **Responsive testing automation** — manual testing in Phase 1.
18. **Performance optimization** (virtual lists, memoization audits) — defer.
19. **Redux / Zustand / other state libraries** — Context API is sufficient.

---

## Appendix A: Backend API Endpoint Count Summary

| Category | Count | Auth Required |
|----------|-------|---------------|
| Health | 1 | No |
| Public (rooms, room-types, images, availability) | 6 | No |
| Customer auth | 4 | Partial |
| Customer bookings | 4 | Yes (customer) |
| Customer payments | 5 | Yes (customer) |
| Customer invoices | 2 | Yes (customer) |
| Staff auth | 3 | Partial |
| Staff room-types | 7 | Yes (staff) |
| Staff room-images | 2 | Yes (staff) |
| Staff rooms | 7 | Yes (staff) |
| Staff bookings | 6 | Yes (staff) |
| Staff payments | 6 | Yes (staff) |
| Staff invoices | 2 | Yes (staff) |
| Staff discounts | 5 | Yes (staff, admin/manager) |
| **Total** | **60** | |

## Appendix B: Discrepancies Found

| Item | Expected | Actual |
|------|----------|--------|
| Test count | 447 (per STEP-17 report) | 430 |
| STEP-12 staff booking create | Implemented | NOT implemented (no store method, no route, no request, no policy method) |
| `StaffCreateBookingRequest.php` | Exists per STEP-12 report | Does not exist |
| `BookingPolicy::createForCustomer()` | Exists per STEP-12 report | Does not exist |

The STEP-12 report appears to describe code that was written and tested but
is not present in the current codebase. The backend must be verified/updated
before the frontend staff booking-on-behalf feature can be integrated.
