<?php

use App\Http\Controllers\Api\V1\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Api\V1\Customer\InvoiceController as CustomerInvoiceController;
use App\Http\Controllers\Api\V1\Customer\PaymentController as CustomerPaymentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Public\BookingController as PublicBookingController;
use App\Http\Controllers\Api\V1\Public\PropertyController as PublicPropertyController;
use App\Http\Controllers\Api\V1\Public\RoomController as PublicRoomController;
use App\Http\Controllers\Api\V1\Public\RoomImageController as PublicRoomImageController;
use App\Http\Controllers\Api\V1\Public\RoomTypeController as PublicRoomTypeController;
use App\Http\Controllers\Api\V1\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Api\V1\Staff\BookingController as StaffBookingController;
use App\Http\Controllers\Api\V1\Staff\BuildingController;
use App\Http\Controllers\Api\V1\Staff\DiscountController as StaffDiscountController;
use App\Http\Controllers\Api\V1\Staff\InvoiceController as StaffInvoiceController;
use App\Http\Controllers\Api\V1\Staff\PaymentController as StaffPaymentController;
use App\Http\Controllers\Api\V1\Staff\PropertyController as StaffPropertyController;
use App\Http\Controllers\Api\V1\Staff\RoomController as StaffRoomController;
use App\Http\Controllers\Api\V1\Staff\RoomImageController as StaffRoomImageController;
use App\Http\Controllers\Api\V1\Staff\RoomStatusController;
use App\Http\Controllers\Api\V1\Staff\RoomTypeController as StaffRoomTypeController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Every route in this project is versioned under /api/v1. The four groups
| below (public/customer/staff/admin) mirror the Architecture Baseline
| §5 route-separation table. public/ stays empty until STEP 06+ - no
| business modules yet.
|
*/

