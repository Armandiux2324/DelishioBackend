<?php

namespace App\Services\Reports;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrdersReport
{
    public function query(string $type, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        if ($type === 'paid') {
            $orders = Order::closedSales($from, $until);
            $dateColumn = 'closed_at';
        } else {
            $orders = Order::where('created_at', '>=', $from)->where('created_at', '<', $until);
            $dateColumn = 'created_at';

            if ($type === 'open') {
                $orders->whereIn('status', ['open', 'sent']);
            } else {
                $orders->where('source', 'customer_app');
            }
        }

        return $orders->with('advancePayment:id,order_id,status')
            ->orderByDesc($dateColumn)->orderByDesc('id');
    }
}
