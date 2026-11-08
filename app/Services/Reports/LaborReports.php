<?php

namespace App\Services\Reports;

use App\Models\ExternalPlatformSale;
use App\Models\Order;
use App\Models\WorkShift;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class LaborReports
{
    public function shifts(CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        $shifts = WorkShift::where('clock_in', '<', $until)
            ->where(fn (Builder $query) => $query->whereNull('clock_out')->orWhere('clock_out', '>', $from))
            ->whereHas('employee', function (Builder $query) use ($filters) {
                $this->filterEmployees($query, $filters);
            })
            ->with('employee.user:id,name')->orderBy('clock_in')->orderBy('id')->get()
            ->map(function (WorkShift $shift) use ($from, $until) {
                $seconds = $shift->clock_out === null ? null : max(0,
                    min($shift->clock_out->timestamp, $until->timestamp) - max($shift->clock_in->timestamp, $from->timestamp));

                return [
                    'id' => $shift->id,
                    'employee_id' => $shift->employee_id,
                    'employee_name' => $shift->employee->user?->name,
                    'role' => $shift->employee->role,
                    'hourly_rate' => $shift->employee->hourly_rate,
                    'clock_in' => $shift->clock_in,
                    'clock_out' => $shift->clock_out,
                    'worked_seconds' => $seconds,
                ];
            });

        return [
            'shifts' => $shifts,
            'totals' => [
                'shift_count' => $shifts->count(),
                'open_shift_count' => $shifts->whereNull('clock_out')->count(),
                'worked_seconds' => $shifts->sum('worked_seconds'),
            ],
        ];
    }

    public function payroll(CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        $shifts = $this->shifts($from, $until, $filters);
        $employees = $shifts['shifts']->groupBy('employee_id')->map(function ($shifts) {
            $employee = $shifts->first();
            $seconds = $shifts->sum('worked_seconds');
            $pay = BigDecimal::of($employee['hourly_rate'])->multipliedBy($seconds)->dividedBy(3600, 2, RoundingMode::HalfUp);

            return [
                'employee_id' => $employee['employee_id'],
                'employee_name' => $employee['employee_name'],
                'role' => $employee['role'],
                'hourly_rate' => $employee['hourly_rate'],
                'shifts' => $shifts->values(),
                'worked_seconds' => $seconds,
                ...$this->hours($seconds),
                'estimated_pay' => (string) $pay,
            ];
        })->values();

        return [
            'pay_basis' => 'current_base_hourly_rate',
            'overtime_policy' => 'undefined',
            'employees' => $employees,
            'totals' => [
                ...$shifts['totals'],
                ...$this->hours($shifts['totals']['worked_seconds']),
                'estimated_pay' => (string) BigDecimal::sum('0.00', ...$employees->pluck('estimated_pay')),
            ],
        ];
    }

    public function salesVsLabor(CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        $orders = Order::closedSales($from, $until)
            ->when($this->filtersEmployees($filters), fn (Builder $orders) => $orders
                ->whereHas('waiter', fn (Builder $employee) => $this->filterEmployees($employee, $filters)))
            ->selectRaw('COALESCE(SUM(subtotal), 0) AS subtotal, COALESCE(SUM(discount_total), 0) AS discount_total, COALESCE(SUM(loyalty_redeemed_amount), 0) AS loyalty_redeemed_amount')
            ->firstOrFail();
        $consumption = BigDecimal::of($orders->subtotal)->minus($orders->discount_total)->minus($orders->loyalty_redeemed_amount);
        $platformSales = ExternalPlatformSale::where('sale_date', '>=', $from)->where('sale_date', '<', $until)
            ->when($this->filtersEmployees($filters), fn (Builder $sales) => $sales
                ->whereHas('registeredBy', fn (Builder $employee) => $this->filterEmployees($employee, $filters)))
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS net_amount')->firstOrFail();
        $netSales = $consumption->plus($platformSales->net_amount);
        $labor = BigDecimal::of($this->payroll($from, $until, $filters)['totals']['estimated_pay']);

        return [
            'sales_basis' => 'net_consumption_plus_net_platform_sales',
            'platform_employee_basis' => 'registered_by_employee_id',
            'labor_basis' => 'current_base_hourly_rate',
            'totals' => [
                'net_consumption' => (string) $consumption,
                'external_platform_net_sales' => $platformSales->net_amount,
                'net_sales' => (string) $netSales,
                'estimated_labor_cost' => (string) $labor,
                'labor_cost_percentage' => $netSales->isZero() ? null
                    : (string) $labor->multipliedBy(100)->dividedBy($netSales, 2, RoundingMode::HalfUp),
            ],
        ];
    }

    private function hours(int $seconds): array
    {
        return [
            'worked_hours' => (string) BigDecimal::of($seconds)->dividedBy(3600, 4, RoundingMode::HalfUp),
            'regular_hours' => null,
            'overtime_hours' => null,
        ];
    }

    private function filterEmployees(Builder $employees, array $filters): Builder
    {
        return $employees
            ->when(isset($filters['employee_id']), fn (Builder $query) => $query->whereKey($filters['employee_id']))
            ->when(isset($filters['role']), fn (Builder $query) => $query->where('role', $filters['role']));
    }

    private function filtersEmployees(array $filters): bool
    {
        return isset($filters['employee_id']) || isset($filters['role']);
    }
}
