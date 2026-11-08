<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('recorded_by_employee_id')->constrained('employees', 'user_id');
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->string('reason', 1000);
            $table->uuid('request_key');
            $table->timestamp('returned_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['order_item_id', 'request_key']);
            $table->index(['order_item_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_returns');
    }
};
