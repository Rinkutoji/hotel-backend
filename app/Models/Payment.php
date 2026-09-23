<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * STEP 10 - real Payment module. No SoftDeletes (STEP 03 §6) - the payment
 * lifecycle is status transitions (pending -> receipt_uploaded -> verified
 * -> rejected -> refunded), never row deletion.
 *
 * status / verified_at / verified_by / receipt_path are deliberately NOT in
 * $fillable (same pattern as Booking.status / Room.status): a customer can
 * never mass-assign a payment status, verification fields, amount or path.
 * Every one of those changes goes through PaymentService via direct property
 * assignment inside a transaction. See docs/STEP-10-PAYMENT-PLAN.md §5/§8.
 */
class Payment extends Model
{
    protected $fillable = [
        'method',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeByProperty($query, int $propertyId)
    {
        return $query->whereHas('booking.bookingRooms.room.building', fn ($q) => $q->where('property_id', $propertyId));
    }

    public function propertyId(): ?int
    {
        return $this->booking?->propertyId();
    }

    public function verifiedBy()
    {
        return $this->belongsTo(Employee::class, 'verified_by')->withTrashed();
    }

    // STEP 16 - 1:1 invoice per payment, enforced at the DB level by a
    // UNIQUE constraint on invoices.payment_id (BR-2). Not eager-loaded by
    // any existing controller so existing payment responses stay unchanged.
    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
