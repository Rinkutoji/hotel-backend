<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    /**
     * status IS fillable here (unlike Room.status) because admin CRUD
     * manages active/inactive per spec.
     */
    protected $fillable = [
        'name', 'code', 'description', 'address', 'city', 'province_state',
        'country', 'postal_code', 'latitude', 'longitude', 'phone', 'email',
        'website', 'check_in_time', 'check_out_time', 'timezone', 'currency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function buildings()
    {
        return $this->hasMany(Building::class);
    }

    public function rooms()
    {
        return $this->hasManyThrough(Room::class, Building::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
