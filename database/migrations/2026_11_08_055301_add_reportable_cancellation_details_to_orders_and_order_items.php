<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancel_authorized_by_employee_id')->nullable()->constrained('employees', 'user_id');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_authorized_by_employee_id');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
