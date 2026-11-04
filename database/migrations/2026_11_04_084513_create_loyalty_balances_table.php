<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id');
            $table->foreignId('restaurant_id')->constrained();
            $table->decimal('points_balance', 12, 2)->default(0);
            $table->unique(['customer_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_balances');
    }
};
