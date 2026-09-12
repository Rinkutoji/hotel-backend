<?php

namespace App\Services;

use App\Models\Discount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * STEP 17 - Pricing tiers / discounts.
 *
 * Approved rules (docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-2/D-3/D-6):
 * - Percentage-of-nightly, applied at booking time in server-side integer
 *   cents BEFORE the booking_rooms.price_per_night snapshot, so the
 *   snapshot stores the final charged nightly rate.
 * - Single discount per booking (no stacking): the tier with the largest
 *   min_nights <= the stay's nights applies.
 * - Downstream invariants unchanged: payments.amount stays bookings.total_
 *   amount (the discounted total) and the STEP 15/16 readers keep reading
 *   the discounted snapshot, so no engine needed a change.
 */
class DiscountService
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    /**
     * The single discount that applies to a stay of $nights nights, or null
     * when the stay is shorter than every tier's minimum.
     */
    public function resolveForNights(int $nights): ?Discount
    {
        return Discount::query()
            ->where('min_nights', '<=', $nights)
            ->orderByDesc('min_nights')
            ->first();
    }

    /**
     * The discount, in integer cents, for one night at the given base price.
     */
    public function discountCentsFor(Discount $discount, int $pricePerNightCents): int
    {
        return max(0, (int) round($pricePerNightCents * (float) $discount->percent_off / 100));
    }

    /**
     * The final charged nightly rate (integer cents) after the discount -
     * never below zero.
     */
    public function discountedNightlyCents(Discount $discount, int $pricePerNightCents): int
    {
        return max(0, $pricePerNightCents - $this->discountCentsFor($discount, $pricePerNightCents));
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Discount::query();

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $query->orderBy('min_nights')->paginate($perPage);
    }

    public function create(array $data): Discount
    {
        return Discount::create($data);
    }

    public function update(Discount $discount, array $data): Discount
    {
        $discount->update($data);

        return $discount->fresh();
    }

    public function delete(Discount $discount): void
    {
        // Hard delete of a definition is safe: booking_rooms.price_per_night
        // is a snapshot, so no historical booking references this row.
        $discount->delete();
    }
}