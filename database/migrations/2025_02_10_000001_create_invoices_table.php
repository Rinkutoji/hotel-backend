<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('payment_id')
                ->constrained('payments')
                ->cascadeOnDelete();
            $table->decimal('total_amount', 10, 2);
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();
            $table->unique('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};