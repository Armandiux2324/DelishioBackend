<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('order_item_id')->nullable()->constrained();
            $table->enum('kind', ['discount', 'courtesy']);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->foreignId('requested_by_employee_id')->constrained('employees', 'user_id');
            $table->foreignId('authorized_by_employee_id')->constrained('employees', 'user_id');
            $table->foreignId('work_shift_id')->constrained();
            $table->timestamp('created_at');
            $table->index(['work_shift_id', 'kind']);
            $table->index(['requested_by_employee_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');
    }
};
