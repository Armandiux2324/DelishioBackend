<?php

namespace App\Services\Reports;

use App\Models\ExternalPlatformSale;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class SalesSummaryReport
{
    public function summarize(CarbonImmutable $from, CarbonImmutable $until, ?string $fromTime, ?string $toTime, ?int $cashRegisterId): array
    {
        $amountFields = ['subtotal', 'discount_total', 'loyalty_redeemed_amount', 'tax_total',
            'delivery_fee', 'tip_amount', 'surcharge_total', 'total'];
        $orders = Order::closedSales($from, $until)
            ->when($fromTime !== null, fn ($query) => $query->whereTime('closed_at', '>=', $fromTime)->whereTime('closed_at', '<=', $toTime))
            ->when($cashRegisterId !== null, fn ($query) => $query->where('cash_register_id', $cashRegisterId))
            ->selectRaw('COUNT(*) AS order_count');
        foreach ($amountFields as $field) {
            $orders->selectRaw("COALESCE(SUM({$field}), 0) AS {$field}");
        }
        $orderTotals = $orders->firstOrFail()->only(['order_count', ...$amountFields]);
        $orderTotals['order_count'] = (int) $orderTotals['order_count'];
        $orderTotals['net_consumption'] = (string) BigDecimal::of($orderTotals['subtotal'])
            ->minus($orderTotals['discount_total'])->minus($orderTotals['loyalty_redeemed_amount']);

        $platformTotals = ['sale_count' => 0, 'total_amount' => '0.00', 'commission_amount' => '0.00', 'net_amount' => '0.00'];
        if ($cashRegisterId === null) {
            $platformTotals = ExternalPlatformSale::where('sale_date', '>=', $from)->where('sale_date', '<', $until)
                ->when($fromTime !== null, fn ($query) => $query->whereTime('sale_date', '>=', $fromTime)->whereTime('sale_date', '<=', $toTime))
                ->selectRaw('COUNT(*) AS sale_count, COALESCE(SUM(total_amount), 0) AS total_amount, COALESCE(SUM(commission_amount), 0) AS commission_amount, COALESCE(SUM(net_amount), 0) AS net_amount')
                ->firstOrFail()->only(array_keys($platformTotals));
            $platformTotals['sale_count'] = (int) $platformTotals['sale_count'];
        }

        $orderAmount = BigDecimal::of($orderTotals['total']);

        return [
            'orders' => $orderTotals,
            'external_platform' => $platformTotals,
            'totals' => [
                'sale_count' => $orderTotals['order_count'] + $platformTotals['sale_count'],
                'total_amount' => (string) $orderAmount->plus($platformTotals['total_amount']),
                'amount_after_platform_commission' => (string) $orderAmount->plus($platformTotals['net_amount']),
            ],
        ];
    }
}
