<?php

namespace App\Services\Reports;

use App\Models\ExternalPlatformSale;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AdminDashboard
{
    public function __construct(private readonly PaymentTransactionsReport $payments) {}

    public function summarize(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $sales = Order::closedSales($from, $until);
        $orderCounts = Order::where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw("COUNT(*) AS order_count, COUNT(CASE WHEN status IN ('open', 'sent') THEN 1 END) AS open_order_count")
            ->toBase()->first();
        $hourlySales = $this->salesByHour($sales, $from, $until);
        $salesCount = array_sum(array_column($hourlySales, 'sale_count'));
        $salesTotal = BigDecimal::sum(...array_column($hourlySales, 'total_amount'));

        $categories = (clone $sales)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->join('categories', 'categories.id', '=', 'menu_items.category_id')
            ->join('super_categories', 'super_categories.id', '=', 'categories.super_category_id')
            ->whereColumn('super_categories.restaurant_id', 'orders.restaurant_id')
            ->where('order_items.cancelled', false)
            ->selectRaw('categories.id AS category_id, categories.name, SUM(order_items.quantity * order_items.unit_price) AS subtotal')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('subtotal')->orderBy('categories.id')
            ->get()
            ->map(fn (Order $row) => ['category_id' => (int) $row->category_id, 'name' => $row->name, 'subtotal' => $row->subtotal]);

        $employees = (clone $sales)
            ->where('orders.source', 'pos')
            ->join('employees', 'employees.user_id', '=', 'orders.waiter_employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->whereColumn('employees.restaurant_id', 'orders.restaurant_id')
            ->selectRaw('employees.user_id AS employee_id, users.name, COUNT(*) AS order_count, SUM(orders.total) AS total')
            ->groupBy('employees.user_id', 'users.name')
            ->orderByDesc('total')->orderBy('employees.user_id')->limit(5)
            ->get()
            ->map(fn (Order $row) => ['employee_id' => (int) $row->employee_id, 'name' => $row->name,
                'order_count' => (int) $row->order_count, 'total_amount' => $row->total]);

        return [
            'sales_count' => $salesCount,
            'sales_total' => (string) $salesTotal,
            'order_count' => (int) $orderCounts->order_count,
            'open_order_count' => (int) $orderCounts->open_order_count,
            'average_ticket' => $salesCount === 0 ? '0.00' : (string) $salesTotal->dividedBy($salesCount, 2, RoundingMode::HalfUp),
            'by_payment_method' => $this->payments->summarize($from, $until)['by_type'],
            'by_category' => $categories,
            'top_employees' => $employees,
            'sales_by_hour' => $hourlySales,
        ];
    }

    private function salesByHour(Builder $sales, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $orders = (clone $sales)
            ->selectRaw('SUBSTR(orders.closed_at, 12, 2) AS sale_hour, COUNT(*) AS sale_count, SUM(orders.total) AS amount')
            ->groupBy('sale_hour')->toBase()->get();
        $external = ExternalPlatformSale::where('sale_date', '>=', $from)->where('sale_date', '<', $until)
            ->selectRaw('SUBSTR(sale_date, 12, 2) AS sale_hour, COUNT(*) AS sale_count, SUM(total_amount) AS amount')
            ->groupBy('sale_hour')->toBase()->get();

        $hours = array_map(fn (int $hour) => ['hour' => $hour, 'sale_count' => 0, 'total_amount' => '0.00'], range(0, 23));
        foreach ($orders->concat($external) as $sale) {
            $hour = (int) $sale->sale_hour;
            $amount = BigDecimal::of((string) $sale->amount)->toScale(2, RoundingMode::HalfUp);
            $hours[$hour]['sale_count'] += (int) $sale->sale_count;
            $hours[$hour]['total_amount'] = (string) BigDecimal::of($hours[$hour]['total_amount'])->plus($amount);
        }

        return $hours;
    }
}
