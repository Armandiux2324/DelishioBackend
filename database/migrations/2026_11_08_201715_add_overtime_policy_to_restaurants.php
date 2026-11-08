<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('overtime_weekly_hours', 5, 2)->default('40.00');
            $table->decimal('overtime_multiplier', 4, 2)->default('1.50');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['overtime_weekly_hours', 'overtime_multiplier']);
        });
    }
};
