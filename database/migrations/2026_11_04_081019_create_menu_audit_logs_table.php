<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained();
            $table->foreignId('employee_id')->constrained('employees', 'user_id');
            $table->enum('action', ['create', 'edit', 'price_change', 'activate', 'deactivate', 'delete']);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_audit_logs');
    }
};