Route::prefix('v1')->group(function () {

    Route::get('/health', HealthController::class);

    // No auth. STEP 06: Room Type public browsing. STEP 07: Room public
    // browsing. STEP 08: Room Type image public browsing.
    Route::prefix('public')->group(function () {
        // STEP 09 - availability check. No auth, read-only. Returns the
        // rooms that are available for the dates plus server-computed
        // pricing preview. See docs/STEP-09-BOOKING-PLAN.md §2.
        Route::post('/availability', [PublicBookingController::class, 'availability']);

        Route::prefix('room-types')->group(function () {
            Route::get('/', [PublicRoomTypeController::class, 'index']);
            Route::get('/{roomType}', [PublicRoomTypeController::class, 'show']);
            Route::get('/{roomType}/images', [PublicRoomImageController::class, 'index']);
        });

        Route::prefix('rooms')->group(function () {
            Route::get('/', [PublicRoomController::class, 'index']);
            Route::get('/{room}', [PublicRoomController::class, 'show']);
        });

        Route::get('/properties', [PublicPropertyController::class, 'index']);
    });

    // Customer authentication - see docs/STEP-04-AUTH-PLAN.md §3.
    Route::prefix('customer')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('/register', [CustomerAuthController::class, 'register']);
            Route::post('/login', [CustomerAuthController::class, 'login']);
            Route::post('/logout', [CustomerAuthController::class, 'logout'])
                ->middleware(['auth:sanctum', 'actor:customer']);
        });

        Route::get('/me', [CustomerAuthController::class, 'me'])
            ->middleware(['auth:sanctum', 'actor:customer']);

        // STEP 09 - customer bookings. actor:customer only; ownership is
        // enforced inside the controller via BookingPolicy + query
        // scoping. See docs/STEP-09-BOOKING-PLAN.md §3.
        Route::prefix('bookings')->middleware(['auth:sanctum', 'actor:customer'])->group(function () {
            Route::get('/', [CustomerBookingController::class, 'index']);
            Route::post('/', [CustomerBookingController::class, 'store']);
            Route::get('/{booking}', [CustomerBookingController::class, 'show']);
            Route::post('/{booking}/cancel', [CustomerBookingController::class, 'cancel']);
        });

        // STEP 10 - customer payments. See
        // docs/STEP-10-PAYMENT-PLAN.md §2. Ownership is enforced in the
        // controller via PaymentPolicy + query scoping on the booking's
        // customer. Money/status/verification fields are never accepted.
        Route::prefix('payments')->middleware(['auth:sanctum', 'actor:customer'])->group(function () {
            Route::get('/', [CustomerPaymentController::class, 'index']);
            Route::post('/', [CustomerPaymentController::class, 'store']);
            Route::get('/{payment}', [CustomerPaymentController::class, 'show']);
            Route::post('/{payment}/receipt', [CustomerPaymentController::class, 'uploadReceipt']);
            Route::get('/{payment}/receipt', [CustomerPaymentController::class, 'downloadReceipt']);
        });

        // STEP 16 - customer invoices (read-only, own only). The invoice is
        // generated server-side inside PaymentService::verify - customers
        // can never write. See docs/STEP-16-IMPLEMENTATION-PLAN.md §3.
        Route::prefix('invoices')->middleware(['auth:sanctum', 'actor:customer'])->group(function () {
            Route::get('/', [CustomerInvoiceController::class, 'index']);
            Route::get('/{invoice}', [CustomerInvoiceController::class, 'show']);
        });
    });

    // Staff authentication (logout/me). Login lives under admin/ below -
    // see docs/STEP-04-AUTH-PLAN.md §3 for why.
    Route::prefix('staff')->group(function () {
        Route::post('/auth/logout', [StaffAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'actor:staff']);

        Route::get('/me', [StaffAuthController::class, 'me'])
            ->middleware(['auth:sanctum', 'actor:staff']);

        // STEP 05 - authorization test fixtures ONLY. These exist purely
        // to exercise the role middleware end-to-end before any real
        // business route needs role protection - they return a static
        // message, nothing else. Not real business endpoints; remove
        // once a real module (Rooms, Bookings, ...) adds real
        // role-protected routes that make these redundant. See
        // docs/STEP-05-AUTHORIZATION-PLAN.md §6.
        Route::prefix('_authz-check')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/any-staff', fn () => ApiResponse::success(null, 'OK - any staff role'));

            Route::get('/receptionist-task', fn () => ApiResponse::success(null, 'OK - receptionist task'))
                ->middleware('role:admin,manager,receptionist');

            Route::get('/manager-task', fn () => ApiResponse::success(null, 'OK - manager task'))
                ->middleware('role:admin,manager');

            Route::get('/admin-only', fn () => ApiResponse::success(null, 'OK - admin only'))
                ->middleware('role:admin');
        });

        // STEP 06 - Room Type management. View/list: any staff role.
        // Write actions: role:admin,manager (RoomTypePolicy re-checks
        // the same rule inside the controller - see
        // docs/STEP-06-ROOM-TYPE-PLAN.md §4).
        Route::prefix('room-types')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/', [StaffRoomTypeController::class, 'index']);
            Route::get('/{roomType}', [StaffRoomTypeController::class, 'show']);
            Route::post('/', [StaffRoomTypeController::class, 'store'])->middleware('role:admin,manager');
            Route::put('/{roomType}', [StaffRoomTypeController::class, 'update'])->middleware('role:admin,manager');
            Route::delete('/{roomType}', [StaffRoomTypeController::class, 'destroy'])->middleware('role:admin,manager');

            // STEP 08 - nested under room-types since these operate in
            // the context of a specific type. View: any staff role.
            // Upload: role:admin,manager.
            Route::get('/{roomType}/images', [StaffRoomImageController::class, 'index']);
            Route::post('/{roomType}/images', [StaffRoomImageController::class, 'store'])
                ->middleware('role:admin,manager');
        });

        // STEP 08 - top-level, matching the exact spec: an image is
        // identified by its own id, not nested under its room type, for
        // update/delete.
        Route::prefix('room-images')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::put('/{image}', [StaffRoomImageController::class, 'update'])->middleware('role:admin,manager');
            Route::delete('/{image}', [StaffRoomImageController::class, 'destroy'])->middleware('role:admin,manager');
        });

        // STEP 07 - Room management. View/list: any staff role.
        // Create/update/delete: role:admin,manager. Status changes:
        // role:admin,manager,receptionist - broader, matching the STEP
        // 07 authorization matrix (docs/STEP-07-ROOM-PLAN.md §3).
        Route::prefix('rooms')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/', [StaffRoomController::class, 'index']);
            Route::get('/{room}', [StaffRoomController::class, 'show']);
            Route::post('/', [StaffRoomController::class, 'store'])->middleware('role:admin,manager');
            Route::put('/{room}', [StaffRoomController::class, 'update'])->middleware('role:admin,manager');
            Route::delete('/{room}', [StaffRoomController::class, 'destroy'])->middleware('role:admin,manager');

            Route::post('/{room}/status/maintenance', [RoomStatusController::class, 'toMaintenance'])
                ->middleware('role:admin,manager,receptionist');
            Route::post('/{room}/status/available', [RoomStatusController::class, 'toAvailable'])
                ->middleware('role:admin,manager,receptionist');
        });

        // STEP 19 - Property/Building management. View: any staff role.
        // Write: role:admin (PropertyPolicy/BuildingPolicy re-check inside).
        Route::prefix('properties')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/', [StaffPropertyController::class, 'index']);
            Route::post('/', [StaffPropertyController::class, 'store'])->middleware('role:admin');
            Route::get('/{property}', [StaffPropertyController::class, 'show']);
            Route::put('/{property}', [StaffPropertyController::class, 'update'])->middleware('role:admin');
            Route::delete('/{property}', [StaffPropertyController::class, 'destroy'])->middleware('role:admin');

            Route::get('/{property}/buildings', [BuildingController::class, 'index']);
            Route::post('/{property}/buildings', [BuildingController::class, 'store'])->middleware('role:admin');
        });

        // Top-level, matching the room-images convention: identified by own id.
        Route::prefix('buildings')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/{building}', [BuildingController::class, 'show']);
            Route::put('/{building}', [BuildingController::class, 'update'])->middleware('role:admin');
            Route::delete('/{building}', [BuildingController::class, 'destroy'])->middleware('role:admin');
        });

        // STEP 09 - Staff booking management. View/list: any staff role.
        // Lifecycle actions (confirm/cancel/check-in/check-out):
        // admin,manager,receptionist - the keep-the-two-layer-pattern:
        // route role:* middleware first, then $this->authorize() re-checks
        // the same rule via BookingPolicy. See
        // docs/STEP-09-BOOKING-PLAN.md §3.
        Route::prefix('bookings')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/', [StaffBookingController::class, 'index']);

            Route::get('/{booking}', [StaffBookingController::class, 'show']);

            Route::post('/{booking}/confirm', [StaffBookingController::class, 'confirm'])
                ->middleware('role:admin,manager,receptionist');
            Route::post('/{booking}/cancel', [StaffBookingController::class, 'cancel'])
                ->middleware('role:admin,manager,receptionist');
            Route::post('/{booking}/check-in', [StaffBookingController::class, 'checkIn'])
                ->middleware('role:admin,manager,receptionist');
            Route::post('/{booking}/check-out', [StaffBookingController::class, 'checkOut'])
                ->middleware('role:admin,manager,receptionist');
        });

        // STEP 10 - Staff payment management. View/download: any staff.
        // Verify/reject: admin,manager,receptionist - route role:*
        // middleware first, then $this->authorize() re-checks via
        // PaymentPolicy. See docs/STEP-10-PAYMENT-PLAN.md §2/§3.
        Route::prefix('payments')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/', [StaffPaymentController::class, 'index']);
            Route::get('/{payment}', [StaffPaymentController::class, 'show']);
            Route::get('/{payment}/receipt', [StaffPaymentController::class, 'downloadReceipt']);

            Route::post('/{payment}/verify', [StaffPaymentController::class, 'verify'])
                ->middleware('role:admin,manager,receptionist');
            Route::post('/{payment}/reject', [StaffPaymentController::class, 'reject'])
                ->middleware('role:admin,manager,receptionist');

            // STEP 11 - refund. Financial authority is narrower than
            // verify/reject: process-refund Gate = admin,manager only
            // (docs/STEP-11-IMPLEMENTATION-PLAN.md §3).
            Route::post('/{payment}/refund', [StaffPaymentController::class, 'refund'])
                ->middleware('role:admin,manager');
        });

        // STEP 16 - staff invoices (read-only). Visible to any staff role -
        // same read tiering as staff payments. Generated only by payment
        // verification (the verify route stays role:admin,manager,receptionist).
        Route::prefix('invoices')->middleware(['auth:sanctum', 'actor:staff'])->group(function () {
            Route::get('/', [StaffInvoiceController::class, 'index']);
            Route::get('/{invoice}', [StaffInvoiceController::class, 'show']);
        });

        // STEP 17 - discount/tier master data. ADMIN+MANAGER (D-5 approved
        // value): role:admin,manager on the whole group, and DiscountPolicy
        // re-checks the same rule inside the controller. Consulted only at
        // booking time; editing/deleting a definition never mutates
        // historical bookings. See
        // docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-5/D-7.
        Route::prefix('discounts')->middleware(['auth:sanctum', 'actor:staff', 'role:admin,manager'])->group(function () {
            Route::get('/', [StaffDiscountController::class, 'index']);
            Route::post('/', [StaffDiscountController::class, 'store']);
            Route::get('/{discount}', [StaffDiscountController::class, 'show']);
            Route::put('/{discount}', [StaffDiscountController::class, 'update']);
            Route::delete('/{discount}', [StaffDiscountController::class, 'destroy']);
        });
    });

    // Employee login. Any role logs in here, not admins only - see
    // docs/STEP-04-AUTH-PLAN.md §3.
    Route::prefix('admin')->group(function () {
        Route::post('/auth/login', [StaffAuthController::class, 'login']);
    });
});
