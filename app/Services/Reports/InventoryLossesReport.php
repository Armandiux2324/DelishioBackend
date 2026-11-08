<?php

namespace App\Services\Reports;

use App\Models\InventoryLoss;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class InventoryLossesReport
{
    public function summarize(CarbonImmutable $from, CarbonImmutable $until, ?int $itemId): array
    {
        $losses = InventoryLoss::where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->when($itemId !== null, fn (Builder $query) => $query->where('inventory_item_id', $itemId))
            ->with('inventoryItem:id,name,unit')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (InventoryLoss $loss) => [
                ...$loss->only(['id', 'inventory_item_id', 'quantity', 'reason', 'registered_by_employee_id', 'created_at']),
                'name' => $loss->inventoryItem->name,
                'unit' => $loss->inventoryItem->unit,
            ]);

        return [
            'losses' => $losses,
            'totals' => [
                'loss_count' => $losses->count(),
                'by_item' => $losses->groupBy('inventory_item_id')->map(fn (Collection $entries) => [
                    ...Arr::only($entries->first(), ['inventory_item_id', 'name', 'unit']),
                    'loss_count' => $entries->count(),
                    'quantity' => (string) BigDecimal::sum('0.000', ...$entries->pluck('quantity')),
                ])->values(),
            ],
        ];
    }
}
