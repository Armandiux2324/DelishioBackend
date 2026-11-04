<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\InventoryLossFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventory_item_id', 'quantity', 'reason', 'registered_by_employee_id'])]
class InventoryLoss extends Model
{
    /** @use HasFactory<InventoryLossFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // El historial sigue visible aunque el ingrediente se elimine lógicamente.
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('inventoryItem'));
        static::saving(function (InventoryLoss $loss) {
            if ($loss->exists) {
                abort_unless(InventoryItem::withTrashed()->whereKey($loss->getRawOriginal('inventory_item_id'))->exists(), 403);
            }
            abort_unless(InventoryItem::whereKey($loss->inventory_item_id)->exists(), 403);
            abort_unless(Employee::whereKey($loss->registered_by_employee_id)->where('role', 'admin')->exists(), 403);
        });
        static::deleting(function (InventoryLoss $loss) {
            abort_unless(InventoryItem::withTrashed()->whereKey($loss->getRawOriginal('inventory_item_id'))->exists(), 403);
        });
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class)->withTrashed();
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'registered_by_employee_id', 'user_id');
    }
}
