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
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id');
            $table->foreignId('restaurant_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->enum('type', ['earn', 'redeem']);
            $table->decimal('points', 12, 2);
            $table->timestamp('created_at');
            $table->unique(['order_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
