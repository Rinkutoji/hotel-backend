<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 14 - records when the no-show sweep marked a booking. Nullable and
     * additive: bookings.status already carries the 'no_show' enum value since
     * STEP 03, but no column captured the moment. Symmetric with
     * cancelled_at/cancellation_reason (the cancellation analogue). Decision:
     * docs/STEP-14-PLAN.md §3 (approved: minimal migration).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('no_show_at')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('no_show_at');
        });
    }
};