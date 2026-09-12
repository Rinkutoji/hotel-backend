<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingRoom extends Model
{
    protected $fillable = [
        'booking_id',
        'room_id',
        'room_type_id',
        'price_per_night',
        'actual_check_in_at',
        'actual_check_out_at',
    ];

    protected function casts(): array
    {
        return [
            'price_per_night' => 'decimal:2',
            'actual_check_in_at' => 'datetime',
            'actual_check_out_at' => 'datetime',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
