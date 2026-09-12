<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('method', ['bank_transfer', 'cash']);
            $table->enum('status', ['pending', 'receipt_uploaded', 'verified', 'rejected', 'refunded'])
                ->default('pending');
            // Private disk only - never a public URL. See Architecture
            // Baseline §14/§18 and config/filesystems.php's 'receipts' disk.
            $table->string('receipt_path')->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            // No soft delete - see docs/STEP-03-DATABASE-PLAN.md §6.
            // 'refunded' is a status, not a deletion.

            // Enforces the approved booking:payment = 1:1 relationship at
            // the DB level, not just by application convention.
            $table->unique('booking_id');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
