# FRONTEND PHASE 3 — PUBLIC HOTEL BROWSING

**Status:** PLAN (awaiting approval)  
**Date:** 2026-09-10  
**Backend baseline:** 430 tests / 1,322 assertions / 0 failures  
**Frontend baseline:** 34 tests / 6 files — all passing  

---

## 1. Actual API Endpoints Discovered

All endpoints are under `POST /api/v1/public/` (no authentication required).

### 1.1 Room Types

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/public/room-types` | Paginated list with filters |
| `GET` | `/public/room-types/{roomType}` | Single room type detail |
| `GET` | `/public/room-types/{roomType}/images` | All images for a room type (non-paginated array) |

### 1.2 Rooms

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/public/rooms` | Paginated list with filters |
| `GET` | `/public/rooms/{room}` | Single room (eager-loads `roomType`) |

### 1.3 Availability

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/public/availability` | Search available rooms for date range |

---

## 2. Exact Request / Query Structures

### 2.1 GET /public/room-types Query Parameters

```
search      string|null   max:255                          Search by name (LIKE %search%)
min_price   numeric|null  min:0                            Filter base_price >= value
max_price   numeric|null  min:0, gte:min_price             Filter base_price <= value
min_adults  integer|null  min:0                            Filter capacity_adults >= value
min_children integer|null min:0                            Filter capacity_children >= value
sort        string|null   in:name,base_price,created_at    Sort field (default: name)
direction   string|null   in:asc,desc                      Sort direction (default: asc)
per_page    integer|null  min:1, max:50                    Items per page (default: 15)
```

### 2.2 GET /public/rooms Query Parameters

```
search        string|null   max:255                                   Search by room_number (LIKE %search%)
room_type_id  integer|null  exists:room_types,id                       Filter by room type
floor         integer|null  min:1, max:200                             Filter by floor
status        string|null   in:available,reserved,occupied,cleaning,maintenance   Filter by status
sort          string|null   in:room_number,floor,status,created_at    Sort field (default: room_number)
direction     string|null   in:asc,desc                                Sort direction (default: asc)
per_page      integer|null  min:1, max:50                              Items per page (default: 15)
```

### 2.3 POST /public/availability Request Body

```json
{
  "check_in_date": "2026-09-15",          // required, date, after_or_equal:today
  "check_out_date": "2026-09-18",         // required, date, after:check_in_date
  "rooms": [1, 2, 3]                      // required, array, min:1, max:5, distinct, each exists in rooms
}
```

**Important:** The `rooms` field is an array of room IDs to check, NOT a room count. The availability search checks specific rooms for availability over the date range.

---

## 3. Exact Response Structures

### 3.1 Paginated Response (room-types index, rooms index)

```json
{
  "success": true,
  "message": "OK",
  "data": [
    {
      "id": 1,
      "name": "Standard Room",
      "description": "A comfortable room with essential amenities",
      "base_price": "89.00",
      "capacity_adults": 2,
      "capacity_children": 1,
      "amenities": ["wifi", "tv", "air conditioning"],
      "created_at": "2026-01-15T08:00:00.000000Z",
      "updated_at": "2026-01-15T08:00:00.000000Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 15,
    "total": 42,
    "last_page": 3
  }
}
```

**Notes:**
- `base_price` is a string with 2 decimal places (e.g. `"89.00"`)
- `amenities` is a JSON array or null
- Soft-deleted room types are automatically excluded (route model binding + SoftDeletes)

### 3.2 Room Type Detail Response

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "id": 1,
    "name": "Standard Room",
    "description": "A comfortable room with essential amenities",
    "base_price": "89.00",
    "capacity_adults": 2,
    "capacity_children": 1,
    "amenities": ["wifi", "tv", "air conditioning"],
    "created_at": "2026-01-15T08:00:00.000000Z",
    "updated_at": "2026-01-15T08:00:00.000000Z"
  }
}
```

### 3.3 Room Type Images Response

```json
{
  "success": true,
  "message": "OK",
  "data": [
    {
      "id": 5,
      "room_type_id": 1,
      "sort_order": 0,
      "url": "http://localhost:8000/storage/room-types/1/abc123.jpg",
      "created_at": "2026-01-20T10:30:00.000000Z",
      "updated_at": "2026-01-20T10:30:00.000000Z"
    }
  ]
}
```

**Notes:**
- `path` is hidden (never exposed to client)
- `url` is an appended accessor using `Storage::disk('public')->url()`
- Images are ordered by `sort_order` asc, then `id` asc (via the relationship)
- Non-paginated (full array returned)

### 3.4 Room List Response (with eager-loaded roomType)

```json
{
  "success": true,
  "message": "OK",
  "data": [
    {
      "id": 101,
      "room_number": "101",
      "room_type_id": 1,
      "floor": 1,
      "status": "available",
      "status_changed_at": "2026-09-10T08:00:00.000000Z",
      "cleaning_started_at": null,
      "created_at": "2026-01-15T08:00:00.000000Z",
      "updated_at": "2026-09-10T08:00:00.000000Z",
      "room_type": {
        "id": 1,
        "name": "Standard Room",
        "base_price": "89.00",
        "capacity_adults": 2,
        "capacity_children": 1
      }
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 15,
    "total": 30,
    "last_page": 2
  }
}
```

**Notes:**
- `roomType` (camelCase) is the eager-loaded relationship, NOT `room_type`
- The room index endpoint eager-loads `roomType` for ALL rooms in the paginated list
- Room status values: `available`, `reserved`, `occupied`, `cleaning`, `maintenance`
- The public room endpoint shows ALL non-deleted rooms regardless of status

### 3.5 Room Detail Response

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "id": 101,
    "room_number": "101",
    "room_type_id": 1,
    "floor": 1,
    "status": "available",
    "status_changed_at": "2026-09-10T08:00:00.000000Z",
    "cleaning_started_at": null,
    "created_at": "2026-01-15T08:00:00.000000Z",
    "updated_at": "2026-09-10T08:00:00.000000Z",
    "room_type": {
      "id": 1,
      "name": "Standard Room",
      "description": "...",
      "base_price": "89.00",
      "capacity_adults": 2,
      "capacity_children": 1,
      "amenities": ["wifi", "tv", "air conditioning"]
    }
  }
}
```

### 3.6 Availability Search Response

```json
{
  "success": true,
  "message": "OK",
  "data": [
    {
      "id": 101,
      "room_number": "101",
      "floor": 1,
      "status": "available",
      "room_type_id": 1,
      "price_per_night": "89.00"
    },
    {
      "id": 102,
      "room_number": "102",
      "floor": 1,
      "status": "available",
      "room_type_id": 1,
      "price_per_night": "89.00",
      "discount": {
        "discount_id": 3,
        "name": "Weekly Stay",
        "min_nights": 7,
        "percent_off": 15.00,
        "nights": 7,
        "price_per_night": "89.00",
        "discounted_price_per_night": "75.65",
        "total_for_stay": "623.00",
        "total_after_discount": "529.55",
        "saved_total": "93.45"
      }
    }
  ]
}
```

**Notes:**
- This is NOT paginated (returns a flat array)
- Only rooms that are available for ALL requested dates are returned
- `maintenance` rooms are excluded
- `discount` is conditionally present (only when a pricing tier applies)
- `price_per_night` is the base price (from `roomType->base_price`)
- The discount object includes both original and discounted pricing

### 3.7 Error Response (422 Validation)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "check_in_date": ["The check in date field is required."],
    "rooms.0": ["The selected rooms.0 is invalid."]
  }
}
```

