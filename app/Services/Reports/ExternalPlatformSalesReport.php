<?php

namespace App\Services\Reports;

use App\Models\ExternalPlatformSale;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class ExternalPlatformSalesReport
{
    public function summarize(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $platforms = ExternalPlatformSale::where('sale_date', '>=', $from)->where('sale_date', '<', $until)
            ->groupBy('platform')->orderBy('platform')
            ->selectRaw('platform, COUNT(*) AS sale_count, SUM(total_amount) AS total_amount, SUM(commission_amount) AS commission_amount, SUM(net_amount) AS net_amount')
            ->get()->map(fn (ExternalPlatformSale $platform) => [
                'platform' => $platform->platform,
                'sale_count' => (int) $platform->sale_count,
                'total_amount' => $platform->total_amount,
                'commission_amount' => $platform->commission_amount,
                'net_amount' => $platform->net_amount,
            ]);

        return [
            'platforms' => $platforms,
            'totals' => [
                'sale_count' => $platforms->sum('sale_count'),
                'total_amount' => (string) BigDecimal::sum('0.00', ...$platforms->pluck('total_amount')),
                'commission_amount' => (string) BigDecimal::sum('0.00', ...$platforms->pluck('commission_amount')),
                'net_amount' => (string) BigDecimal::sum('0.00', ...$platforms->pluck('net_amount')),
            ],
        ];
    }
}
