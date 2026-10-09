<?php

use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Task 42: permanent end-to-end HTTP tests for the report property/
// building/room-type filters (spec §9). Production behavior shipped in
// T31 (ReportService) + T32 (controller wiring); this file is the
// permanent replacement of the T31 direct-service probe and the T32
// HTTP probe's report half (both deleted pre-commit in those tasks).
// GREEN-first shape: every test must pass on its first run - a failure
// is a REAL FINDING, never patch production code in this task.

beforeEach(function () {
    // Room types created ONCE and captured (T31 lesson: makeRoomType()
    // has NO dedup and the room_types.name unique index was dropped by
    // migration 2025_02_03_000001).
    $this->afwId = makeRoomType('RF-W')->id;
    $this->afmId = makeRoomType('RF-M')->id;

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
    $this->westCustomer = makeBookingCustomer('rf-west@example.com');
    $this->mainCustomer = makeBookingCustomer('rf-main@example.com');
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

    // Booking sources - explicit-property save (fillable-safe).
    $this->westBooking->booking_source = 'website';
    $this->westBooking->save();
    $this->mainBooking->booking_source = 'phone';
    $this->mainBooking->save();
    $this->westOutstanding->booking_source = 'walk_in';
    $this->westOutstanding->save();
    $this->mainOutstanding->booking_source = 'walk_in';
    $this->mainOutstanding->save();

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

    // Room statuses + audit logs (Room.status also NOT fillable).
    $this->westRoomB->status = 'maintenance';
    $this->westRoomB->save();
    $this->mainRoom->status = 'maintenance';
    $this->mainRoom->save();

    $logAt = $this->today->copy()->toDateTimeString();
    foreach ([
        [$this->westRoomA->id, 'cleaning'],
        [$this->westRoomC->id, 'cleaning'],
        [$this->mainRoom->id, 'cleaning'],
        [$this->westRoomB->id, 'maintenance'],
        [$this->mainRoom->id, 'maintenance'],
    ] as [$roomId, $toStatus]) {
        DB::table('room_status_logs')->insert([
            'room_id' => $roomId,
            'from_status' => 'occupied',
            'to_status' => $toStatus,
            'changed_by' => null,
            'reason' => 'probe',
            'created_at' => $logAt,
        ]);
    }

    // Admin token + HTTP helper. Routes are role:admin,manager only.
    $this->token = loginAsEmployee($this, 'rf-admin@example.com', 'admin');
    $this->getAsAdmin = fn (string $uri) => $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson($uri)
        ->assertOk()
        ->json('data');
});

test('the bookings report narrows by property and room type', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $conjunction = ($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to
        .'&property_id='.$this->west->id.'&room_type_id='.$this->afwId);
    $all = ($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to);

    expect($west['total'])->toBe(2)
        ->and($west['by_status']['checked_in'])->toBe(1)
        ->and($west['by_status']['pending_payment'])->toBe(1)
        ->and($conjunction['total'])->toBe(1)
        ->and($all['total'])->toBe(4);
});

test('the revenue report narrows by property', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/reports/revenue?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/reports/revenue?from='.$this->from.'&to='.$this->to);

    expect($west['verified_total'])->toBe('200.00')
        ->and($west['refunded_total'])->toBe('0.00')
        ->and($west['net_total'])->toBe('200.00')
        ->and($all['refunded_total'])->toBe('200.00')
        ->and($all['net_total'])->toBe('0.00');
});

test('the rooms report narrows by property and building', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/reports/rooms?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $westB = ($this->getAsAdmin)('/api/v1/staff/reports/rooms?from='.$this->from.'&to='.$this->to.'&building_id='.$this->westBuilding->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/reports/rooms?from='.$this->from.'&to='.$this->to);

    expect($west['total'])->toBe(3)
        ->and($west['distribution']['maintenance'])->toBe(1)
        ->and($west['occupied_room_nights'])->toBe(3)
        ->and($westB['total'])->toBe(2)
        ->and($all['total'])->toBe(4)
        ->and($all['occupied_room_nights'])->toBe(6);
});

