<?php

use App\Models\Room;
use Carbon\Carbon;

// Task 42: permanent end-to-end HTTP tests for the analytics + dashboard
// property/building/room-type filters (spec §9). Production behavior
// shipped in T30 (AnalyticsService) + T32 (controller wiring); this file
// is the permanent replacement of the T30 direct-service probe and the
// T32 HTTP probe's analytics half (both deleted pre-commit in those
// tasks). GREEN-first shape: every test must pass on its first run - a
// failure is a REAL FINDING, never patch production code in this task.

beforeEach(function () {
    // Room types created ONCE and captured (T31 lesson: makeRoomType()
    // has NO dedup and the room_types.name unique index was dropped by
    // migration 2025_02_03_000001).
    $this->afwId = makeRoomType('AF-W')->id;
    $this->afmId = makeRoomType('AF-M')->id;

    // Window computed BEFORE any date arithmetic (T30 lesson: Carbon
    // mutates in place).
    $this->today = Carbon::today();
    $this->from = $this->today->copy()->subDays(2)->toDateString();
    $this->to = $this->today->copy()->addDays(2)->toDateString();

    // WEST property chain.
    $this->west = makeProperty('WEST', 'West Hotel');
    $this->westBuilding = makeBuilding('WEST-B', $this->west->id);
    $this->westBuilding2 = makeBuilding('WEST-B2', $this->west->id);
    $this->westRoomA = Room::create([
        'room_number' => 'W101',
        'room_type_id' => $this->afwId,
        'floor' => 1,
        'building_id' => $this->westBuilding->id,
    ]);
    $this->westRoomB = Room::create([
        'room_number' => 'W102',
        'room_type_id' => $this->afmId,
        'floor' => 1,
        'building_id' => $this->westBuilding->id,
    ]);
    $this->westRoomC = Room::create([
        'room_number' => 'W201',
        'room_type_id' => $this->afmId,
        'floor' => 1,
        'building_id' => $this->westBuilding2->id,
    ]);

    // MAIN room (makeBookingRoom attaches to the seeded MAIN building).
    $this->mainRoom = makeBookingRoom('M101', $this->afmId);

    // One checked-in stay per property overlapping the window.
    $this->westCustomer = makeBookingCustomer('af-west@example.com');
    $this->mainCustomer = makeBookingCustomer('af-main@example.com');
    $this->westBooking = makeHoldingBooking(
        $this->westCustomer,
        $this->westRoomA,
        $this->today->copy()->subDay()->toDateString(),
        $this->today->copy()->addDays(2)->toDateString(),
        'checked_in'
    );
    $this->mainBooking = makeHoldingBooking(
        $this->mainCustomer,
        $this->mainRoom,
        $this->today->copy()->subDay()->toDateString(),
        $this->today->copy()->addDays(2)->toDateString(),
        'checked_in'
    );

    // One expired outstanding hold per property (no payment at all).
    $this->westOutstanding = makeHoldingBooking(
        $this->westCustomer,
        $this->westRoomB,
        $this->today->copy()->addDays(10)->toDateString(),
        $this->today->copy()->addDays(11)->toDateString(),
        'pending_payment',
        true
    );
    $this->mainOutstanding = makeHoldingBooking(
        $this->mainCustomer,
        $this->mainRoom,
        $this->today->copy()->addDays(10)->toDateString(),
        $this->today->copy()->addDays(11)->toDateString(),
        'pending_payment',
        true
    );

    // Payments: WEST verified, MAIN refunded, timestamps inside the
    // window. Status/verified_at/refunded_at are NOT in $fillable -
    // explicit assignment + save() (RevenueReportTest pattern). One
    // payment per booking - UNIQUE(payments.booking_id).
    $westPayment = makePaymentFor($this->westBooking);
    $westPayment->status = 'verified';
    $westPayment->verified_at = $this->today->copy();
    $westPayment->save();
    $mainPayment = makePaymentFor($this->mainBooking);
    $mainPayment->status = 'refunded';
    $mainPayment->refunded_at = $this->today->copy();
    $mainPayment->save();

    // Room statuses (Room.status also NOT fillable).
    $this->westRoomB->status = 'maintenance';
    $this->westRoomB->save();
    $this->mainRoom->status = 'maintenance';
    $this->mainRoom->save();

    // Admin token + HTTP helper. Routes are role:admin,manager only.
    $this->token = loginAsEmployee($this, 'af-admin@example.com', 'admin');
    $this->getAsAdmin = fn (string $uri) => $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson($uri)
        ->assertOk()
        ->json('data');
});

test('the analytics summary narrows by property', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to);

    expect($west['summary']['total_bookings'])->toBe(2)
        ->and($west['summary']['rooms_total'])->toBe(3)
        ->and($west['summary']['verified_revenue'])->toBe('200.00')
        ->and($west['summary']['refunds'])->toBe('0.00')
        ->and(collect($west['booking_status'])->firstWhere('status', 'checked_in')['count'])->toBe(1)
        ->and(collect($west['booking_status'])->firstWhere('status', 'pending_payment')['count'])->toBe(1)
        ->and(collect($west['booking_trend'])->sum(fn ($p) => $p['bookings']))->toBe(2)
        ->and(collect($west['payment_status'])->firstWhere('status', 'verified')['count'])->toBe(1)
        ->and($all['summary']['total_bookings'])->toBe(4)
        ->and($all['summary']['rooms_total'])->toBe(4)
        ->and(collect($all['booking_trend'])->sum(fn ($p) => $p['bookings']))->toBe(4)
        ->and(collect($all['payment_status'])->firstWhere('status', 'refunded')['count'])->toBe(1);
});

