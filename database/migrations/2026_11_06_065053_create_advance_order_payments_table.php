<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained();
            $table->boolean('payment_confirmed')->default(false);
            $table->string('payment_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->string('stripe_account_id');
            $table->enum('currency', ['USD', 'MXN']);
            $table->decimal('amount', 10, 2);
            $table->decimal('points_to_earn', 12, 2)->default(0);
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->boolean('refund_pending')->default(false)->index();
            $table->string('refund_reference')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_order_payments');
    }
};
