<?php

/*
|--------------------------------------------------------------------------
| Booking Business Rules
|--------------------------------------------------------------------------
|
| Numbers here are read via config('booking.xxx') everywhere in the app -
| never env() directly outside this file, per STEP 02's explicit
| requirement, so a business-rule number lives in exactly one place.
|
| Neither value does anything yet - no scheduled job reads them until
| STEP 10 (Booking System) and STEP 13 (Cleaning / Room Status).
|
*/

return [

    // How many hours a "pending_payment" booking holds its room(s) before
    // auto-cancelling. Architecture Baseline §9. Approved value: 2.
    'hold_hours' => (int) env('UNPAID_BOOKING_HOLD_HOURS', 2),

    // How many minutes a room stays "cleaning" after checkout before the
    // scheduled sweep flips it to "available". Architecture Baseline §13.
    'cleaning_buffer_minutes' => (int) env('CLEANING_BUFFER_MINUTES', 15),

    // STEP 09 - booking validation caps (docs/STEP-09-BOOKING-PLAN.md §10).
    'max_stay_nights' => (int) env('BOOKING_MAX_STAY_NIGHTS', 30),

    'max_rooms_per_booking' => (int) env('BOOKING_MAX_ROOMS_PER_BOOKING', 5),

    // STEP 15 - late-cancellation fee (docs/STEP-15-IMPLEMENTATION-PLAN.md §4/§5).
    // How many hours before the start of the check-in day a staff
    // cancellation of a confirmed booking is considered "late".
    'late_cancel_window_hours' => (int) env('LATE_CANCEL_WINDOW_HOURS', 24),

    // How many nights of the booking_rooms.price_per_night snapshots the
    // late fee amounts to (server-computed, integer cents).
    'late_cancel_fee_nights' => (int) env('LATE_CANCEL_FEE_NIGHTS', 1),

];