### 3.8 Error Response (404)

```json
{
  "success": false,
  "message": "No query results for model [App\\Models\\RoomType] X."
}
```

---

## 4. Frontend Files to Create/Modify

### 4.1 Files to CREATE

| File | Purpose |
|------|---------|
| `src/api/roomApi.js` | Public API service for room types, rooms, images, availability |
| `src/hooks/useApi.js` | Generic data-fetching hook (loading/error/data state) |
| `src/pages/customer/Rooms.jsx` | Room type browsing page (listing + search/filter/sort/pagination) |
| `src/pages/customer/RoomDetails.jsx` | Single room type detail page (info, images, rooms, pricing) |
| `src/pages/customer/AvailabilitySearch.jsx` | Availability search page |
| `src/components/customer/RoomTypeCard.jsx` | Card component for room type listing |
| `src/components/customer/RoomCard.jsx` | Card component for room listing |
| `src/components/customer/RoomGallery.jsx` | Image gallery for room type detail |
| `src/components/customer/SearchBar.jsx` | Search input component |
| `src/components/customer/FilterBar.jsx` | Filter controls component |
| `src/components/customer/Pagination.jsx` | Page navigation component |
| `src/components/common/EmptyState.jsx` | Empty state display |
| `src/components/common/ErrorState.jsx` | Error state display |
| `src/components/common/LoadingState.jsx` | Loading state display (inline, not fullScreen) |
| `src/test/roomApi.test.js` | Tests for room API service |
| `src/test/useApi.test.js` | Tests for useApi hook |
| `src/test/roomsPage.test.jsx` | Tests for Rooms page |
| `src/test/roomDetailsPage.test.jsx` | Tests for RoomDetails page |
| `src/test/availabilitySearch.test.jsx` | Tests for AvailabilitySearch page |
| `src/test/roomTypeCard.test.jsx` | Tests for RoomTypeCard component |
| `src/test/roomCard.test.jsx` | Tests for RoomCard component |
| `src/test/roomGallery.test.jsx` | Tests for RoomGallery component |
| `src/test/pagination.test.jsx` | Tests for Pagination component |
| `src/test/filterBar.test.jsx` | Tests for FilterBar component |
| `src/test/publicRoutes.test.jsx` | Tests for guest access to public routes |
| `src/test/homePage.test.jsx` | Tests for updated Home page |

