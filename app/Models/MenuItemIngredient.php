<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use App\Observers\MenuItemIngredientObserver;
use Database\Factories\MenuItemIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['menu_item_id', 'inventory_item_id', 'quantity'])]
#[ObservedBy([MenuItemIngredientObserver::class])]
class MenuItemIngredient extends Model
{
    /** @use HasFactory<MenuItemIngredientFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('menuItem'));
        static::saving(function (MenuItemIngredient $ingredient) {
            if ($ingredient->exists) {
                abort_if($ingredient->isDirty(['menu_item_id', 'inventory_item_id']), 403);
            }
            abort_unless(MenuItem::whereKey($ingredient->menu_item_id)->lockForUpdate()->first(), 403);
            abort_unless(InventoryItem::whereKey($ingredient->inventory_item_id)->lockForUpdate()->first(), 403);
        });
        static::deleting(fn (MenuItemIngredient $ingredient) => abort_unless(
            MenuItem::whereKey($ingredient->getRawOriginal('menu_item_id'))->lockForUpdate()->first(), 403
        ));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class)->withTrashed();
    }
}
