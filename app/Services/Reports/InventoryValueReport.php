<?php

namespace App\Services\Reports;

use App\Models\InventoryItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

class InventoryValueReport
{
    public function summarize(?int $itemId): array
    {
        $items = InventoryItem::when($itemId !== null, fn (Builder $query) => $query->whereKey($itemId))
            ->orderBy('id')->get(['id', 'name', 'unit', 'current_stock', 'unit_cost'])
            ->map(fn (InventoryItem $item) => [
                ...$item->only(['id', 'name', 'unit', 'current_stock', 'unit_cost']),
                'value' => (string) BigDecimal::of($item->current_stock)->multipliedBy($item->unit_cost)->toScale(2, RoundingMode::HalfUp),
            ]);

        return [
            'valuation_basis' => 'current_stock_and_unit_cost',
            'items' => $items,
            'totals' => [
                'item_count' => $items->count(),
                'value' => (string) BigDecimal::sum('0.00', ...$items->pluck('value')),
            ],
        ];
    }
}
