<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees', 'user_id');
            $table->timestamp('clock_in');
            $table->timestamp('clock_out')->nullable();
            $table->index(['employee_id', 'clock_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_shifts');
    }
};
