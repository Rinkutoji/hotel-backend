<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

class ReleaseExpiredBookingHolds extends Command
{
    protected $signature = 'bookings:release-expired-holds';

    protected $description = 'Cancel pending_payment bookings past their payment deadline';

    public function handle(BookingService $service): int
    {
        $count = $service->releaseExpiredHolds();

        $this->info("Released {$count} expired booking hold(s).");

        return self::SUCCESS;
    }
}