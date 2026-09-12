<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'base_price',
        'capacity_adults',
        'capacity_children',
        'amenities',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'capacity_adults' => 'integer',
            'capacity_children' => 'integer',
            // Eloquent handles JSON encode/decode transparently - a
            // plain PHP array in, a plain PHP array out.
            'amenities' => 'array',
        ];
    }

    // STEP 07: rooms() relationship, referenced by RoomTypeService's
    // deletion guard (a room type with active rooms can't be
    // soft-deleted). Default query already excludes soft-deleted rooms
    // via Room's own SoftDeletes global scope.
    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    // STEP 08: room_images has no soft-delete column (approved as hard
    // delete only) and cascadeOnDelete on its FK - but that cascade only
    // fires on a real DELETE, which never happens to a RoomType in this
    // app (only ->delete(), which soft-deletes). Soft-deleting a room
    // type leaves its images untouched, still validly attached - see
    // docs/STEP-08-ROOM-IMAGE-PLAN.md §5.
    public function images()
    {
        return $this->hasMany(RoomImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