### 4.2 Files to MODIFY

| File | Changes |
|------|---------|
| `src/routes/CustomerApp.jsx` | Add new routes: `/rooms`, `/rooms/:id`, `/availability` |
| `src/pages/customer/Home.jsx` | Upgrade to hotel landing page with hero, featured room types, CTA |
| `src/components/customer/Navbar.jsx` | Add "Rooms" and "Availability" nav links |
| `src/components/customer/Footer.jsx` | Add footer links to Rooms and Availability |

---

## 5. Component Structure

### 5.1 useApi Hook (`src/hooks/useApi.js`)

Generic hook for data fetching with loading/error/data states.

```
useApi(fetchFn, dependencies) => { data, error, isLoading, refetch }
```

- `fetchFn`: async function that returns data
- `dependencies`: array of deps to trigger refetch
- Returns: `{ data, error, isLoading, refetch }`

### 5.2 RoomTypeCard (`src/components/customer/RoomTypeCard.jsx`)

Props:
- `roomType` — room type object

Renders:
- Room type name
- Base price (formatted)
- Capacity (adults/children)
- Amenities (limited display)
- Link to detail page `/rooms/:id`

### 5.3 RoomCard (`src/components/customer/RoomCard.jsx`)

Props:
- `room` — room object (with roomType)

Renders:
- Room number
- Floor
- Status badge (color-coded)
- Room type name
- Price per night

### 5.4 RoomGallery (`src/components/customer/RoomGallery.jsx`)

Props:
- `images` — array of image objects

Renders:
- Main image display
- Thumbnail navigation
- Handles empty state (no images)

### 5.5 SearchBar (`src/components/customer/SearchBar.jsx`)

Props:
- `value` — current search value
- `onChange` — callback on value change
- `placeholder` — input placeholder

Renders:
- Text input with search icon
- Debounced onChange (300ms)

### 5.6 FilterBar (`src/components/customer/FilterBar.jsx`)

Props:
- `filters` — current filter values
- `onChange` — callback on filter change
- `sortOptions` — available sort fields
- `statusOptions` — available status values (for rooms)

Renders:
- Filter controls based on context (room type vs room filters)
- Sort dropdown
- Direction toggle
- Clear filters button

### 5.7 Pagination (`src/components/customer/Pagination.jsx`)

Props:
- `currentPage` — current page number
- `lastPage` — total pages
- `onPageChange` — callback on page change

Renders:
- Previous/Next buttons
- Page number buttons (with ellipsis for large page counts)
- Current page indicator

### 5.8 EmptyState (`src/components/common/EmptyState.jsx`)

Props:
- `title` — heading text
- `message` — description text
- `action` — optional CTA button

### 5.9 ErrorState (`src/components/common/ErrorState.jsx`)

Props:
- `error` — error object or message
- `onRetry` — optional retry callback

### 5.10 LoadingState (`src/components/common/LoadingState.jsx`)

Props:
- `fullScreen` — boolean (default false)

Renders:
- Inline spinner (default) or full-screen spinner

---

## 6. Page Structure

### 6.1 Home Page (UPGRADE)

The current Home page is a placeholder. Upgrade to a hotel landing experience:

**Sections:**
1. **Hero** — Full-width hero with hotel name, tagline, and CTA to browse rooms
2. **Featured Room Types** — Fetch first 3 room types from API and display as cards
3. **Features/Benefits** — 3-column feature cards (already partially exist)
4. **CTA Section** — Call to action for availability search

