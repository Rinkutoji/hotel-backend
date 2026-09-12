<?php

use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Services\RoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

/**
 * STEP 13 - room cleaning / turnover automation.
 *
 * Verified axes (docs/STEP-13-IMPLEMENTATION-PLAN.md §21):
 * - checkout records 'cleaning' + cleaning_started_at
 * - sweep: before/after the 15-min buffer, exact boundary, idempotency,
 *   multiple rooms, maintenance interrupt, soft-delete, null timestamps,
 *   audit rows
 * - manual early-completion via the existing status/available endpoint and
 *   its RBAC; customer 403 / guest 401
 * - scheduler registration
 */

// Helper: create a room directly in 'cleaning' with explicit timestamps so
// tests can pin the buffer reliably. cleaning_started_at is not in
// $fillable - set explicitly, same pattern as status.
function makeCleaningRoom(string $number, int $roomTypeId, ?Carbon $cleaningStartedAt = null, ?Carbon $statusChangedAt = null): Room
{
    $room = makeBookingRoom($number, $roomTypeId);
    $room->status = 'cleaning';
    $room->status_changed_at = $statusChangedAt ?? ($cleaningStartedAt ?? now());
    $room->cleaning_started_at = $cleaningStartedAt;
    $room->save();

    return $room;
}

// --- Checkout stamps the cleaning timer ---

test('checkout places the room into cleaning and records cleaning_started_at', function () {
    $customer = makeBookingCustomer('turnover-checkout-guest@example.com');
    $employee = \App\Models\Employee::create([
        'name' => 'Admin', 'email' => 'turnover-checkout-staff@example.com', 'password' => 'Password123!', 'role' => 'admin',
    ]);
    $type = makeRoomType('TurnoverCheckout');
    $room = makeBookingRoom('TO1', $type->id);

    \Laravel\Sanctum\Sanctum::actingAs($customer);
    $create = $this->postJson('/api/v1/customer/bookings', [
        'check_in_date' => Carbon::today()->format('Y-m-d'),
        'check_out_date' => Carbon::today()->addDay(2)->format('Y-m-d'),
        'rooms' => [$room->id],
    ])->assertStatus(201);

    $bookingId = $create->json('data.id');

    \Laravel\Sanctum\Sanctum::actingAs($employee);
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/confirm")->assertStatus(200);
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/check-in")->assertStatus(200);
    $this->postJson("/api/v1/staff/bookings/{$bookingId}/check-out")->assertStatus(200);

    $room = $room->fresh();
    expect($room->status)->toBe('cleaning');
    expect($room->cleaning_started_at)->not->toBeNull();
    // Stamped in the same transition as status_changed_at - clock-independent
    // window, no Carbon::setTestNow needed for this assertion.
    expect($room->cleaning_started_at->diffInSeconds($room->status_changed_at))->toBeLessThan(1);
});

// --- Sweep: before the buffer ---

