<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * STEP 16 - a persisted line item of an immutable invoice. Snapshots are
 * taken from booking_rooms.price_per_night x nights at issuance (D-3), so
 * later price/room/type changes never alter a historical invoice.
 */
class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'room_number',
        'room_type_name',
        'price_per_night',
        'nights',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'price_per_night' => 'decimal:2',
            'nights' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}