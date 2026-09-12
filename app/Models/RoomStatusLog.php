<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The room_status_logs table has existed since STEP 03; no model was
 * created until now, since nothing needed to write to it yet - STEP 07
 * is genuinely the first module that does.
 */
class RoomStatusLog extends Model
{
    // Only created_at exists (append-only audit log, see the STEP 03
    // migration) - no updated_at, so Eloquent's automatic timestamp
    // management is disabled. The DB column itself has
    // useCurrent(), so created_at is still populated correctly on
    // insert without Eloquent's help.
    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
