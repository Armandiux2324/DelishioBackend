<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\TaxExemption;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrderAuditReports
{
    public function discounts(CarbonImmutable $from, CarbonImmutable $until, string $kind): array
    {
        $entries = OrderDiscount::where('kind', $kind)
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->with('order:id,status')->orderBy('created_at')->orderBy('id')->get();

        return [
            'entries' => $entries,
            'totals' => [
                'entry_count' => $entries->count(),
                'order_count' => $entries->pluck('order_id')->unique()->count(),
                'authorized_amount' => (string) BigDecimal::sum('0.00', ...$entries->pluck('amount')),
            ],
        ];
    }

    public function taxes(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $orders = Order::closedSales($from, $until)->orderBy('closed_at')->orderBy('id')
            ->get(['id', 'closed_at', 'tax_total']);
        $exemptions = TaxExemption::where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->with('order:id,status')->orderBy('created_at')->orderBy('id')->get();

        return [
            'orders' => $orders,
            'exemptions' => $exemptions,
            'totals' => [
                'tax_total' => (string) BigDecimal::sum('0.00', ...$orders->pluck('tax_total')),
                'exemption_count' => $exemptions->count(),
            ],
        ];
    }

    public function tips(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $orders = Order::closedSales($from, $until)->where('tip_amount', '>', 0)
            ->orderBy('closed_at')->orderBy('id')->get(['id', 'closed_at', 'waiter_employee_id', 'tip_amount']);

        return [
            'orders' => $orders,
            'totals' => [
                'order_count' => $orders->count(),
                'tip_amount' => (string) BigDecimal::sum('0.00', ...$orders->pluck('tip_amount')),
            ],
        ];
    }

    public function cancellations(CarbonImmutable $from, CarbonImmutable $until): array
    {
        // shortcut: older POS cancellations only have a creation date in the database, upgrade if audit logs are imported.
        $orders = Order::where('status', 'cancelled')
            ->where(function (Builder $orders) use ($from, $until) {
                $orders->where('cancelled_at', '>=', $from)->where('cancelled_at', '<', $until)
                    ->orWhere(function (Builder $legacy) use ($from, $until) {
                        $legacy->whereNull('cancelled_at')->where(function (Builder $orders) use ($from, $until) {
                            $orders->whereHas('advancePayment', fn (Builder $payment) => $payment
                                ->where('cancellation_requested_at', '>=', $from)->where('cancellation_requested_at', '<', $until))
                                ->orWhere(fn (Builder $orders) => $orders
                                    ->whereDoesntHave('advancePayment', fn (Builder $payment) => $payment->whereNotNull('cancellation_requested_at'))
                                    ->where('created_at', '>=', $from)->where('created_at', '<', $until));
                        });
                    });
            })
            ->with('advancePayment:id,order_id,cancellation_requested_at')->orderBy('id')
            ->get(['id', 'source', 'waiter_employee_id', 'cancel_reason', 'cancel_authorized_by_employee_id', 'cancelled_at', 'created_at'])
            ->map(fn (Order $order) => [
                ...$order->attributesToArray(),
                'cancelled_at' => $order->cancelled_at ?? $order->advancePayment?->cancellation_requested_at,
            ]);

        $items = OrderItem::where('cancelled', true)
            ->whereRaw('COALESCE(cancelled_at, created_at) >= ? AND COALESCE(cancelled_at, created_at) < ?', [$from, $until])
            ->orderBy('id')->get(['id', 'order_id', 'menu_item_id', 'quantity', 'unit_price', 'cancel_reason',
                'cancelled_by_employee_id', 'cancel_authorized_by_employee_id', 'cancelled_at', 'created_at']);

        return [
            'orders' => $orders,
            'items' => $items,
            'missing_timestamp_date_basis' => 'created_at',
            'totals' => [
                'order_count' => $orders->count(),
                'item_count' => $items->count(),
                'item_quantity' => $items->sum('quantity'),
                'gross_item_amount' => (string) BigDecimal::sum('0.00', ...$items->map(
                    fn (OrderItem $item) => BigDecimal::of($item->unit_price)->multipliedBy($item->quantity))),
            ],
        ];
    }
}
