<?php

/**
 * STEP 17 - Pricing tiers / discounts: the search/availability surface (D-4
 * approval).
 *
 * Approved rule (docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-4): the search
 * output shows the discounted nightly and stay totals the booking snapshot
 * would charge. The base availability shape is preserved: price_per_night
 * stays the base rate, and no "discount" key exists for a stay that does not
 * qualify.
 */

use Illuminate\Support\Carbon;

test('availability discloses the applied tier and discounted totals for a qualifying stay', function () {
    $type = makeRoomType('AvailDisc');
    $room = makeBookingRoom('AD1', $type->id);
    makeDiscount('Week tier', 2, 10);

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200)->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $room->id);
    $response->assertJsonPath('data.0.price_per_night', '100.00');
    $response->assertJsonPath('data.0.discount.name', 'Week tier');
    $response->assertJsonPath('data.0.discount.min_nights', 2);
    $response->assertJsonPath('data.0.discount.percent_off', '10.00');
    $response->assertJsonPath('data.0.discount.nights', 2);
    $response->assertJsonPath('data.0.discount.price_per_night', '100.00');
    $response->assertJsonPath('data.0.discount.discounted_price_per_night', '90.00');
    $response->assertJsonPath('data.0.discount.total_for_stay', '200.00');
    $response->assertJsonPath('data.0.discount.total_after_discount', '180.00');
    $response->assertJsonPath('data.0.discount.saved_total', '20.00');
});

test('a stay below every tier minimum shows no discount key', function () {
    $type = makeRoomType('AvailPlain');
    $room = makeBookingRoom('AD2', $type->id);
    makeDiscount('Week tier', 2, 10);

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ]);

    $response->assertStatus(200)->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.price_per_night', '100.00');
    expect($response->json('data.0'))->not->toHaveKey('discount');
});

test('multi-room availability carries per-room discount maths', function () {
    $basic = makeRoomType('AvailBasic');
    $luxury = makeRoomType('AvailLuxury');
    $luxury->update(['base_price' => 400]);
    $roomA = makeBookingRoom('AD3a', $basic->id);
    $roomB = makeBookingRoom('AD3b', $luxury->id);
    makeDiscount('Stay tier', 2, 10);

    $response = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$roomA->id, $roomB->id],
    ]);

    $response->assertStatus(200)->assertJsonCount(2, 'data');
    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId[$roomA->id]['discount']['discounted_price_per_night'])->toBe('90.00');
    expect($byId[$roomA->id]['discount']['total_after_discount'])->toBe('180.00');
    expect($byId[$roomB->id]['discount']['discounted_price_per_night'])->toBe('360.00');
    expect($byId[$roomB->id]['discount']['total_after_discount'])->toBe('720.00');
});

test('availability presents the same discounted rate the booking will charge', function () {
    $type = makeRoomType('AvailConsistent');
    $room = makeBookingRoom('AD4', $type->id);
    makeDiscount('Consistent tier', 2, 10);

    $availability = postAvailability([
        'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertStatus(200);

    expect($availability->json('data.0.discount.discounted_price_per_night'))->toBe('90.00');

    $token = loginAsCustomer($this, 'avail-consistent@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/bookings', [
            'check_in_date' => Carbon::today()->addDay()->format('Y-m-d'),
            'check_out_date' => Carbon::today()->addDay(3)->format('Y-m-d'),
            'rooms' => [$room->id],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.total_amount', '180.00')
        // The booking response re-uses the transient discount summary and
        // snapshots the same discounted nightly rate the search advertised.
        ->assertJsonPath('data.discount.saved_total', '20.00')
        ->assertJsonPath('data.discount.total_after_discount', '180.00');
});