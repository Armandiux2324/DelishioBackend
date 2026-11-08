<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Services\OrderTotals;
use App\Support\RestaurantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductsReport
{
    private const AMOUNTS = ['gross_sales', 'discount_total', 'loyalty_redeemed_amount', 'returns_amount', 'returns_tax_amount', 'net_sales', 'tax_total', 'total_sales'];

    public function __construct(private readonly OrderTotals $orderTotals) {}

    public function summarize(CarbonImmutable $from, CarbonImmutable $until, array $filters, bool $includeReturns = true): array
    {
        $restaurantId = app(RestaurantContext::class)->id();
        $products = [];
        $zero = BigDecimal::of('0.00');
        $empty = ['quantity_sold' => 0, 'quantity_returned' => 0] + array_fill_keys(self::AMOUNTS, '0.00');
        $returnsInRange = fn ($query) => $query->where('returned_at', '>=', $from)->where('returned_at', '<', $until);

        Order::where(function (Builder $orders) use ($from, $until, $includeReturns, $returnsInRange) {
            $orders->where(fn (Builder $sales) => $sales->closedSales($from, $until));
            if ($includeReturns) {
                $orders->orWhereHas('items.returns', $returnsInRange);
            }
        })
            ->with([
                'items' => fn ($items) => $items->where('cancelled', false)->orderBy('id')
                    ->when($includeReturns, fn (Builder $items) => $items->with(['returns' => $returnsInRange])),
                'discounts' => fn ($discounts) => $discounts->orderBy('id'),
                'taxExemptions',
            ])
            ->chunkById(100, function ($orders) use ($from, $until, $includeReturns, $restaurantId, $filters, $empty, $zero, &$products) {
                $menuIds = $orders->flatMap(fn (Order $order) => $order->items->pluck('menu_item_id'))->unique()->all();
                $catalog = $this->catalog($menuIds, $restaurantId);
                $costs = DB::table('menu_item_ingredients')
                    ->join('inventory_items', 'inventory_items.id', '=', 'menu_item_ingredients.inventory_item_id')
                    ->where('inventory_items.restaurant_id', $restaurantId)
                    ->whereIn('menu_item_id', $catalog->keys())
                    ->groupBy('menu_item_id')
                    ->selectRaw('menu_item_id, SUM(menu_item_ingredients.quantity * inventory_items.unit_cost) AS cost')
                    ->pluck('cost', 'menu_item_id');

                foreach ($orders as $order) {
                    $sold = $order->closed_at !== null && $order->closed_at >= $from && $order->closed_at < $until;
                    $discounted = $redeemed = $net = $taxes = [];
                    if ($sold) {
                        $discounted = $this->orderTotals->basis($order, $order->items, $order->discounts)['lines'];
                        $redeemed = $this->orderTotals->proportionalShares($discounted, BigDecimal::of($order->loyalty_redeemed_amount));
                        foreach ($discounted as $id => $amount) {
                            $net[$id] = $amount->minus($redeemed[$id]);
                        }
                        $taxes = $this->taxShares($order, $catalog, $net);
                    }

                    // Filters apply after allocating all adjustments across the order.
                    foreach ($order->items as $item) {
                        $returns = $includeReturns ? $item->returns : collect();
                        $product = $catalog->get($item->menu_item_id);
                        if ((! $sold && $returns->isEmpty()) || $product === null
                            || (isset($filters['super_category_id']) && (int) $product->super_category_id !== (int) $filters['super_category_id'])
                            || (isset($filters['category_id']) && (int) $product->category_id !== (int) $filters['category_id'])
                            || (isset($filters['modifier']) && mb_stripos($item->modifiers ?? '', $filters['modifier']) === false)) {
                            continue;
                        }

                        $cost = $costs->get($product->id);
                        $products[$product->id] ??= [
                            'menu_item_id' => (int) $product->id,
                            'menu_item_name' => $product->name,
                            'super_category' => ['id' => (int) $product->super_category_id, 'name' => $product->super_category_name],
                            'category' => ['id' => (int) $product->category_id, 'name' => $product->category_name],
                            'estimated_unit_cost' => $cost === null ? null : (string) BigDecimal::of((string) $cost)->toScale(4, RoundingMode::HalfUp),
                        ] + $empty;
                        $row = &$products[$product->id];
                        $gross = $sold ? BigDecimal::of($item->unit_price)->multipliedBy($item->quantity) : $zero;
                        $returned = BigDecimal::sum($zero, ...$returns->pluck('amount'));
                        $returnedTax = BigDecimal::sum($zero, ...$returns->pluck('tax_amount'));
                        $netSales = ($net[$item->id] ?? $zero)->minus($returned);
                        $tax = ($taxes[$item->id] ?? $zero)->minus($returnedTax);
                        $row['quantity_sold'] += $sold ? $item->quantity : 0;
                        $row['quantity_returned'] += $returns->sum('quantity');
                        foreach ([
                            'gross_sales' => $gross,
                            'discount_total' => $gross->minus($discounted[$item->id] ?? $zero),
                            'loyalty_redeemed_amount' => $redeemed[$item->id] ?? $zero,
                            'returns_amount' => $returned,
                            'returns_tax_amount' => $returnedTax,
                            'net_sales' => $netSales,
                            'tax_total' => $tax,
                            'total_sales' => $netSales->plus($tax),
                        ] as $field => $amount) {
                            $row[$field] = (string) $amount->plus($row[$field]);
                        }
                        unset($row);
                    }
                }
            });

        ksort($products);
        $totals = $empty;
        $totals['quantity_sold'] = array_sum(array_column($products, 'quantity_sold'));
        $totals['quantity_returned'] = array_sum(array_column($products, 'quantity_returned'));
        foreach (self::AMOUNTS as $field) {
            $totals[$field] = (string) BigDecimal::sum('0.00', ...array_column($products, $field));
        }

        return [
            'cost_basis' => 'current_recipe',
            'returns_date_basis' => 'returned_at',
            'tax_basis' => 'allocated_order_tax_using_current_rates',
            'products' => array_values($products),
            'totals' => $totals,
        ];
    }

    private function catalog(array $menuIds, int $restaurantId): Collection
    {
        return DB::table('menu_items')
            ->join('categories', 'categories.id', '=', 'menu_items.category_id')
            ->join('super_categories', 'super_categories.id', '=', 'categories.super_category_id')
            ->leftJoin('tax_classes', fn (JoinClause $join) => $join->on('tax_classes.id', '=', 'super_categories.tax_class_id')
                ->on('tax_classes.restaurant_id', '=', 'super_categories.restaurant_id'))
            ->where('super_categories.restaurant_id', $restaurantId)
            ->whereIn('menu_items.id', $menuIds)
            ->get(['menu_items.id', 'menu_items.name', 'categories.id as category_id', 'categories.name as category_name',
                'super_categories.id as super_category_id', 'super_categories.name as super_category_name', 'tax_classes.percentage'])
            ->keyBy('id');
    }

    private function taxShares(Order $order, Collection $catalog, array $net): array
    {
        if (BigDecimal::of($order->tax_total)->isZero() || $order->taxExemptions->contains(fn ($exemption) => $exemption->order_item_id === null)) {
            return [];
        }

        $exempt = $order->taxExemptions->pluck('order_item_id')->all();
        $taxable = $weights = [];
        foreach ($order->items as $item) {
            if (! in_array($item->id, $exempt, true)) {
                $taxable[$item->id] = $net[$item->id];
                $rate = (string) ($catalog->get($item->menu_item_id)->percentage ?? '0.00');
                $weights[$item->id] = $net[$item->id]->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp);
            }
        }

        // The ledger stores the paid tax for the whole order. Current rates only supply weights.
        if (BigDecimal::sum('0.00', ...array_values($weights))->isZero()) {
            $weights = $taxable;
        }

        return $this->orderTotals->proportionalShares($weights, BigDecimal::of($order->tax_total));
    }
}
