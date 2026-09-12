<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks (not registered yet)
|--------------------------------------------------------------------------
|
| The unpaid-booking hold-expiry sweep (Architecture Baseline §9) lands
| here in STEP 09 as an hourly Artisan registration of the
| bookings:release-expired-holds command. The cleaning-buffer sweep
| (Architecture Baseline §13, STEP 13 - Cleaning / Room Status) still
| belongs later. Local caveat documented: needs schedule:work/cron on
| XAMPP.
*/

Schedule::command('bookings:release-expired-holds')->hourly();

/*
|--------------------------------------------------------------------------
| STEP 13 - cleaning-buffer sweep
|--------------------------------------------------------------------------
|
| The housekeeping turnover sweep (Architecture Baseline §13): rooms that
| have been 'cleaning' for >= config('booking.cleaning_buffer_minutes')
| (15) are transitioned back to 'available' by the rooms:complete-cleaning
| command. Every-minute cadence keeps the boundary tight (a room crossing
| the buffer is flipped within ~1 minute); the sweep is idempotent, so a
| throttled/missed tick only delays turnover, never corrupts state. Same
| local caveat as the hold sweep: needs schedule:work (dev) or cron
| (prod) to actually run on XAMPP. See
| docs/STEP-13-IMPLEMENTATION-PLAN.md §6/§23.
*/
Schedule::command('rooms:complete-cleaning')->everyMinute();

/*
|--------------------------------------------------------------------------
| STEP 14 - no-show sweep
|--------------------------------------------------------------------------
|
| The no-show automation (Architecture Baseline §9's deferred no_show enum):
| confirmed bookings whose check-in date has fully passed without a check-in
| are marked 'no_show' and their reserved rooms released back to 'available'
| by the bookings:mark-no-show command. Hourly cadence (same as the sibling
| hold-expiry sweep): a no-show crossing midnight is detected within about an
| hour, and the sweep is idempotent so catch-up after a missed tick is
| automatic. Same local caveat as the other sweeps: needs schedule:work (dev)
| or cron (prod) to actually run on XAMPP. See
| docs/STEP-14-PLAN.md §4/§14.
*/
Schedule::command('bookings:mark-no-show')->hourly();
