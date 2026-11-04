<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained();
            $table->foreignId('waiter_employee_id')->nullable()->constrained('employees', 'user_id');
            $table->foreignId('customer_id')->nullable()->constrained('customers', 'user_id');
            $table->enum('source', ['pos', 'customer_app']);
            $table->enum('service_type', ['dine_in', 'takeout', 'delivery']);
            $table->string('table_number')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('delivery_fee', 10, 2)->nullable();
            $table->enum('status', ['open', 'sent', 'closed', 'cancelled'])->default('open');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->boolean('dual_pricing_applied')->default(false);
            $table->decimal('tip_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->foreignId('cash_register_id')->nullable()->constrained();
            $table->timestamp('created_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->index(['restaurant_id', 'waiter_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
