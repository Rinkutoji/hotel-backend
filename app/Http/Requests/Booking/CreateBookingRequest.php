<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\ApiFormRequest;
use App\Models\Room;
use Carbon\Carbon;

class CreateBookingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $maxNights = (int) config('booking.max_stay_nights', 30);

        return [
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => [
                'required', 'date', 'after:check_in_date',
                function ($attribute, $value, $fail) use ($maxNights) {
                    $checkIn = Carbon::parse($this->input('check_in_date'));
                    $checkOut = Carbon::parse($value);
                    $nights = (int) $checkIn->startOfDay()->diffInDays($checkOut->startOfDay());
                    if ($nights > $maxNights) {
                        $fail("The stay cannot exceed {$maxNights} nights.");
                    }
                },
            ],
            'rooms' => [
                'required', 'array', 'min:1',
                'max:' . config('booking.max_rooms_per_booking', 5),
            ],
            'rooms.*' => [
                'required', 'integer', 'distinct',
                function ($attribute, $value, $fail) {
                    // Model-backed existence check (not Rule::exists): the
                    // query runs through the Room model, so Room's
                    // SoftDeletes global scope excludes soft-deleted rooms
                    // here too - they fail validation like any missing id.
                    if (! Room::whereKey($value)->exists()) {
                        $fail('The selected room is invalid.');
                    }
                },
            ],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
        ];
    }
}