test('a cleaning room stays cleaning before the 15 minute buffer elapses', function () {
    $type = makeRoomType('TurnoverBefore');
    $room = makeCleaningRoom('TO2', $type->id, now()->subMinutes(5));

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 0')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('cleaning');
    expect(RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')->count())->toBe(0);
});

// --- Sweep: after the buffer ---

test('a cleaning room becomes available after the 15 minute buffer elapses', function () {
    $type = makeRoomType('TurnoverAfter');
    $room = makeCleaningRoom('TO3', $type->id, now()->subMinutes(16));

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 1')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('available');
});

// --- Sweep: exact boundary (frozen clock for determinism) ---

test('a room at exactly the 15 minute boundary is eligible for turnover', function () {
    $this->freezeTime();
    try {
        $type = makeRoomType('TurnoverBoundary');
        $room = makeCleaningRoom('TO4', $type->id, now()->subMinutes(15));

        $this->artisan('rooms:complete-cleaning')
            ->expectsOutputToContain('Cleaned 1')
            ->assertExitCode(0);

        expect($room->fresh()->status)->toBe('available');
    } finally {
        $this->travelBack();
    }
});

test('a room one second before the 15 minute boundary stays cleaning', function () {
    $this->freezeTime();
    try {
        $type = makeRoomType('TurnoverBoundaryBefore');
        $room = makeCleaningRoom('TO5', $type->id, now()->subMinutes(15)->addSecond());

        $this->artisan('rooms:complete-cleaning')
            ->expectsOutputToContain('Cleaned 0')
            ->assertExitCode(0);

        expect($room->fresh()->status)->toBe('cleaning');
    } finally {
        $this->travelBack();
    }
});

// --- Idempotency ---

test('repeated sweep runs are idempotent', function () {
    $type = makeRoomType('TurnoverIdem');
    $room = makeCleaningRoom('TO6', $type->id, now()->subMinutes(30));

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 1')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('available');

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 0')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('available');
    expect(RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')->count())->toBe(1);
});

// --- Multiple rooms, mixed ages ---

test('the sweep processes multiple eligible cleaning rooms and skips fresh ones', function () {
    $typeA = makeRoomType('TurnoverMultiA');
    $typeB = makeRoomType('TurnoverMultiB');
    $eligibleA = makeCleaningRoom('TO7a', $typeA->id, now()->subMinutes(30));
    $eligibleB = makeCleaningRoom('TO7b', $typeA->id, now()->subHours(2));
    $freshA = makeCleaningRoom('TO7c', $typeB->id, now()->subMinutes(5));
    $freshB = makeCleaningRoom('TO7d', $typeB->id, now()->subMinutes(2));

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 2')
        ->assertExitCode(0);

    expect($eligibleA->fresh()->status)->toBe('available');
    expect($eligibleB->fresh()->status)->toBe('available');
    expect($freshA->fresh()->status)->toBe('cleaning');
    expect($freshB->fresh()->status)->toBe('cleaning');
});

// --- Maintenance interruption ---

test('maintenance can interrupt cleaning and the sweep never un-interrupts it', function () {
    $type = makeRoomType('TurnoverMaint');
    $room = makeCleaningRoom('TO8', $type->id, now()->subMinutes(30));
    $token = loginAsEmployee($this, 'turnover-maint@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/maintenance", ['reason' => 'Leaking pipe'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'maintenance');

    // Even though its cleaning buffer has long elapsed, a maintenance room is
    // never a sweep candidate.
    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 0')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('maintenance');
});

// --- Manual early completion (existing endpoint + RBAC) ---

test('staff can manually early-complete a cleaning room before the buffer elapses', function () {
    $type = makeRoomType('TurnoverManual');
    $room = makeCleaningRoom('TO9', $type->id, now());
    $token = loginAsEmployee($this, 'turnover-manual@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/available", ['reason' => 'Housekeeping done early'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'available');

    $log = RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')->first();
    expect($log)->not->toBeNull();
    $actor = \App\Models\Employee::where('email', 'turnover-manual@example.com')->first();
    expect($log->changed_by)->toBe($actor->id);
    expect($log->reason)->toBe('Housekeeping done early');
});

test('admin, manager and receptionist can all early-complete a cleaning room', function () {
    foreach (['admin', 'manager', 'receptionist'] as $role) {
        $type = makeRoomType("TurnoverRole{$role}");
        $room = makeCleaningRoom("TO10-{$role}", $type->id, now());
        $token = loginAsEmployee($this, "turnover-{$role}@example.com", $role);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/staff/rooms/{$room->id}/status/available")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'available');
    }
});

test('a guest is rejected with 401 on the status endpoint', function () {
    $type = makeRoomType('TurnoverGuest');
    $room = makeCleaningRoom('TO11g', $type->id, now());

    $this->postJson("/api/v1/staff/rooms/{$room->id}/status/available")->assertStatus(401);
});

test('a customer cannot early-complete a room (403)', function () {
    $type = makeRoomType('TurnoverBlocked');
    $room = makeCleaningRoom('TO11', $type->id, now());

    $token = loginAsCustomer($this, 'turnover-customer@example.com');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/available")
        ->assertStatus(403);
});

// --- Invalid states / live rooms never made available ---

test('a room that is not in an allowed source state cannot be made available via the endpoint', function () {
    $type = makeRoomType('TurnoverInvalid');
    $room = makeBookingRoom('TO12', $type->id);
    $room->status = 'occupied';
    $room->status_changed_at = now();
    $room->save();
    $token = loginAsEmployee($this, 'turnover-invalid@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$room->id}/status/available")
        ->assertStatus(409);

    expect($room->fresh()->status)->toBe('occupied');
});

test('the sweep never makes reserved, occupied, maintenance or available rooms available', function () {
    $type = makeRoomType('TurnoverLiveGuard');
    $base = ['room_type_id' => $type->id];

    $available = Room::create(['room_number' => 'TO13a', ...$base, 'floor' => 1]);
    $reserved = Room::create(['room_number' => 'TO13b', ...$base, 'floor' => 1]);
    $reserved->status = 'reserved';
    $reserved->status_changed_at = now()->subHours(2);
    $reserved->save();
    $occupied = Room::create(['room_number' => 'TO13c', ...$base, 'floor' => 1]);
    $occupied->status = 'occupied';
    $occupied->status_changed_at = now()->subHours(2);
    $occupied->save();
    $maintenance = Room::create(['room_number' => 'TO13d', ...$base, 'floor' => 1]);
    $maintenance->status = 'maintenance';
    $maintenance->status_changed_at = now()->subHours(2);
    $maintenance->save();
    $availableOld = Room::create(['room_number' => 'TO13e', ...$base, 'floor' => 1]);
    $availableOld->status_changed_at = now()->subHours(2);
    $availableOld->save();

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 0')
        ->assertExitCode(0);

    expect($available->fresh()->status)->toBe('available');
    expect($reserved->fresh()->status)->toBe('reserved');
    expect($occupied->fresh()->status)->toBe('occupied');
    expect($maintenance->fresh()->status)->toBe('maintenance');
    expect($availableOld->fresh()->status)->toBe('available');
});

// --- Null timestamp fallback / safe skip ---

test('a cleaning room with null cleaning_started_at falls back to status_changed_at', function () {
    $type = makeRoomType('TurnoverFallback');
    $room = makeCleaningRoom('TO14', $type->id, null, now()->subMinutes(30));

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 1')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('available');
});

test('a cleaning room with both timestamps null is safely skipped, never auto-flipped', function () {
    $type = makeRoomType('TurnoverBothNull');
    $room = makeCleaningRoom('TO15', $type->id, null, null);

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 0')
        ->assertExitCode(0);

    expect($room->fresh()->status)->toBe('cleaning');
    expect(RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')->count())->toBe(0);
});

// --- Soft-deleted rooms are ignored ---

test('a soft-deleted cleaning room is ignored by the sweep and returns 404 on the endpoint', function () {
    $type = makeRoomType('TurnoverDeleted');
    $room = makeCleaningRoom('TO16', $type->id, now()->subMinutes(30));
    $roomId = $room->id;
    $room->delete();

    $this->artisan('rooms:complete-cleaning')
        ->expectsOutputToContain('Cleaned 0')
        ->assertExitCode(0);

    $room = Room::withTrashed()->find($roomId);
    expect($room->status)->toBe('cleaning');

    $token = loginAsEmployee($this, 'turnover-deleted@example.com', 'admin');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/staff/rooms/{$roomId}/status/available")
        ->assertStatus(404);
});

// --- Audit log (system-triggered) ---

test('the sweep writes a system-triggered room_status_logs row', function () {
    $type = makeRoomType('TurnoverAudit');
    $room = makeCleaningRoom('TO17', $type->id, now()->subMinutes(30));

    $this->artisan('rooms:complete-cleaning')->assertExitCode(0);

    $log = RoomStatusLog::where('room_id', $room->id)
        ->where('to_status', 'available')->first();
    expect($log)->not->toBeNull();
    expect($log->from_status)->toBe('cleaning');
    expect($log->to_status)->toBe('available');
    expect($log->changed_by)->toBeNull();
    expect($log->reason)->toBe('Cleaning period complete');
});

// --- Transition matrix / scheduler registration ---

test('cleaning to available is the only new cleaning edge added', function () {
    expect(RoomService::isTransitionValid('cleaning', 'available'))->toBeTrue();
    expect(RoomService::isTransitionValid('cleaning', 'maintenance'))->toBeTrue();
    expect(RoomService::isTransitionValid('cleaning', 'reserved'))->toBeFalse();
    expect(RoomService::isTransitionValid('cleaning', 'occupied'))->toBeFalse();
    // Live-room edges are unchanged - a reserved/occupied/maintenance/available
    // room can still never jump to 'available' via the matrix.
    expect(RoomService::isTransitionValid('occupied', 'available'))->toBeFalse();
    expect(RoomService::isTransitionValid('reserved', 'available'))->toBeTrue();
    expect(RoomService::isTransitionValid('maintenance', 'available'))->toBeTrue();
    expect(RoomService::isTransitionValid('available', 'available'))->toBeFalse();
});

test('the rooms:complete-cleaning command is scheduled every minute', function () {
    $events = Schedule::events();

    $turnover = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'rooms:complete-cleaning'));

    expect($turnover)->not->toBeNull();
    expect($turnover->expression)->toBe('* * * * *');
});