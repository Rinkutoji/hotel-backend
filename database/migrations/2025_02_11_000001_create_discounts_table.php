<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Stay-length tier threshold - the discount whose min_nights is
            // the largest threshold <= the stay's nights applies (single
            // discount per booking, no stacking). UNIQUE(min_nights) keeps
            // that resolution deterministic.
            $table->unsignedInteger('min_nights');
            // Percentage of the nightly base price taken off each night
            // (0 < x <= 100). Stored decimal:2, applied in integer cents.
            $table->decimal('percent_off', 5, 2);
            $table->timestamps();
            $table->unique('min_nights');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};