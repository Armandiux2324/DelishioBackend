<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['restaurant_id', 'name', 'unit', 'unit_cost', 'current_stock'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use BelongsToRestaurant, HasFactory, SoftDeletes;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:4', 'current_stock' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item) {
            if ($item->exists && $item->isDirty('unit')) {
                abort_if($item->losses()->exists(), 409, __('La unidad no se puede cambiar después de registrar pérdidas.'));
                abort_if(InventoryMovement::where('inventory_item_id', $item->id)->exists(), 409, __('La unidad no se puede cambiar después de registrar consumos.'));
                abort_if(MenuItemIngredient::where('inventory_item_id', $item->id)->exists(), 409, __('La unidad no se puede cambiar si el ingrediente forma parte de una receta.'));
            }
        });
        static::deleting(function (InventoryItem $item) {
            abort_if(MenuItemIngredient::where('inventory_item_id', $item->id)->whereHas('menuItem', fn ($query) => $query->withoutTrashed())->exists(),
                409, __('El ingrediente forma parte de una receta.'));
        });
    }

    public function losses(): HasMany
    {
        return $this->hasMany(InventoryLoss::class);
    }
}
