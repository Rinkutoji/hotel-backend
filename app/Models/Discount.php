<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * STEP 17 - a pricing-tier/discount definition consulted ONLY at booking
 * time. The discounted nightly rate is snapshotted into
 * booking_rooms.price_per_night, so this row is pure master data: deleting
 * or editing it never mutates historical bookings (whoever already booked
 * keeps the rate they were quoted).
 */
class Discount extends Model
{
    protected $fillable = [
        'name',
        'min_nights',
        'percent_off',
    ];

    protected function casts(): array
    {
        return [
            'min_nights' => 'integer',
            'percent_off' => 'decimal:2',
        ];
    }
}