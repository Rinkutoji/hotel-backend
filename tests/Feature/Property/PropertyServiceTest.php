<?php

use App\Models\Booking;
use App\Models\Building;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Services\PropertyService;

// Task 23: direct service-level proof of PropertyService (listing filters,
// CRUD/soft-delete, detail aggregation) — the HTTP-level CRUD tests land in
// Phase 8 once the routes exist (plan Task 41).

beforeEach(function () {
    $this->service = new PropertyService;
    $this->west = makeProperty('WEST', 'West Hotel');
    $this->westBuilding = makeBuilding('WEST-B', $this->west->id);

    $this->makeWestRoom = function (string $number, Building $building, string $typeName): Room {
        return Room::create([
            'room_number' => $number,
            'room_type_id' => makeRoomType($typeName)->id,
            'building_id' => $building->id,
            'floor' => 1,
        ]);
    };
});

test('it paginates properties', function () {
    $page = $this->service->paginate([]);

    expect($page->total())->toBeGreaterThanOrEqual(2);
    expect($page->items())->not->toBeEmpty();
    expect($page->perPage())->toBe(15);
});

test('it searches properties by name', function () {
    $page = $this->service->paginate(['search' => 'West']);

    expect($page->total())->toBe(1);
    expect($page->items()[0]->name)->toBe('West Hotel');
});

test('it searches properties by code', function () {
    $page = $this->service->paginate(['search' => 'MAIN']);

    expect($page->total())->toBe(1);
    expect($page->items()[0]->code)->toBe('MAIN');
});

test('it filters by city province and country', function () {
    $this->west->update(['city' => 'Siem Reap', 'province_state' => 'SR', 'country' => 'KH']);

    $byCity = $this->service->paginate(['city' => 'Siem Reap']);
    expect($byCity->total())->toBe(1);
    expect($byCity->items()[0]->code)->toBe('WEST');

    $byCountry = $this->service->paginate(['country' => 'KH']);
    expect($byCountry->total())->toBe(1);
    expect($byCountry->items()[0]->code)->toBe('WEST');

    $byProvince = $this->service->paginate(['province_state' => 'SR']);
    expect($byProvince->total())->toBe(1);
    expect($byProvince->items()[0]->code)->toBe('WEST');

    expect($this->service->paginate(['country' => 'US'])->total())->toBe(0);
});

test('it filters by status', function () {
    $this->west->update(['status' => 'inactive']);

    $inactive = $this->service->paginate(['status' => 'inactive']);
    expect($inactive->total())->toBe(1);
    expect($inactive->items()[0]->code)->toBe('WEST');

    $active = $this->service->paginate(['status' => 'active']);
    expect($active->total())->toBe(1);
    expect($active->items()[0]->code)->toBe('MAIN');
});

test('it only sorts by allowlisted fields', function () {
    $default = $this->service->paginate(['sort' => 'id']);
    expect($default->items()[0]->name)->toBe('Main Hotel');

    $byCreated = $this->service->paginate(['sort' => 'created_at', 'direction' => 'desc']);
    expect($byCreated->total())->toBe(2);
    expect($byCreated->items())->toHaveCount(2);
});

test('it clamps per_page and paginates', function () {
    makeProperty('EAST', 'East Hotel');

    $page = $this->service->paginate(['per_page' => 2]);
    expect($page->items())->toHaveCount(2);
    expect($page->total())->toBe(3);
    expect($page->perPage())->toBe(2);
    expect($page->lastPage())->toBe(2);

    expect($this->service->paginate(['per_page' => 999])->perPage())->toBe(50);
});

test('it includes building and room counts on list items', function () {
    ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T8');

    $page = $this->service->paginate([]);

    $west = $page->getCollection()->firstWhere('code', 'WEST');
    expect($west->buildings_count)->toBe(1);
    expect($west->rooms_count)->toBe(1);

    $main = $page->getCollection()->firstWhere('code', 'MAIN');
    expect($main->buildings_count)->toBeGreaterThanOrEqual(1);
    expect($main->rooms_count)->toBe(0);
});

test('it creates a property', function () {
    $created = $this->service->create(['name' => 'East Hotel', 'code' => 'EAST23']);

    expect(Property::find($created->id))->not->toBeNull();
    expect($created->status)->toBe('active');
    expect($created->name)->toBe('East Hotel');
    expect($created->code)->toBe('EAST23');
});

