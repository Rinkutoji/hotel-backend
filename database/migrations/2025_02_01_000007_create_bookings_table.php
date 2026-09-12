<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->enum('status', [
                'pending_payment', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show',
            ])->default('pending_payment');
            $table->decimal('total_amount', 10, 2)->default(0);
            // Snapshotted once at creation (created_at + config('booking.hold_hours'))
            // - not recomputed from config on read. See Architecture Baseline §4/§9:
            // recomputing would silently shift the deadline on existing bookings if
            // the config value ever changes.
            $table->timestamp('payment_deadline_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            // No soft delete - see docs/STEP-03-DATABASE-PLAN.md §6.
            // Cancellation is a status transition ('cancelled'), not a row deletion.

            $table->index('status');
            $table->index(['check_in_date', 'check_out_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