**Data fetching:**
- Fetch room types on mount (limit to first 3, use per_page=3)
- Handle loading/error states gracefully

### 6.2 Rooms Page (`/rooms`)

**Layout:**
- Page header with title and description
- Search bar (search by name)
- Filter bar (price range, capacity, sort, direction)
- Grid of RoomTypeCards
- Pagination at bottom

**Data fetching:**
- Fetch room types with query params from URL state
- Update URL params on filter/search/sort changes (useSearchParams)
- Debounce search input

**States:**
- Loading: Show skeleton/spinner
- Empty: "No room types found" with clear filters button
- Error: ErrorState with retry
- Success: RoomTypeCard grid

### 6.3 Room Details Page (`/rooms/:id`)

**Layout:**
- Back link to rooms list
- Room type name and description
- Image gallery (RoomGallery)
- Amenities list
- Capacity display (adults/children)
- Price per night (prominent)
- Available rooms count (fetched from rooms endpoint filtered by room_type_id)
- CTA to check availability

**Data fetching:**
- Fetch room type by ID
- Fetch room type images
- Fetch rooms for this room type (to show available room count)

**States:**
- Loading: Show skeleton
- Not found: 404-style message with back link
- Error: ErrorState with retry
- Success: Full detail view

### 6.4 Availability Search Page (`/availability`)

**Layout:**
- Search form with check-in/check-out date pickers
- Room selection (list of available rooms to check)
- Search button
- Results list

**Important:** The availability API requires specific room IDs. The workflow is:
1. User selects dates
2. User can optionally filter rooms (by type, floor, etc.)
3. User clicks search
4. API returns available rooms for those dates

**Alternative approach (simpler UX):**
- Show a room list with filters
- User selects dates and clicks "Check Availability"
- Filter the results to show only available rooms

**States:**
- Initial: Show search form with room list
- Loading: Show spinner during search
- Empty: "No rooms available for selected dates"
- Error: ErrorState
- Success: List of available rooms with pricing

---

## 7. Routing Updates

### 7.1 CustomerApp.jsx Changes

Add new routes inside the `<Route element={<PublicLayout />}>` group:

```jsx
<Route path="rooms" element={<Rooms />} />
<Route path="rooms/:id" element={<RoomDetails />} />
<Route path="availability" element={<AvailabilitySearch />} />
```

These routes are PUBLIC (no auth required). Guests and authenticated users can access them.

### 7.2 Navbar Updates

Add navigation links:
- "Rooms" → `/rooms`
- "Availability" → `/availability`

These links are visible to all users (guests and authenticated).

---

## 8. API Service Layer

### 8.1 roomApi.js

```javascript
import apiClient from './client'

export const publicRooms = {
  // Room Types
  listRoomTypes: (params) => apiClient.get('/public/room-types', { params }),
  getRoomType: (id) => apiClient.get(`/public/room-types/${id}`),
  getRoomTypeImages: (id) => apiClient.get(`/public/room-types/${id}/images`),

  // Rooms
  listRooms: (params) => apiClient.get('/public/rooms', { params }),
  getRoom: (id) => apiClient.get(`/public/rooms/${id}`),

  // Availability
  checkAvailability: (data) => apiClient.post('/public/availability', data),
}
```

**Notes:**
- Uses the existing `apiClient` with interceptors
- No authentication headers needed (public endpoints)
- Consistent with existing `authApi.js` pattern

---

## 9. UI Design Guidelines

### 9.1 Design System

- **Colors:** Use existing brand palette (brand-50 to brand-700) plus slate for neutrals
- **Typography:** Tailwind defaults (no custom fonts)
- **Shadows:** Use `shadow-sm`, `shadow-md`, `shadow-lg` for cards
- **Rounded corners:** `rounded-xl` for cards, `rounded-lg` for buttons
- **Spacing:** Consistent padding/margins using Tailwind scale
- **Responsive:** Mobile-first, breakpoint at `sm:` (640px) and `lg:` (1024px)

### 9.2 Component Styling

**Cards:**
```jsx
<div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
```

**Buttons:**
- Primary: `btn-primary` class (existing)
- Secondary: `btn-outline` class (existing)
- Ghost: `text-slate-600 hover:text-slate-900`

**Inputs:**
- Use existing `input` class
- Add search icon prefix for SearchBar

