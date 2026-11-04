<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained();
            $table->enum('type', ['cash_in', 'cash_out']);
            $table->decimal('amount', 10, 2);
            $table->enum('reason', ['payroll', 'minor_purchase', 'other'])->nullable();
            $table->foreignId('related_employee_id')->nullable()->constrained('employees', 'user_id');
            $table->date('payroll_period_start')->nullable();
            $table->date('payroll_period_end')->nullable();
            $table->foreignId('registered_by_employee_id')->constrained('employees', 'user_id');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
