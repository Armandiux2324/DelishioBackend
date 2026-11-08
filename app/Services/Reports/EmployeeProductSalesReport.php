<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Services\OrderTotals;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class EmployeeProductSalesReport
{
    public function __construct(private readonly OrderTotals $orderTotals) {}

    public function summarize(CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        $products = [];
        Order::closedSales($from, $until)->whereNotNull('waiter_employee_id')
            ->when(isset($filters['employee_id']), fn (Builder $query) => $query->where('waiter_employee_id', $filters['employee_id']))
            ->when(isset($filters['role']), fn (Builder $query) => $query->whereHas('waiter', fn (Builder $employee) => $employee->where('role', $filters['role'])))
            ->with(['waiter.user:id,name', 'items' => fn ($items) => $items->where('cancelled', false)->orderBy('id'),
                'items.menuItem:id,category_id,name', 'discounts' => fn ($discounts) => $discounts->orderBy('id')])
            ->chunkById(100, function ($orders) use (&$products) {
                foreach ($orders as $order) {
                    $discounted = $this->orderTotals->basis($order, $order->items, $order->discounts)['lines'];
                    $redeemed = $this->orderTotals->proportionalShares($discounted, BigDecimal::of($order->loyalty_redeemed_amount));
                    foreach ($order->items as $item) {
                        $key = $order->waiter_employee_id.':'.$item->menu_item_id;
                        $products[$key] ??= [
                            'employee_id' => $order->waiter_employee_id,
                            'employee_name' => $order->waiter?->user?->name,
                            'menu_item_id' => $item->menu_item_id,
                            'menu_item_name' => $item->menuItem?->name,
                            'quantity_sold' => 0,
                            'gross_sales' => '0.00',
                            'net_sales' => '0.00',
                        ];
                        $products[$key]['quantity_sold'] += $item->quantity;
                        $products[$key]['gross_sales'] = (string) BigDecimal::of($item->unit_price)
                            ->multipliedBy($item->quantity)->plus($products[$key]['gross_sales']);
                        $products[$key]['net_sales'] = (string) $discounted[$item->id]
                            ->minus($redeemed[$item->id])->plus($products[$key]['net_sales']);
                    }
                }
            });

        return [
            'products' => array_values($products),
            'totals' => [
                'quantity_sold' => array_sum(array_column($products, 'quantity_sold')),
                'gross_sales' => (string) BigDecimal::sum('0.00', ...array_column($products, 'gross_sales')),
                'net_sales' => (string) BigDecimal::sum('0.00', ...array_column($products, 'net_sales')),
            ],
        ];
    }
}
