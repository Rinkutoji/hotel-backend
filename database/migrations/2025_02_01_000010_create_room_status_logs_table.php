<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')
                ->constrained('rooms')
                ->cascadeOnDelete();
            // Plain strings, not the same native ENUM as rooms.status -
            // an audit table shouldn't need its own migration just because
            // the live status enum gains a value later. See
            // docs/STEP-03-DATABASE-PLAN.md §6. Nullable from_status covers
            // the very first log entry for a room (no prior status).
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // Nullable = system-triggered (scheduled sweep), not a person.
            // Architecture Baseline §13.
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->string('reason')->nullable();
            // created_at only, no updated_at - append-only audit log, a
            // row is never modified after it's written.
            $table->timestamp('created_at')->useCurrent();

            $table->index('room_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_logs');
    }
};
