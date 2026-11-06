<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\ExternalPlatformSaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['external_sale_id', 'menu_item_id', 'description', 'quantity', 'unit_price'])]
class ExternalPlatformSaleItem extends Model
{
    /** @use HasFactory<ExternalPlatformSaleItemFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('sale'));
        static::creating(function (ExternalPlatformSaleItem $item) {
            abort_unless(ExternalPlatformSale::whereKey($item->external_sale_id)->exists(), 403);
            if ($item->menu_item_id !== null) {
                abort_unless(MenuItem::whereKey($item->menu_item_id)->exists(), 403);
            }
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ExternalPlatformSale::class, 'external_sale_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }
}
