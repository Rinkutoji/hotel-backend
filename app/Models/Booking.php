<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * No SoftDeletes - bookings use status transitions (cancelled, no_show),
 * not row deletion. See docs/STEP-03-DATABASE-PLAN.md §6.
 */
class Booking extends Model
{
    /**
     * status is deliberately NOT here - same pattern as Room.status.
     * Every status change goes through BookingService, never from
     * client input via mass-assignment.
     */
    protected $fillable = [
        'booking_number',
        'customer_id',
        'check_in_date',
        'check_out_date',
        'total_amount',
        'payment_deadline_at',
        'cancelled_at',
        'cancellation_reason',
        'no_show_at',
        'late_fee_amount',
        'late_fee_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'total_amount' => 'decimal:2',
            'payment_deadline_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'late_fee_amount' => 'decimal:2',
            'late_fee_applied_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function rooms()
    {
        return $this->hasManyThrough(Room::class, BookingRoom::class);
    }

    /**
     * STEP 09 - an unpaid hold is "holding" the rooms only while its
     * payment_deadline_at is still in the future. Once it passes, the
     * booking is treated as non-holding (rooms implicitly free) and
     * staff confirm auto-cancels it first.
     */
    public function isExpired(): bool
    {
        return $this->payment_deadline_at !== null && $this->payment_deadline_at->isPast();
    }

    // 1:1 relationship enforced at the DB level by a UNIQUE constraint
    // on payments.booking_id. See docs/STEP-03-DATABASE-PLAN.md §4.
    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
}