test('analytics room occupancy is property-scoped', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to);

    expect(collect($west['room_occupancy'])->firstWhere('status', 'maintenance')['count'])->toBe(1)
        ->and(collect($west['room_occupancy'])->firstWhere('status', 'available')['count'])->toBe(2)
        ->and(collect($all['room_occupancy'])->firstWhere('status', 'maintenance')['count'])->toBe(2)
        ->and(collect($all['room_occupancy'])->firstWhere('status', 'available')['count'])->toBe(2);
});

test('analytics room types are property-scoped', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to);

    // room_types is zero-filled for EVERY type (T30 lesson) - keyBy the
    // name and assert per-key values, never pluck().
    $westByType = collect($west['room_types'])->keyBy('name');
    $allByType = collect($all['room_types'])->keyBy('name');

    expect($westByType['AF-W']['bookings'])->toBe(1)
        ->and($westByType['AF-W']['revenue'])->toBe('200.00')
        ->and($westByType['AF-M']['bookings'])->toBe(1)
        ->and($westByType['AF-M']['revenue'])->toBe('0.00')
        ->and($allByType['AF-M']['bookings'])->toBe(3)
        ->and($allByType['AF-W']['bookings'])->toBe(1);
});

test('analytics room_type filter conjunctions with property', function () {
    $result = ($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to
        .'&property_id='.$this->west->id.'&room_type_id='.$this->afwId);

    expect($result['summary']['total_bookings'])->toBe(1);
});

test('the dashboard KPIs narrow by property', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/dashboard?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/dashboard?from='.$this->from.'&to='.$this->to);

    expect($west['inventory']['total'])->toBe(3)
        ->and($west['guests']['total'])->toBe(1)
        ->and($west['guests']['currently_staying'])->toBe(1)
        ->and($west['todays']['outstanding']['count'])->toBe(1)
        ->and($all['inventory']['total'])->toBe(4)
        ->and($all['guests']['total'])->toBe(2)
        ->and($all['todays']['outstanding']['count'])->toBe(2);
});

test('dashboard guest stats under a property filter keep global first-booking semantics', function () {
    // T30 review minor, plan-prescribed semantics locked in: "new"
    // compares the GLOBAL MIN(created_at) per customer, so a customer
    // whose first-ever booking was at ANOTHER property inside the window
    // still counts as "new" under the property filter (the filter narrows
    // the booking rows, not the first_at derivation).
    $cross = makeBookingCustomer('af-cross@example.com');
    $firstAtMain = makeHoldingBooking(
        $cross,
        $this->mainRoom,
        $this->today->copy()->subDay()->toDateString(),
        $this->today->copy()->addDay()->toDateString(),
        'confirmed'
    );
    $firstAtMain->created_at = $this->today->copy()->subDay();
    $firstAtMain->save();

    $laterAtWest = makeHoldingBooking(
        $cross,
        $this->westRoomA,
        $this->today->copy()->addDay()->toDateString(),
        $this->today->copy()->addDays(2)->toDateString(),
        'confirmed'
    );
    $laterAtWest->created_at = $this->today->copy();
    $laterAtWest->save();

    // A returning guest: prior booking BEFORE the window, WEST booking
    // inside the window.
    $returning = makeBookingCustomer('af-returning@example.com');
    $prior = makeHoldingBooking(
        $returning,
        $this->mainRoom,
        $this->today->copy()->subDays(10)->toDateString(),
        $this->today->copy()->subDays(9)->toDateString(),
        'checked_out'
    );
    $prior->created_at = $this->today->copy()->subDays(10);
    $prior->save();

    $inWindow = makeHoldingBooking(
        $returning,
        $this->westRoomA,
        $this->today->copy()->subDay()->toDateString(),
        $this->today->copy()->addDay()->toDateString(),
        'confirmed'
    );
    $inWindow->created_at = $this->today->copy();
    $inWindow->save();

    $west = ($this->getAsAdmin)('/api/v1/staff/dashboard?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/dashboard?from='.$this->from.'&to='.$this->to);

    expect($west['guests']['new'])->toBe(2)
        ->and($west['guests']['returning'])->toBe(1)
        ->and($west['guests']['total'])->toBe(3)
        ->and($all['guests']['total'])->toBe(4);
});

test('the dashboard CSV export serves the filtered payload', function () {
    // T43: the user-approved flatten() fix made every row share the
    // section/metric/value shape, so the CSV body can now prove filter
    // threading (T32 amendment - the pre-fix bodies were byte-identical
    // because Csv::fromAssocRows derives its header from the first row).
    $filtered = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/staff/dashboard/export?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $unfiltered = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/staff/dashboard/export?from='.$this->from.'&to='.$this->to)
        ->assertOk();

    expect($filtered->streamedContent())
        ->toContain('section,metric,value')
        ->toContain('inventory,total,3')
        ->toContain('guests,total,1')
        ->not->toBe($unfiltered->streamedContent());
});

test('absent filters equal explicit empty filters', function () {
    expect(($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to.'&property_id='))
        ->toBe(($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to));
});

test('the property filter is active, not inert', function () {
    expect(($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id))
        ->not->toBe(($this->getAsAdmin)('/api/v1/staff/analytics?from='.$this->from.'&to='.$this->to));
});
