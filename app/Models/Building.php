<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Building extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'property_id',
        'name',
        'code',
        'description',
        'floor_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'floor_count' => 'integer',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
