<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate legacy totals before changing schema or money; ambiguous historical data requires review.
        foreach ($this->plans() as $plan) {
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('loyalty_redeemed_amount', 10, 2)->default(0);
            $table->decimal('surcharge_total', 10, 2)->default(0);
        });
        Schema::table('payments', fn (Blueprint $table) => $table->decimal('surcharge_amount', 10, 2)->default(0));
        Schema::table('loyalty_transactions', fn (Blueprint $table) => $table->decimal('base_amount', 12, 2)->nullable());
        foreach ($this->plans() as $plan) {
            DB::table('orders')->where('id', $plan['id'])->update([
                'discount_total' => (string) $plan['discount'], 'loyalty_redeemed_amount' => (string) $plan['redeemed'],
                'surcharge_total' => (string) $plan['surcharge'],
            ]);
            if ($plan['card'] !== null) {
                DB::table('payments')->where('id', $plan['card']->id)->update([
                    'amount' => (string) BigDecimal::of((string) $plan['card']->amount)->minus($plan['surcharge']),
                    'surcharge_amount' => (string) $plan['surcharge'],
                ]);
            }
            DB::table('loyalty_transactions')->where('order_id', $plan['id'])->where('type', 'earn')->update(['base_amount' => (string) $plan['base']]);
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('dual_pricing_applied'));
    }

    private function plans(): Generator
    {
        foreach (DB::table('orders')->orderBy('id')->lazyById(100) as $order) {
            $discount = BigDecimal::of((string) $order->discount_total);
            $redeemed = BigDecimal::of('0.00');
            if (DB::table('loyalty_transactions')->where('order_id', $order->id)->where('type', 'redeem')->exists()) {
                $lines = [];
                $gross = BigDecimal::of('0.00');
                foreach (DB::table('order_items')->where('order_id', $order->id)->where('cancelled', false)->orderBy('id')->get() as $item) {
                    $lines[$item->id] = BigDecimal::of((string) $item->unit_price)->multipliedBy($item->quantity);
                    $gross = $gross->plus($lines[$item->id]);
                }
                foreach (DB::table('order_discounts')->where('order_id', $order->id)->orderBy('id')->get() as $record) {
                    if ($record->amount === null) {
                        throw new RuntimeException('Review legacy discount for order '.$order->id);
                    }
                    $amount = BigDecimal::of((string) $record->amount);
                    if ($record->order_item_id !== null) {
                        if (isset($lines[$record->order_item_id])) {
                            $line = $lines[$record->order_item_id];
                            $lines[$record->order_item_id] = $amount->isGreaterThan($line) ? BigDecimal::of('0.00') : $line->minus($amount);
                        }
                    } else {
                        $this->allocate($lines, $amount);
                    }
                }
                $net = array_reduce($lines, fn ($sum, $line) => $sum->plus($line), BigDecimal::of('0.00'));
                $authorizedDiscount = $gross->minus($net);
                $redeemed = $discount->minus($authorizedDiscount);
                $discount = $authorizedDiscount;
            }
            $base = BigDecimal::of((string) $order->subtotal)->minus($discount)->minus($redeemed);
            $surcharge = BigDecimal::of((string) $order->total)->minus($base)->minus((string) $order->tax_total)
                ->minus((string) ($order->delivery_fee ?? '0.00'))->minus((string) $order->tip_amount);
            $payments = DB::table('payments')->where('order_id', $order->id)->get();
            if ($order->status === 'cancelled' && $payments->isEmpty()) {
                $surcharge = BigDecimal::of('0.00');
            }
            $cards = $payments->where('method', 'card')->values();
            if ($redeemed->isNegative() || $base->isNegative() || $surcharge->isNegative()
                || ($surcharge->isPositive() && $payments->isNotEmpty() && $cards->count() !== 1)
                || ($cards->count() === 1 && $surcharge->isGreaterThan((string) $cards[0]->amount))) {
                throw new RuntimeException('Review legacy financial breakdown for order '.$order->id);
            }
            yield ['id' => $order->id, 'discount' => $discount, 'redeemed' => $redeemed, 'surcharge' => $surcharge,
                'base' => $base, 'card' => $cards->count() === 1 ? $cards[0] : null];
        }
    }

    private function allocate(array &$lines, BigDecimal $amount): void
    {
        $total = array_reduce($lines, fn ($sum, $line) => $sum->plus($line), BigDecimal::of('0.00'));
        if ($total->isZero()) {
            return;
        }
        if ($amount->isGreaterThan($total)) {
            $amount = $total;
        }
        $left = $amount;
        $parts = [];
        foreach ($lines as $id => $line) {
            $parts[$id] = $amount->multipliedBy($line)->dividedBy($total, 2, RoundingMode::Down);
            $left = $left->minus($parts[$id]);
        }
        foreach ($lines as $id => $line) {
            if ($left->isPositive() && $line->minus($parts[$id])->isPositive()) {
                $parts[$id] = $parts[$id]->plus('0.01');
                $left = $left->minus('0.01');
            }
            $lines[$id] = $line->minus($parts[$id]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->boolean('dual_pricing_applied')->default(false));
        foreach (DB::table('orders')->orderBy('id')->lazyById(100) as $order) {
            DB::table('orders')->where('id', $order->id)->update([
                'discount_total' => (string) BigDecimal::of((string) $order->discount_total)->plus((string) $order->loyalty_redeemed_amount),
                'dual_pricing_applied' => BigDecimal::of((string) $order->surcharge_total)->isPositive(),
            ]);
        }
        foreach (DB::table('payments')->orderBy('id')->lazyById(100) as $payment) {
            DB::table('payments')->where('id', $payment->id)->update([
                'amount' => (string) BigDecimal::of((string) $payment->amount)->plus((string) $payment->surcharge_amount)]);
        }
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('surcharge_amount'));
        Schema::table('loyalty_transactions', fn (Blueprint $table) => $table->dropColumn('base_amount'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['loyalty_redeemed_amount', 'surcharge_total']));
    }
};
