<?php

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Feature tests get a freshly migrated database per test. Unit tests
// deliberately don't - if a test needs the DB, it belongs in Feature/.
uses(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared test helpers
|--------------------------------------------------------------------------
|
| $testCase is passed explicitly (not resolved via Pest's test() helper)
| so these work unambiguously regardless of which file calls them.
| Originally written inline in STEP 05's AuthorizationTest.php - moved
| here in STEP 06 once a second file needed the same helper (redeclaring
| a global function in two files is a fatal error).
|
*/

function loginAsEmployee(TestCase $testCase, string $email, string $role): string
{
    $payload = [
        'name' => ucfirst($role), 'email' => $email, 'password' => 'Password123!', 'role' => $role,
    ];

    // Location feature (Task 20): production receptionists must carry a
    // property assignment (Task 16 validation). Helper receptionists get
    // MAIN so property-scoped policies keep existing allow-assertions green.
    if ($role === 'receptionist') {
        $payload['property_id'] = makeProperty()->id;
    }

    Employee::create($payload);

    return $testCase->postJson('/api/v1/admin/auth/login', [
        'email' => $email, 'password' => 'Password123!',
    ])->json('data.token');
}

function loginAsCustomer(TestCase $testCase, string $email): string
{
    Customer::create([
        'name' => 'Test Customer', 'email' => $email, 'password' => 'Password123!',
    ]);

    return $testCase->postJson('/api/v1/customer/auth/login', [
        'email' => $email, 'password' => 'Password123!',
    ])->json('data.token');
}

// STEP 07: rooms always need a parent room type - shared across every
// file in tests/Feature/Room/.
function makeRoomType(string $name = 'Standard'): RoomType
{
    return RoomType::create(['name' => $name, 'base_price' => 100, 'capacity_adults' => 2]);
}

// STEP 12: rooms.building_id is NOT NULL (migration 2025_03_01_000003, which
// also seeds a MAIN property + MAIN building) - helpers shared across the
// whole suite. firstOrCreate finds the seeded rows; never create duplicate
// 'MAIN' rows. No static cache - firstOrCreate against the fresh test DB
// only (RefreshDatabase wipes rows between tests but static state would
// survive the process).
function makeProperty(string $code = 'MAIN', string $name = 'Main Hotel'): Property
{
    return Property::firstOrCreate(
        ['code' => $code],
        ['name' => $name, 'status' => 'active'],
    );
}

function makeBuilding(?string $code = null, ?int $propertyId = null): Building
{
    $propertyId ??= makeProperty()->id;

    return Building::firstOrCreate(
        ['property_id' => $propertyId, 'code' => $code ?? 'MAIN'],
        ['name' => 'Main Building', 'status' => 'active'],
    );
}

// STEP 09: booking helpers - shared across every file in tests/Feature/Booking/.

function makeBookingCustomer(string $email = 'guest@example.com'): Customer
{
    return Customer::create([
        'name' => 'Guest', 'email' => $email, 'password' => 'Password123!',
    ]);
}

function makeBookingRoom(string $number, int $roomTypeId, int $floor = 1): Room
{
    return Room::create([
        'room_number' => $number, 'room_type_id' => $roomTypeId, 'floor' => $floor,
        'building_id' => makeBuilding()->id,
    ]);
}

function makeHoldingBooking(Customer $customer, Room $room, string $checkIn, string $checkOut, string $status = 'confirmed', bool $expired = false): Booking
{
    $booking = Booking::create([
        'booking_number' => 'BK-TEST'.strtoupper(uniqid()),
        'customer_id' => $customer->id,
        'check_in_date' => $checkIn,
        'check_out_date' => $checkOut,
        'total_amount' => 200,
        'payment_deadline_at' => $expired ? now()->subMinute() : now()->addHours(2),
    ]);

    // status is deliberately NOT in $fillable - set explicitly, same
    // pattern as Room.status in RoomService.
    $booking->status = $status;
    $booking->save();

    BookingRoom::create([
        'booking_id' => $booking->id,
        'room_id' => $room->id,
        'room_type_id' => $room->room_type_id,
        'price_per_night' => 100,
    ]);

    return $booking;
}

function postAvailability(array $payload): TestResponse
{
    return test()->postJson('/api/v1/public/availability', $payload);
}

// STEP 10: payment helpers - shared across every file in tests/Feature/Payment/.

/**
 * A fake upload whose content genuinely matches its extension, so the
 * mimes:jpeg,png,pdf allowlist (content-sniffed, see validator source)
 * accepts it. $padding lets tests build a real-PDF header big enough to
 * trip the size cap without failing the mime check.
 */
function fakeReceiptFile(string $name, string $padding = ''): UploadedFile
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $header = match ($ext) {
        'png', 'jpg', 'jpeg' => base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ),
        default => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n",
    };

    return UploadedFile::fake()->createWithContent($name, $header.$padding);
}

/**
 * Insert a payment row directly with the requested state/status. Mirrors
 * makeHoldingBooking: status is outside $fillable, set explicitly.
 */
function makePaymentFor(
    Booking $booking,
    string $status = 'pending',
    string $method = 'bank_transfer',
    ?string $receiptPath = null,
    ?string $notes = null
): Payment {
    $payment = new Payment(['method' => $method]);
    $payment->booking_id = $booking->id;
    $payment->amount = $booking->total_amount;
    $payment->status = $status;
    $payment->receipt_path = $receiptPath;
    $payment->notes = $notes;
    $payment->save();

    return $payment;
}

// STEP 15: a confirmed booking with explicit booking_rooms.price_per_night
// SNAPSHOTS and its rooms already 'reserved' (mirrors what
// confirmWithinTransaction produces). $rooms expects [Room, string|float].
function makeConfirmedBookingWithRooms(
    Customer $customer,
    string $checkIn,
    string $checkOut,
    array $rooms,
    string $totalAmount = '200.00'
): Booking {
    $booking = Booking::create([
        'booking_number' => 'BK-TEST'.strtoupper(uniqid()),
        'customer_id' => $customer->id,
        'check_in_date' => $checkIn,
        'check_out_date' => $checkOut,
        'total_amount' => $totalAmount,
        'payment_deadline_at' => now()->addHours(2),
    ]);

    $booking->status = 'confirmed';
    $booking->save();

    foreach ($rooms as [$room, $pricePerNight]) {
        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'price_per_night' => $pricePerNight,
        ]);

        $room->status = 'reserved';
        $room->status_changed_at = now();
        $room->save();
    }

    return $booking;
}

// STEP 17: a discount/pricing-tier row. Shared across every file in
// tests/Feature/Discount/.
function makeDiscount(string $name = 'Tier', int $minNights = 2, float $percentOff = 10): Discount
{
    return Discount::create([
        'name' => $name,
        'min_nights' => $minNights,
        'percent_off' => $percentOff,
    ]);
}