**Status Badges:**
- Available: `bg-green-100 text-green-800`
- Reserved: `bg-yellow-100 text-yellow-800`
- Occupied: `bg-red-100 text-red-800`
- Cleaning: `bg-blue-100 text-blue-800`
- Maintenance: `bg-slate-100 text-slate-800`

### 9.3 Responsive Layout

**Room Grid:**
- Mobile: 1 column
- Tablet (`sm:`): 2 columns
- Desktop (`lg:`): 3 columns

**Room Details:**
- Mobile: Stack vertically
- Tablet+: Side-by-side layout (gallery left, info right)

**Availability Search:**
- Mobile: Stack form and results
- Tablet+: Side-by-side (filters left, results right)

---

## 10. Testing Plan

### 10.1 API Service Tests (`roomApi.test.js`)

- Test each API function calls correct endpoint
- Test query params are passed correctly
- Test response handling
- Test error propagation

### 10.2 useApi Hook Tests (`useApi.test.js`)

- Test initial loading state
- Test successful data fetch
- Test error handling
- Test refetch functionality
- Test dependency-based refetch

### 10.3 Page Tests

**Rooms Page (`roomsPage.test.jsx`):**
- Renders room type list
- Shows loading state
- Shows empty state
- Shows error state
- Handles search input
- Handles filter changes
- Handles pagination
- Handles sort changes

**Room Details Page (`roomDetailsPage.test.jsx`):**
- Renders room type information
- Renders images
- Shows loading state
- Shows 404 for invalid ID
- Shows error state
- Displays amenities
- Displays pricing
- Shows CTA button

**Availability Search (`availabilitySearch.test.jsx`):**
- Renders search form
- Handles date selection
- Shows search results
- Shows loading state
- Shows empty results
- Shows error state

### 10.4 Component Tests

**RoomTypeCard (`roomTypeCard.test.jsx`):**
- Renders room type name
- Renders price
- Renders capacity
- Links to detail page

**RoomCard (`roomCard.test.jsx`):**
- Renders room number
- Renders floor
- Renders status badge
- Renders room type

**RoomGallery (`roomGallery.test.jsx`):**
- Renders images
- Handles empty images
- Handles single image
- Navigation between images

**Pagination (`pagination.test.jsx`):**
- Renders page numbers
- Handles page change
- Shows current page
- Disables prev/next at bounds

**FilterBar (`filterBar.test.jsx`):**
- Renders filter options
- Handles filter changes
- Clears filters

### 10.5 Route Navigation Tests (`publicRoutes.test.jsx`)

- Guest can access `/rooms`
- Guest can access `/rooms/:id`
- Guest can access `/availability`
- Authenticated user can access all public routes
- Navigation between pages works
- Back navigation works

### 10.6 Home Page Tests (`homePage.test.jsx`)

- Renders hero section
- Shows featured room types
- Handles loading state
- Handles API error gracefully
- CTA links work

### 10.7 Regression Tests

All existing 34 tests must remain green:
- apiClient tests (4)
- authStorage tests (5)
- authContext tests (9)
- protectedRoute tests (7)
- publicOnlyRoute tests (5)
- apiError tests (4)

---

## 11. Out-of-Scope Items

The following are explicitly OUT OF SCOPE for Phase 3:

1. **Customer booking flow** — No booking creation, cart, or checkout
2. **Payment processing** — No payment forms or receipt uploads
3. **Invoice display** — No customer invoice views
4. **Booking management** — No booking list, details, or cancellation
5. **User profile features** — Profile page already exists, no changes needed
6. **Authentication changes** — Auth system is complete, no modifications
7. **Staff interface** — Staff pages remain as ComingSoon placeholders
8. **Real-time availability** — No WebSocket or polling for live updates
9. **Room selection for booking** — No multi-select or cart functionality
10. **Date range validation beyond API** — Client validates format only; server validates business rules
11. **Image upload/admin** — Staff image management is out of scope
12. **Discount display logic** — Discounts are shown as returned by API, no client-side calculation
13. **SEO optimization** — No meta tags, structured data, or sitemap
14. **Internationalization** — English only
15. **Analytics/tracking** — No event tracking or analytics
16. **Service workers/PWA** — No offline support
17. **Animation/transitions** — Minimal, only loading spinners
18. **Dark mode** — Light theme only
19. **Advanced search (date-based)** — Availability search is date-based but not a full booking search widget

---

## 12. Implementation Order

### Phase 3a: Foundation
1. Create `src/api/roomApi.js` (API service layer)
2. Create `src/hooks/useApi.js` (generic data fetching hook)
3. Create common components: EmptyState, ErrorState, LoadingState
4. Add tests for API service and hook

