<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 15 - records the server-computed late-cancellation fee on a
     * confirmed booking cancelled by staff inside the late-cancellation
     * window. Both columns are nullable and additive: null = "no fee
     * applied", and existing bookings are untouched. late_fee_amount reuses
     * the decimal(10,2) money convention from STEP 03; late_fee_applied_at
     * mirrors how cancelled_at / no_show_at record their moments. Decision:
     * docs/STEP-15-IMPLEMENTATION-PLAN.md §6/§8.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('late_fee_amount', 10, 2)->nullable()->after('no_show_at');
            $table->timestamp('late_fee_applied_at')->nullable()->after('late_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['late_fee_amount', 'late_fee_applied_at']);
        });
    }
};