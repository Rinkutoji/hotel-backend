<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    /**
     * status, status_changed_at, and cleaning_started_at are
     * deliberately NOT here. Every FormRequest already excludes status
     * from its rules, but keeping it out of $fillable too is a second,
     * independent layer - even a future bug that accidentally
     * mass-assigned raw request data couldn't set it through this
     * model. Status is only ever set via explicit property assignment
     * inside RoomService. See docs/STEP-07-ROOM-PLAN.md §5.
     */
    protected $fillable = [
        'room_number',
        'room_type_id',
        'building_id',
        'floor',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'status_changed_at' => 'datetime',
            'cleaning_started_at' => 'datetime',
        ];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    // Explicit keys: rooms holds building_id (FK to buildings.id), not the
    // through table - default inference would use the wrong columns.
    public function property()
    {
        return $this->hasOneThrough(Property::class, Building::class, 'id', 'id', 'building_id', 'property_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(RoomStatusLog::class);
    }

    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class);
    }
}
