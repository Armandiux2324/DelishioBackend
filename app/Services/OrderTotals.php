<?php

namespace App\Services;

use App\Models\Order;
use App\Support\RestaurantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderTotals
{
    /** @return array{subtotal: BigDecimal, lines: array<int, BigDecimal>} */
    public function basis(Order $order, ?Collection $items = null, ?Collection $discounts = null): array
    {
        $items ??= $order->items()->where('cancelled', false)->orderBy('id')->get();
        $subtotal = BigDecimal::of('0.00');
        $lines = [];
        foreach ($items as $item) {
            $lines[$item->id] = BigDecimal::of($item->unit_price)->multipliedBy($item->quantity);
            $subtotal = $subtotal->plus($lines[$item->id]);
        }
        $discounts ??= $order->discounts()->orderBy('id')->get();
        foreach ($discounts as $discount) {
            if ($discount->order_item_id !== null) {
                $id = $discount->order_item_id;
                if (isset($lines[$id])) {
                    $amount = BigDecimal::of($discount->amount);
                    $lines[$id] = $amount->isGreaterThan($lines[$id]) ? BigDecimal::of('0.00') : $lines[$id]->minus($amount);
                }
            } else {
                $this->allocate($lines, BigDecimal::of($discount->amount));
            }
        }

        return ['subtotal' => $subtotal, 'lines' => $lines];
    }

    public function calculate(Order $order, ?Collection $items = null, ?array $rates = null, string $redemptionAmount = '0.00'): array
    {
        $items ??= $order->items()->where('cancelled', false)->orderBy('id')->get();
        $basis = $this->basis($order, $items);
        $beforeRedemption = array_reduce($basis['lines'], fn ($sum, $line) => $sum->plus($line), BigDecimal::of('0.00'));
        $this->allocate($basis['lines'], BigDecimal::of($redemptionAmount));
        $rates ??= $order->status === 'sent' || $order->sent_at !== null ? $this->taxRates($items) : [];
        $exemptions = $order->taxExemptions()->get();
        $wholeExempt = $exemptions->contains(fn ($exemption) => $exemption->order_item_id === null);
        $lineExempt = $exemptions->pluck('order_item_id')->filter()->all();
        $net = $tax = BigDecimal::of('0.00');
        foreach ($items as $item) {
            $base = $basis['lines'][$item->id];
            $net = $net->plus($base);
            if (! $wholeExempt && ! in_array($item->id, $lineExempt, true)) {
                $tax = $tax->plus($base->multipliedBy($rates[$item->menu_item_id] ?? '0.00')->dividedBy(100, 2, RoundingMode::HalfUp));
            }
        }
        $discount = $basis['subtotal']->minus($beforeRedemption);
        $redeemed = $beforeRedemption->minus($net);
        $total = $net->plus($tax)->plus($order->delivery_fee ?? '0.00');
        if ($order->status === 'cancelled') {
            return ['subtotal' => '0.00', 'discount_total' => '0.00', 'loyalty_redeemed_amount' => '0.00', 'tax_total' => '0.00', 'total' => '0.00'];
        }
        foreach ([$basis['subtotal'], $discount, $redeemed, $tax, $total] as $amount) {
            if ($amount->isNegative() || $amount->isGreaterThan('99999999.99')) {
                throw ValidationException::withMessages(['items' => __('Los importes de la orden exceden el rango permitido.')]);
            }
        }

        return ['subtotal' => (string) $basis['subtotal'], 'discount_total' => (string) $discount, 'loyalty_redeemed_amount' => (string) $redeemed, 'tax_total' => (string) $tax, 'total' => (string) $total];
    }

    private function allocate(array &$lines, BigDecimal $amount): void
    {
        $total = BigDecimal::sum('0.00', ...array_values($lines));
        if ($amount->isGreaterThan($total)) {
            $amount = $total;
        }
        foreach ($this->proportionalShares($lines, $amount) as $id => $part) {
            $lines[$id] = $lines[$id]->minus($part);
        }
    }

    /**
     * @param  array<int, BigDecimal>  $weights
     * @return array<int, BigDecimal>
     */
    public function proportionalShares(array $weights, BigDecimal $amount): array
    {
        $total = BigDecimal::sum('0.00', ...array_values($weights));
        if ($total->isZero() || $amount->isZero()) {
            return array_fill_keys(array_keys($weights), BigDecimal::of('0.00'));
        }
        $left = $amount;
        $parts = [];
        foreach ($weights as $id => $weight) {
            $parts[$id] = $amount->multipliedBy($weight)->dividedBy($total, 2, RoundingMode::Down);
            $left = $left->minus($parts[$id]);
        }
        // Keep the original line order when assigning leftover cents.
        foreach ($weights as $id => $weight) {
            if ($left->isPositive() && $weight->isPositive()) {
                $parts[$id] = $parts[$id]->plus('0.01');
                $left = $left->minus('0.01');
            }
        }

        return $parts;
    }

    private function taxRates(Collection $items): array
    {
        // Explicit tenant predicates retain tax lookup through logically deleted catalog ancestors.
        $id = app(RestaurantContext::class)->id();
        $rates = DB::table('menu_items')->join('categories', 'categories.id', '=', 'menu_items.category_id')
            ->join('super_categories', 'super_categories.id', '=', 'categories.super_category_id')
            ->join('tax_classes', 'tax_classes.id', '=', 'super_categories.tax_class_id')
            ->where('super_categories.restaurant_id', $id)->where('tax_classes.restaurant_id', $id)
            ->whereIn('menu_items.id', $items->pluck('menu_item_id'))->pluck('tax_classes.percentage', 'menu_items.id')
            ->map(fn ($rate) => (string) $rate)->all();
        if (count($rates) !== $items->pluck('menu_item_id')->unique()->count()) {
            throw ValidationException::withMessages(['items' => __('La clasificación fiscal del menú no está disponible.')]);
        }

        return $rates;
    }
}