### Phase 3b: Room Type Browsing
5. Create RoomTypeCard component
6. Create SearchBar component
7. Create FilterBar component
8. Create Pagination component
9. Create Rooms page
10. Add routes to CustomerApp.jsx
11. Update Navbar with new links
12. Add tests for all new components and page

### Phase 3c: Room Type Details
13. Create RoomGallery component
14. Create RoomDetails page
15. Add route to CustomerApp.jsx
16. Add tests

### Phase 3d: Availability Search
17. Create AvailabilitySearch page
18. Add route to CustomerApp.jsx
19. Update Navbar with availability link
20. Add tests

### Phase 3e: Home Page Upgrade
21. Upgrade Home page with hero, featured room types, CTA
22. Add tests

### Phase 3f: Polish & Regression
23. Responsive testing and fixes
24. Run full test suite (frontend + backend)
25. Lint check
26. Build verification

---

## 13. Key Decisions & Notes

### 13.1 Room Type vs Room Distinction

The backend has two separate concepts:
- **Room Type** (e.g., "Standard Room", "Deluxe Suite") — defines pricing, capacity, amenities
- **Room** (e.g., "Room 101", "Room 102") — physical room with status, linked to a room type

The public browsing experience focuses on **Room Types** (what guests care about). Individual rooms are shown in availability search results.

### 13.2 Availability Search UX

The availability API requires specific room IDs to check. This creates a UX challenge:
- Guests don't know room IDs
- They want to search by dates and room type

**Solution:** 
1. Show room types on the availability page
2. When user selects dates and a room type, fetch all rooms of that type
3. Send those room IDs to the availability API
4. Display available rooms

Alternatively, a simpler approach:
1. Show all rooms with filters
2. User enters dates
3. Check availability for all displayed rooms
4. Highlight/label available vs unavailable

### 13.3 Image URLs

Room image URLs are computed server-side via `Storage::disk('public')->url()`. In development, this typically produces URLs like `http://localhost:8000/storage/room-types/1/image.jpg`. The frontend should use these URLs directly.

### 13.4 Price Display

- Room type `base_price` is displayed as the nightly rate
- Availability response includes `price_per_night` (same as base_price)
- Discount information is displayed when present
- Frontend never calculates prices; always uses backend values

### 13.5 Room Status Display

Room status values from the API:
- `available` — Green badge
- `reserved` — Yellow badge
- `occupied` — Red badge
- `cleaning` — Blue badge
- `maintenance` — Gray badge

Status is informational only; guests cannot change room status.

### 13.6 Soft Delete Handling

- Deleted room types return 404 (via route model binding)
- Deleted rooms return 404
- Frontend handles 404 with "Not Found" message and back link

---

## 14. Verification Checklist

After implementation, verify:

- [ ] `npm run lint` — 0 warnings
- [ ] `npm run test` — All tests pass (existing 34 + new tests)
- [ ] `npm run build` — Successful production build
- [ ] Backend tests still pass (no backend changes)
- [ ] Guest can browse room types without login
- [ ] Guest can view room type details without login
- [ ] Guest can search availability without login
- [ ] Authenticated users can access all public pages
- [ ] Search/filter/sort/pagination work correctly
- [ ] Loading states display properly
- [ ] Empty states display properly
- [ ] Error states display properly
- [ ] Responsive design works on mobile/tablet/desktop
- [ ] Images load correctly
- [ ] Navigation between pages works
- [ ] Back buttons work correctly
- [ ] No API endpoints invented (only use actual backend)
- [ ] No client-side price calculation
- [ ] No booking functionality implemented
- [ ] No backend code modified

---

## 15. Risk Mitigation

### Risk 1: Availability Search Complexity
The availability API requires room IDs, not room type IDs. This may confuse users.
**Mitigation:** Provide clear UX that guides users through date selection → room type → available rooms flow.

### Risk 2: No Real-time Updates
Room availability may change between page load and booking.
**Mitigation:** Show disclaimer that availability is subject to change. Re-check availability before booking (Phase 4).

### Risk 3: Image URL Handling
Image URLs depend on server storage configuration.
**Mitigation:** Use the URL as-is from API. Handle broken images gracefully with placeholder.

### Risk 4: Pagination State Management
Multiple filter/pagination states need coordination.
**Mitigation:** Use URL search params as single source of truth. Simple state management with useState + useEffect.

---

*End of Phase 3 Implementation Plan*
