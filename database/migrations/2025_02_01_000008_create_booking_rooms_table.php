<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->foreignId('room_id')
                ->constrained('rooms')
                ->restrictOnDelete();
            // Snapshot of the room's type at booking time - kept as a real,
            // enforced FK (not a loose denormalized value) since room_types
            // uses soft-delete, so this reference never dangles. See
            // docs/STEP-03-DATABASE-PLAN.md §6.
            $table->foreignId('room_type_id')
                ->constrained('room_types')
                ->restrictOnDelete();
            // Snapshot of the actual per-night price at booking time.
            $table->decimal('price_per_night', 10, 2);
            $table->timestamp('actual_check_in_at')->nullable();
            $table->timestamp('actual_check_out_at')->nullable();
            $table->timestamps();

            // Prevents the same room being listed twice within one booking.
            // NOT full double-booking prevention across different bookings -
            // that stays application-level (MySQL has no date-range
            // exclusion constraint). See Architecture Baseline §4/§11.
            $table->unique(['booking_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rooms');
    }
};
