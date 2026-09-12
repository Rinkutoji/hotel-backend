<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

/**
 * STEP 14 - no-show sweep. Marks confirmed bookings whose check-in date has
 * fully passed (without a check-in) as 'no_show' and releases their reserved
 * rooms back to 'available'. Mirrors the ReleaseExpiredBookingHolds command
 * (STEP 09) and CompleteRoomCleaning (STEP 13): a thin CLI wrapper over a
 * single BookingService method; all transition/business logic lives in the
 * service. Idempotent - safe to run repeatedly. See
 * docs/STEP-14-PLAN.md §4.
 */
class MarkNoShowBookings extends Command
{
    protected $signature = 'bookings:mark-no-show';

    protected $description = 'Mark confirmed bookings whose check-in date has passed as no-show';

    public function handle(BookingService $service): int
    {
        $count = $service->markNoShowBookings();

        $this->info("Marked {$count} booking(s) as no-show.");

        return self::SUCCESS;
    }
}