<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class ProfitMarginsReport
{
    public function __construct(private readonly ProductsReport $products) {}

    public function summarize(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $sales = $this->products->summarize($from, $until, [], includeReturns: false);
        $costedSales = $costTotal = BigDecimal::of('0.00');
        $products = [];
        foreach ($sales['products'] as $product) {
            $cost = $product['estimated_unit_cost'] === null ? null : BigDecimal::of($product['estimated_unit_cost'])
                ->multipliedBy($product['quantity_sold'])->toScale(2, RoundingMode::HalfUp);
            $net = BigDecimal::of($product['net_sales']);
            if ($cost !== null) {
                $costTotal = $costTotal->plus($cost);
                $costedSales = $costedSales->plus($net);
            }

            $products[] = [
                'menu_item_id' => $product['menu_item_id'],
                'menu_item_name' => $product['menu_item_name'],
                'quantity_sold' => $product['quantity_sold'],
                'net_sales' => (string) $net,
                'estimated_cost' => $cost === null ? null : (string) $cost,
                ...$this->margin($net, $cost),
            ];
        }

        return [
            'cost_basis' => 'current_recipe',
            'products' => $products,
            'totals' => [
                'net_sales' => $sales['totals']['net_sales'],
                'costed_net_sales' => (string) $costedSales,
                'uncosted_net_sales' => (string) BigDecimal::of($sales['totals']['net_sales'])->minus($costedSales),
                'estimated_cost' => (string) $costTotal,
                ...$this->margin($costedSales, $costTotal),
            ],
        ];
    }

    private function margin(BigDecimal $sales, ?BigDecimal $cost): array
    {
        $profit = $cost === null ? null : $sales->minus($cost);

        return [
            'estimated_profit' => $profit === null ? null : (string) $profit,
            'margin_percentage' => $profit === null || $sales->isZero() ? null
                : (string) $profit->multipliedBy(100)->dividedBy($sales, 2, RoundingMode::HalfUp),
        ];
    }
}
