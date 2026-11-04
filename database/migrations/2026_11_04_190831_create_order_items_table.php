<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('menu_item_id')->constrained();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->text('modifiers')->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->enum('station', ['none', 'kitchen', 'bar']);
            $table->enum('status', ['pending', 'in_preparation', 'ready'])->default('pending');
            $table->boolean('cancelled')->default(false);
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by_employee_id')->nullable()->constrained('employees', 'user_id');
            $table->foreignId('cancel_authorized_by_employee_id')->nullable()->constrained('employees', 'user_id');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
