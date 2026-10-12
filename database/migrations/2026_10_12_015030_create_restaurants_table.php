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
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('schedule')->nullable();
            $table->enum('currency', ['USD', 'MXN'])->default('MXN');
            $table->enum('status', ['active', 'suspended'])->default('active');

            // Configuraciones del restaurante
            $table->boolean('loyalty_enabled')->default(false);
            $table->decimal('loyalty_factor', 8, 4)->nullable();
            $table->decimal('loyalty_redeem_value', 8, 4)->nullable();
            $table->decimal('discount_limit_per_shift', 8, 2)->nullable();
            $table->decimal('courtesy_percentage', 5, 2)->nullable();
            $table->unsignedInteger('courtesy_daily_limit')->nullable();
            $table->boolean('dual_pricing_enabled')->default(false);
            $table->decimal('dual_pricing_percentage', 5, 2)->nullable();
 
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
