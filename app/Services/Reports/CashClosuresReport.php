<?php

namespace App\Services\Reports;

use App\Models\CashRegister;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CashClosuresReport
{
    public function summarize(CarbonImmutable $from, CarbonImmutable $until, ?int $registerId): array
    {
        $amounts = ['opening_amount', 'cash_sales', 'cash_in', 'cash_out', 'tip_amount', 'expected_cash', 'counted_cash', 'difference'];
        $closures = CashRegister::where('closed_at', '>=', $from)->where('closed_at', '<', $until)
            ->when($registerId !== null, fn (Builder $query) => $query->whereKey($registerId))
            ->withSum('cashPayments as cash_sales', 'amount')
            ->withSum(['movements as cash_in' => fn (Builder $query) => $query->where('type', 'cash_in')], 'amount')
            ->withSum(['movements as cash_out' => fn (Builder $query) => $query->where('type', 'cash_out')], 'amount')
            ->withSum('closedOrders as tip_amount', 'tip_amount')
            ->orderBy('closed_at')->orderBy('id')->get()
            ->map(function (CashRegister $register) use ($amounts) {
                $closure = $register->only(['id', 'opened_at', 'closed_at', 'opened_by_employee_id', 'closed_by_employee_id', ...$amounts]);
                foreach (['cash_sales', 'cash_in', 'cash_out', 'tip_amount'] as $field) {
                    $closure[$field] = (string) BigDecimal::of((string) ($closure[$field] ?? '0.00'))->toScale(2, RoundingMode::HalfUp);
                }

                return $closure;
            });

        $totals = ['closure_count' => $closures->count()];
        foreach ($amounts as $field) {
            $totals[$field] = (string) BigDecimal::sum('0.00', ...$closures->pluck($field)->filter(fn ($amount) => $amount !== null)->values());
        }

        return [
            'cash_sales_basis' => 'recorded_cash_payments_including_any_cash_tips',
            'tip_basis' => 'closed_order_tips_all_methods',
            'closures' => $closures,
            'totals' => $totals,
        ];
    }
}
