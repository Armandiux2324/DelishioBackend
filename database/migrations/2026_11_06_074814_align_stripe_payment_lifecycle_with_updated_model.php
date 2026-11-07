<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $accounts = config('services.stripe.legacy_accounts', config('services.stripe.accounts', []));
        if (! is_array($accounts) || count($accounts) !== count(array_unique($accounts))) {
            throw new RuntimeException('Review duplicate legacy Stripe account assignments.');
        }
        foreach ($accounts as $id => $account) {
            if (! is_string($account) || ! preg_match('/\\Aacct_[a-zA-Z0-9]+\\z/', $account) || ! DB::table('restaurants')->where('id', $id)->exists()) {
                throw new RuntimeException('Review legacy Stripe account assignment for restaurant '.$id);
            }
        }
        Schema::table('restaurants', fn (Blueprint $table) => $table->string('stripe_account_id')->nullable()->unique());
        foreach ($accounts as $id => $account) {
            DB::table('restaurants')->where('id', $id)->update(['stripe_account_id' => $account]);
        }
        Schema::table('advance_order_payments', function (Blueprint $table) {
            $table->renameColumn('payment_reference', 'payment_intent_id');
            $table->renameColumn('paid_at', 'captured_at');
            $table->enum('status', ['pending', 'authorized', 'captured', 'voided', 'refund_pending', 'refunded', 'refund_failed'])->default('pending')->index();
            $table->unsignedInteger('refund_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('cancellation_notified_at')->nullable();
        });
        DB::table('advance_order_payments')->update([
            'status' => DB::raw("CASE WHEN refunded_at IS NOT NULL THEN 'refunded' WHEN refund_pending = 1 THEN 'refund_pending' WHEN payment_confirmed = 1 THEN 'captured' ELSE 'pending' END"),
            'updated_at' => now(),
        ]);
        Schema::table('advance_order_payments', function (Blueprint $table) {
            $table->dropIndex(['refund_pending']);
            $table->dropColumn(['payment_confirmed', 'refund_pending']);
            $table->timestamp('updated_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        $accounts = config('services.stripe.legacy_accounts', config('services.stripe.accounts', []));
        foreach (DB::table('restaurants')->whereNotNull('stripe_account_id')->get() as $restaurant) {
            if (($accounts[$restaurant->id] ?? null) !== $restaurant->stripe_account_id) {
                throw new RuntimeException('Export current Stripe account assignments to the legacy map before rollback.');
            }
        }
        if (DB::table('advance_order_payments')->whereIn('status', ['authorized', 'refund_pending', 'refund_failed'])->exists()) {
            throw new RuntimeException('Settle pending Stripe authorizations/refunds before rolling back.');
        }
        Schema::table('advance_order_payments', function (Blueprint $table) {
            $table->boolean('payment_confirmed')->default(false);
            $table->boolean('refund_pending')->default(false)->index();
        });
        DB::table('advance_order_payments')->update([
            'payment_confirmed' => DB::raw("CASE WHEN status = 'captured' THEN 1 ELSE 0 END"),
            'refund_pending' => DB::raw("CASE WHEN status IN ('refund_pending', 'refund_failed') THEN 1 ELSE 0 END"),
        ]);
        Schema::table('advance_order_payments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'refund_attempts', 'last_error', 'authorized_at', 'updated_at', 'cancellation_requested_at', 'cancellation_notified_at']);
            $table->renameColumn('payment_intent_id', 'payment_reference');
            $table->renameColumn('captured_at', 'paid_at');
        });
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn('stripe_account_id');
        });
    }
};
