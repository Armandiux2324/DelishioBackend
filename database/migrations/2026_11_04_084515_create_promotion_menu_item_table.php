<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_menu_items', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained();
            $table->foreignId('menu_item_id')->constrained();
            $table->unique(['promotion_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_menu_items');
    }
};
