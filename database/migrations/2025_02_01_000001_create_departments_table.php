<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            // No soft delete - Architecture Baseline §4: hard delete
            // acceptable for departments, expected to only ever happen
            // while unused (enforced by employees.department_id's
            // restrictOnDelete below, not by anything in this file).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
