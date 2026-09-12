<?php

/**
 * STEP 17 - Pricing tiers / discounts: the admin surface (D-5 approval).
 *
 * Approved rules (docs/STEP-17-IMPLEMENTATION-PLAN.md §B D-2/D-5/D-7):
 * - The `discounts` table is the single additive definition store; a tier
 *   is {name, min_nights, percent_off}.
 * - Managing tiers is ADMIN+MANAGER only; receptionist and any customer is
 *   403 (route role:admin,manager + DiscountPolicy second layer).
 * - percent_off ∈ (0,100], a server-side percentage-of-nightly; min_nights
 *   is a unique positive integer - one tier per threshold keeps the
 *   single-best-tier resolution deterministic.
 * - Money is never accepted here: tiers only carry the three approved
 *   definition fields, and a tier definition is master data (consulted at
 *   booking time only), so editing/deleting it never mutates bookings.
 */

use App\Models\Discount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('an empty discounts table lists no tiers for an admin', function () {
    $token = loginAsEmployee($this, 'disc-admin-list@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/discounts')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(0, 'data');
});

test('an admin can create, view, update, and delete a pricing tier', function () {
    $token = loginAsEmployee($this, 'disc-admin-crud@example.com', 'admin');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/discounts', [
            'name' => 'Week stay',
            'min_nights' => 3,
            'percent_off' => 15,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Week stay')
        ->assertJsonPath('data.min_nights', 3)
        ->assertJsonPath('data.percent_off', '15.00');

    $id = $create->json('data.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/staff/discounts/{$id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/discounts/{$id}", [
            'name' => 'Week stay PLUS',
            'min_nights' => 4,
            'percent_off' => 20,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Week stay PLUS')
        ->assertJsonPath('data.min_nights', 4)
        ->assertJsonPath('data.percent_off', '20.00');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/discounts/{$id}")
        ->assertStatus(200);

    expect(Discount::find($id))->toBeNull();
});

test('a manager has the same tier-management authority as an admin', function () {
    $token = loginAsEmployee($this, 'disc-manager@example.com', 'manager');

    $create = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/discounts', [
            'name' => 'Manager tier',
            'min_nights' => 2,
            'percent_off' => 10,
        ])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/discounts')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/staff/discounts/{$create->json('data.id')}", [
            'name' => 'Manager tier updated',
            'min_nights' => 2,
            'percent_off' => 12,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.percent_off', '12.00');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/staff/discounts/{$create->json('data.id')}")
        ->assertStatus(200);
});

test('receptionist staff are forbidden from every discount endpoint', function () {
    $token = loginAsEmployee($this, 'disc-receptionist@example.com', 'receptionist');
    $discount = makeDiscount('Reception tier', 2, 10);

    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->getJson('/api/v1/staff/discounts')->assertStatus(403);
    $this->withHeaders($auth)->postJson('/api/v1/staff/discounts', [
        'name' => 'Sneak', 'min_nights' => 1, 'percent_off' => 10,
    ])->assertStatus(403);
    $this->withHeaders($auth)->getJson("/api/v1/staff/discounts/{$discount->id}")->assertStatus(403);
    $this->withHeaders($auth)->putJson("/api/v1/staff/discounts/{$discount->id}", [
        'name' => 'Sneak', 'min_nights' => 1, 'percent_off' => 10,
    ])->assertStatus(403);
    $this->withHeaders($auth)->deleteJson("/api/v1/staff/discounts/{$discount->id}")->assertStatus(403);
});

test('a customer token cannot reach the tier admin surface', function () {
    $token = loginAsCustomer($this, 'disc-customer@example.com');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/staff/discounts')
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/discounts', [
            'name' => 'Sneak', 'min_nights' => 1, 'percent_off' => 10,
        ])
        ->assertStatus(403);
});

test('an unauthenticated caller gets 401 from the tier admin surface', function () {
    $this->getJson('/api/v1/staff/discounts')->assertStatus(401);
});

test('store validates name, min_nights, and percent_off bounds', function () {
    $token = loginAsEmployee($this, 'disc-validation@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->postJson('/api/v1/staff/discounts', [
        'min_nights' => 1, 'percent_off' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors('name');

    $this->withHeaders($auth)->postJson('/api/v1/staff/discounts', [
        'name' => 'Zero nights', 'min_nights' => 0, 'percent_off' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors('min_nights');

    $this->withHeaders($auth)->postJson('/api/v1/staff/discounts', [
        'name' => 'Zero percent', 'min_nights' => 1, 'percent_off' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors('percent_off');

    $this->withHeaders($auth)->postJson('/api/v1/staff/discounts', [
        'name' => 'Over 100', 'min_nights' => 1, 'percent_off' => 100.01,
    ])->assertStatus(422)->assertJsonValidationErrors('percent_off');

    $this->withHeaders($auth)->postJson('/api/v1/staff/discounts', [
        'name' => 'Full refund tier', 'min_nights' => 1, 'percent_off' => 100,
    ])->assertStatus(201)->assertJsonPath('data.percent_off', '100.00');
});

test('min_nights is unique - a second tier for the same threshold is rejected', function () {
    makeDiscount('Existing', 2, 10);
    $token = loginAsEmployee($this, 'disc-unique@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/discounts', [
            'name' => 'Duplicate', 'min_nights' => 2, 'percent_off' => 20,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('min_nights');
});

test('update cannot move a tier onto another tier min_nights but keeps its own', function () {
    makeDiscount('Existing', 2, 10);
    $other = makeDiscount('Other', 3, 15);
    $token = loginAsEmployee($this, 'disc-update-unique@example.com', 'admin');
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->putJson("/api/v1/staff/discounts/{$other->id}", [
        'name' => 'Other', 'min_nights' => 2, 'percent_off' => 15,
    ])->assertStatus(422)->assertJsonValidationErrors('min_nights');

    $this->withHeaders($auth)->putJson("/api/v1/staff/discounts/{$other->id}", [
        'name' => 'Other', 'min_nights' => 3, 'percent_off' => 15,
    ])->assertStatus(200)->assertJsonPath('data.min_nights', 3);
});

test('client-supplied money fields on a tier definition are silently ignored', function () {
    $token = loginAsEmployee($this, 'disc-inject@example.com', 'admin');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/staff/discounts', [
            'name' => 'Clean tier',
            'min_nights' => 4,
            'percent_off' => 10,
            'total_amount' => 1.99,
            'saved_total' => 999999,
            'discount_id' => 5,
            'deleted_at' => now()->toDateTimeString(),
        ])
        ->assertStatus(201);

    $tier = Discount::firstOrFail();
    expect($tier->name)->toBe('Clean tier');
    expect($tier->min_nights)->toBe(4);
    expect($tier->percent_off)->toBe('10.00');
    expect($tier->total_amount ?? null)->toBeNull();
    expect($tier->saved_total ?? null)->toBeNull();
});

test('the discounts table is additive with the approved shape', function () {
    expect(Schema::hasTable('discounts'))->toBeTrue();
    expect(Schema::hasColumns('discounts', ['name', 'min_nights', 'percent_off', 'created_at', 'updated_at']))->toBeTrue();
    expect(Schema::hasColumn('discounts', 'deleted_at'))->toBeFalse();
});

test('min_nights has a database-level unique constraint', function () {
    Discount::create(['name' => 'A', 'min_nights' => 5, 'percent_off' => 10]);

    expect(fn () => DB::table('discounts')->insert([
        'name' => 'B', 'min_nights' => 5, 'percent_off' => 20,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});