test('it updates only the provided fields', function () {
    $this->west->update(['city' => 'SR']);

    $updated = $this->service->update($this->west, ['city' => 'Phnom Penh']);

    expect($updated->city)->toBe('Phnom Penh');
    expect($updated->name)->toBe('West Hotel');
    expect($updated->code)->toBe('WEST');
    expect($updated->status)->toBe('active');
    expect($this->west->fresh()->city)->toBe('Phnom Penh');
});

test('it soft deletes a property', function () {
    $this->service->delete($this->west);

    expect(Property::find($this->west->id))->toBeNull();

    $trashed = Property::withTrashed()->find($this->west->id);
    expect($trashed)->not->toBeNull();
    expect($trashed->deleted_at)->not->toBeNull();
});

test('it returns every property field in the detail payload', function () {
    $detail = $this->service->detail($this->west);

    expect($detail)->toHaveKeys([
        'id', 'name', 'code', 'description', 'status', 'check_in_time',
        'check_out_time', 'timezone', 'currency', 'address', 'city',
        'province_state', 'country', 'postal_code', 'phone', 'email',
        'website', 'latitude', 'longitude', 'buildings', 'summary',
    ]);
    expect($detail['name'])->toBe('West Hotel');
    expect($detail['status'])->toBe('active');
    expect($detail['latitude'])->toBe($this->west->latitude);
});

test('it includes every building in the detail payload', function () {
    $b2 = makeBuilding('WEST-B2', $this->west->id);

    $detail = $this->service->detail($this->west);

    expect($detail['buildings'])->toHaveCount(2);

    foreach ($detail['buildings'] as $building) {
        expect($building)->toHaveKeys(['id', 'name', 'code', 'status', 'floor_count', 'room_count', 'occupied', 'occupancy_pct']);
    }

    $ids = collect($detail['buildings'])->pluck('id')->all();
    expect($ids)->toContain($this->westBuilding->id);
    expect($ids)->toContain($b2->id);
});

test('it reports correct room counts per building', function () {
    $b2 = makeBuilding('WEST-B2', $this->west->id);

    ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');
    ($this->makeWestRoom)('B1-R2', $this->westBuilding, 'P23-T2');
    ($this->makeWestRoom)('B2-R1', $b2, 'P23-T3');

    $detail = $this->service->detail($this->west);
    $buildings = collect($detail['buildings'])->keyBy('code');

    expect($buildings['WEST-B']['room_count'])->toBe(2);
    expect($buildings['WEST-B2']['room_count'])->toBe(1);
});

test('it reports correct occupied counts', function () {
    $b2 = makeBuilding('WEST-B2', $this->west->id);

    ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');
    $r2 = ($this->makeWestRoom)('B1-R2', $this->westBuilding, 'P23-T2');
    ($this->makeWestRoom)('B2-R1', $b2, 'P23-T3');

    $r2->status = 'occupied';
    $r2->save();

    $detail = $this->service->detail($this->west);
    $buildings = collect($detail['buildings'])->keyBy('code');

    expect($buildings['WEST-B']['occupied'])->toBe(1);
    expect($buildings['WEST-B2']['occupied'])->toBe(0);
    expect($detail['summary']['occupied'])->toBe(1);
});

test('it derives occupancy percentages from room counts', function () {
    $b2 = makeBuilding('WEST-B2', $this->west->id);

    ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');
    $r2 = ($this->makeWestRoom)('B1-R2', $this->westBuilding, 'P23-T2');

    $r2->status = 'occupied';
    $r2->save();

    $detail = $this->service->detail($this->west);
    $buildings = collect($detail['buildings'])->keyBy('code');

    expect($buildings['WEST-B']['occupancy_pct'])->toBe(50.0);
    expect($buildings['WEST-B2']['occupancy_pct'])->toBe(0.0);
    expect($detail['summary']['occupancy_pct'])->toBe(50.0);

    $empty = makeProperty('EMPTY', 'Empty Hotel');
    $emptyDetail = $this->service->detail($empty);

    expect($emptyDetail['summary']['room_count'])->toBe(0);
    expect($emptyDetail['summary']['occupied'])->toBe(0);
    expect($emptyDetail['summary']['occupancy_pct'])->toBe(0.0);
});

