<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained();
            $table->string('name');
            $table->string('condition_type');
            $table->decimal('condition_value', 10, 2)->nullable();
            $table->string('discount_type');
            $table->decimal('discount_value', 10, 2);
            $table->string('days_of_week')->nullable();
            $table->time('time_start')->nullable();
            $table->time('time_end')->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->boolean('active')->default(true);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
