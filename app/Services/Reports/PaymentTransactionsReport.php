<?php

namespace App\Services\Reports;

use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class PaymentTransactionsReport
{
    public function summarize(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = Order::closedSales($from, $until)
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->selectRaw("CASE WHEN orders.source = 'customer_app' THEN 'online' ELSE payments.method END AS payment_type")
            ->selectRaw('COUNT(*) AS transaction_count, SUM(payments.amount) AS amount, SUM(payments.surcharge_amount) AS surcharge_amount')
            ->groupBy('payment_type')
            ->toBase()
            ->get();

        $byType = array_fill_keys(['cash', 'card', 'transfer', 'online'], [
            'transaction_count' => 0,
            'amount' => '0.00',
            'surcharge_amount' => '0.00',
            'total_amount' => '0.00',
        ]);
        $amountTotal = BigDecimal::of('0.00');
        $surchargeTotal = BigDecimal::of('0.00');
        $transactionCount = 0;

        foreach ($rows as $row) {
            $amount = BigDecimal::of((string) $row->amount)->toScale(2, RoundingMode::HalfUp);
            $surcharge = BigDecimal::of((string) $row->surcharge_amount)->toScale(2, RoundingMode::HalfUp);
            $count = (int) $row->transaction_count;
            $byType[$row->payment_type] = [
                'transaction_count' => $count,
                'amount' => (string) $amount,
                'surcharge_amount' => (string) $surcharge,
                'total_amount' => (string) $amount->plus($surcharge),
            ];
            $amountTotal = $amountTotal->plus($amount);
            $surchargeTotal = $surchargeTotal->plus($surcharge);
            $transactionCount += $count;
        }

        return [
            'by_type' => $byType,
            'totals' => [
                'transaction_count' => $transactionCount,
                'amount' => (string) $amountTotal,
                'surcharge_amount' => (string) $surchargeTotal,
                'total_amount' => (string) $amountTotal->plus($surchargeTotal),
            ],
        ];
    }
}
