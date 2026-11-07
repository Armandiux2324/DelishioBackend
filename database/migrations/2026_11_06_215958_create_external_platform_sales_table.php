<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_platform_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained();
            $table->enum('platform', ['doordash', 'ubereats', 'didi', 'rappi']);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('net_amount', 10, 2);
            $table->foreignId('registered_by_employee_id')->constrained('employees', 'user_id');
            $table->timestamp('sale_date')->useCurrent();
            $table->index(['restaurant_id', 'sale_date', 'id']);
        });
        Schema::create('external_platform_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_sale_id')->constrained('external_platform_sales');
            $table->foreignId('menu_item_id')->nullable()->constrained();
            $table->string('description');
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_platform_sale_items');
        Schema::dropIfExists('external_platform_sales');
    }
};
