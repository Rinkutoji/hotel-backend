<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * STEP 16 - an immutable billing record for a verified payment. Never
 * edited, deleted, voided or re-issued: the lifecycle is at most an
 * issued_at timestamp (see docs/STEP-16-IMPLEMENTATION-PLAN.md BR-5).
 * No SoftDeletes - same as payments/bookings.
 */
class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'payment_id',
        'total_amount',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function scopeByProperty($query, int $propertyId)
    {
        return $query->whereHas('payment.booking.bookingRooms.room.building', fn ($q) => $q->where('property_id', $propertyId));
    }

    // STEP 16 - D-3 (approved): billing line-item snapshots persisted at
    // issuance so the document never re-reads mutable room/type values.
    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
