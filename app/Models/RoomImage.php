<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RoomImage extends Model
{
    // No SoftDeletes - room_images was approved as hard delete only.

    protected $fillable = [
        'room_type_id',
        'path',
        'sort_order',
    ];

    /**
     * The raw storage path is never exposed in API responses - "do not
     * expose filesystem paths directly." Only the computed public URL
     * (below) is - clients get something usable, not internal server
     * file layout.
     */
    protected $hidden = [
        'path',
    ];

    protected $appends = [
        'url',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::disk('public')->url($this->path),
        );
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
