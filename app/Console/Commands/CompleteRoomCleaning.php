<?php

namespace App\Console\Commands;

use App\Services\RoomService;
use Illuminate\Console\Command;

/**
 * STEP 13 - room turnover sweep. Transitions every room that has been in
 * 'cleaning' at least config('booking.cleaning_buffer_minutes') to
 * 'available', writing a system-triggered room_status_logs row
 * (changed_by = null). Mirrors the ReleaseExpiredBookingHolds command
 * (STEP 09): a thin CLI wrapper over a single RoomService method; all
 * transition/business logic stays in RoomService. Idempotent - safe to
 * run repeatedly (or overlap) with no side effects. See
 * docs/STEP-13-IMPLEMENTATION-PLAN.md §6.
 */
class CompleteRoomCleaning extends Command
{
    protected $signature = 'rooms:complete-cleaning';

    protected $description = 'Transition rooms in cleaning past the buffer to available';

    public function handle(RoomService $service): int
    {
        $count = $service->completeReadyCleaning();

        $this->info("Cleaned {$count} room(s) ready to leave cleaning.");

        return self::SUCCESS;
    }
}