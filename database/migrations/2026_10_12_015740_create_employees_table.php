<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants');
            $table->string('phone')->nullable();
            $table->enum('role', ['admin', 'waiter', 'chef']);
            $table->enum('station', ['kitchen', 'bar'])->nullable()->comment('aplica solo si role = chef');
            $table->decimal('hourly_rate', 8, 2);
            $table->string('pin', 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
