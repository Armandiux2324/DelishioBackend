<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained();
            $table->foreignId('opened_by_employee_id')->constrained('employees', 'user_id');
            $table->decimal('opening_amount', 10, 2);
            $table->timestamp('opened_at');
            $table->foreignId('closed_by_employee_id')->nullable()->constrained('employees', 'user_id');
            $table->decimal('counted_cash', 10, 2)->nullable();
            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->index(['restaurant_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
