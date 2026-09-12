<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AvailabilityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'rooms' => [
                'required', 'array', 'min:1',
                'max:' . config('booking.max_rooms_per_booking', 5),
            ],
            'rooms.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('rooms', 'id'),
            ],
        ];
    }
}