test('it sums verified revenue through rooms and excludes other payments', function () {
    $westRoom = ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');

    $westBooking = makeHoldingBooking(
        makeBookingCustomer('p23-a@example.com'),
        $westRoom,
        now()->addDay()->toDateString(),
        now()->addDays(3)->toDateString(),
        'confirmed'
    );
    makePaymentFor($westBooking, 'verified');

    $mainRoom = makeBookingRoom('M1-R1', makeRoomType('P23-Main')->id);
    $mainBooking = makeHoldingBooking(
        makeBookingCustomer('p23-b@example.com'),
        $mainRoom,
        now()->addDay()->toDateString(),
        now()->addDays(3)->toDateString(),
        'confirmed'
    );
    makePaymentFor($mainBooking, 'verified');

    $pendingBooking = makeHoldingBooking(
        makeBookingCustomer('p23-c@example.com'),
        $westRoom,
        now()->addDays(5)->toDateString(),
        now()->addDays(7)->toDateString(),
        'pending_payment'
    );
    makePaymentFor($pendingBooking, 'pending');

    $refundedBooking = makeHoldingBooking(
        makeBookingCustomer('p23-d@example.com'),
        $westRoom,
        now()->addDays(9)->toDateString(),
        now()->addDays(11)->toDateString(),
        'confirmed'
    );
    makePaymentFor($refundedBooking, 'refunded');

    $detail = $this->service->detail($this->west);

    expect($detail['summary']['verified_revenue_total'])->toBe('200.00');
});

test('it counts active bookings by holding statuses', function () {
    $westRoom = ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');

    makeHoldingBooking(makeBookingCustomer('p23-e@example.com'), $westRoom, now()->addDay()->toDateString(), now()->addDays(3)->toDateString(), 'confirmed');
    makeHoldingBooking(makeBookingCustomer('p23-f@example.com'), $westRoom, now()->addDays(4)->toDateString(), now()->addDays(6)->toDateString(), 'checked_in');
    makeHoldingBooking(makeBookingCustomer('p23-g@example.com'), $westRoom, now()->addDays(8)->toDateString(), now()->addDays(10)->toDateString(), 'cancelled');
    makeHoldingBooking(makeBookingCustomer('p23-h@example.com'), $westRoom, now()->addDays(12)->toDateString(), now()->addDays(14)->toDateString(), 'no_show');

    $mainRoom = makeBookingRoom('M1-R2', makeRoomType('P23-Main2')->id);
    makeHoldingBooking(makeBookingCustomer('p23-i@example.com'), $mainRoom, now()->addDay()->toDateString(), now()->addDays(3)->toDateString(), 'confirmed');

    $detail = $this->service->detail($this->west);

    expect($detail['summary']['active_bookings'])->toBe(2);
});

test('it excludes soft-deleted operational records but keeps historical revenue', function () {
    $r1 = ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');

    $booking = makeHoldingBooking(
        makeBookingCustomer('p23-j@example.com'),
        $r1,
        now()->addDay()->toDateString(),
        now()->addDays(3)->toDateString(),
        'confirmed'
    );
    makePaymentFor($booking, 'verified');

    $this->westBuilding->delete();

    $detail = $this->service->detail($this->west);

    $ids = collect($detail['buildings'])->pluck('id')->all();
    expect($ids)->not->toContain($this->westBuilding->id);
    expect($detail['summary']['room_count'])->toBe(0);
    expect($detail['summary']['occupied'])->toBe(0);
    expect($detail['summary']['verified_revenue_total'])->toBe('200.00');
    expect($detail['summary']['active_bookings'])->toBe(0);
});

test('it leaves unrelated records untouched on delete', function () {
    $room = ($this->makeWestRoom)('B1-R1', $this->westBuilding, 'P23-T1');
    $booking = makeHoldingBooking(makeBookingCustomer('p23-k@example.com'), $room, now()->addDay()->toDateString(), now()->addDays(3)->toDateString(), 'confirmed');
    makePaymentFor($booking, 'verified');

    $buildings = Building::count();
    $rooms = Room::count();
    $bookings = Booking::count();
    $payments = Payment::count();

    $this->service->delete($this->west);

    expect(Building::count())->toBe($buildings);
    expect(Room::count())->toBe($rooms);
    expect(Booking::count())->toBe($bookings);
    expect(Payment::count())->toBe($payments);

    $page = $this->service->paginate([]);
    expect($page->total())->toBe(1);
    expect($page->items()[0]->code)->toBe('MAIN');
});