test('the guests and sources reports narrow by property', function () {
    $westGuests = ($this->getAsAdmin)('/api/v1/staff/reports/guests?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $allGuests = ($this->getAsAdmin)('/api/v1/staff/reports/guests?from='.$this->from.'&to='.$this->to);
    $westSources = ($this->getAsAdmin)('/api/v1/staff/reports/sources?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $allSources = ($this->getAsAdmin)('/api/v1/staff/reports/sources?from='.$this->from.'&to='.$this->to);

    expect($westGuests['total'])->toBe(1)
        ->and($westGuests['new'])->toBe(1)
        ->and($westGuests['currently_staying'])->toBe(1)
        ->and($allGuests['total'])->toBe(2)
        ->and($westSources['total'])->toBe(2)
        ->and(collect($westSources['list'])->firstWhere('source', 'website')['count'])->toBe(1)
        ->and($allSources['total'])->toBe(4);
});

test('the check-in and check-out reports narrow by property', function () {
    expect(($this->getAsAdmin)('/api/v1/staff/reports/check-ins?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id)['total'])->toBe(1)
        ->and(($this->getAsAdmin)('/api/v1/staff/reports/check-ins?from='.$this->from.'&to='.$this->to)['total'])->toBe(2)
        ->and(($this->getAsAdmin)('/api/v1/staff/reports/check-outs?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id)['total'])->toBe(1)
        ->and(($this->getAsAdmin)('/api/v1/staff/reports/check-outs?from='.$this->from.'&to='.$this->to)['total'])->toBe(2);
});

test('the payments report narrows by property', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/reports/payments?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/reports/payments?from='.$this->from.'&to='.$this->to);

    expect($west['verified_total'])->toBe('200.00')
        ->and($west['refunded_total'])->toBe('0.00')
        ->and($all['refunded_total'])->toBe('200.00');
});

test('the outstanding report narrows by property', function () {
    $west = ($this->getAsAdmin)('/api/v1/staff/reports/outstanding?property_id='.$this->west->id);
    $all = ($this->getAsAdmin)('/api/v1/staff/reports/outstanding');

    expect($west['count'])->toBe(1)
        ->and($west['amount'])->toBe('200.00')
        ->and($all['count'])->toBe(2);
});

test('the housekeeping and maintenance reports narrow by property and building', function () {
    $westHk = ($this->getAsAdmin)('/api/v1/staff/reports/housekeeping?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $westBHk = ($this->getAsAdmin)('/api/v1/staff/reports/housekeeping?from='.$this->from.'&to='.$this->to.'&building_id='.$this->westBuilding->id);
    $allHk = ($this->getAsAdmin)('/api/v1/staff/reports/housekeeping?from='.$this->from.'&to='.$this->to);
    $westMt = ($this->getAsAdmin)('/api/v1/staff/reports/maintenance?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id);
    $allMt = ($this->getAsAdmin)('/api/v1/staff/reports/maintenance?from='.$this->from.'&to='.$this->to);

    expect($westHk['events'])->toBe(2)
        ->and($westBHk['events'])->toBe(1)
        ->and($allHk['events'])->toBe(3)
        ->and($westMt['events'])->toBe(1)
        ->and($westMt['currently_in_maintenance'])->toBe(1)
        ->and($allMt['events'])->toBe(2)
        ->and($allMt['currently_in_maintenance'])->toBe(2);
});

test('the report CSV export respects the property filter', function () {
    // The export rebuilds through ReportController::dataFor with the
    // exact same filter params; Csv::fromJsonFlat walks every key, so
    // the filtered body genuinely differs from the unfiltered one.
    $filtered = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/staff/reports/bookings/export?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $unfiltered = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/staff/reports/bookings/export?from='.$this->from.'&to='.$this->to)
        ->assertOk();

    expect($filtered->streamedContent())->not->toBe($unfiltered->streamedContent());
});

test('absent filters equal explicit empty filters', function () {
    expect(($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to.'&property_id=&building_id=&room_type_id='))
        ->toBe(($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to));
});

test('the property filter is active, not inert', function () {
    expect(($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to.'&property_id='.$this->west->id))
        ->not->toBe(($this->getAsAdmin)('/api/v1/staff/reports/bookings?from='.$this->from.'&to='.$this->to));
});
