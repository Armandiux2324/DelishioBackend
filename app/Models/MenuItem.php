<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use App\Observers\MenuItemObserver;
use App\Support\RestaurantContext;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['category_id', 'name', 'sale_price', 'description', 'image_url', 'legal_age_required', 'preparation_station', 'active'])]
#[ObservedBy([MenuItemObserver::class])]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory, SoftDeletes;

    public const AUDITED_FIELDS = ['category_id', 'name', 'sale_price', 'description', 'image_url', 'legal_age_required', 'preparation_station', 'active'];

    protected $attributes = ['legal_age_required' => false, 'preparation_station' => 'none', 'active' => true];

    protected function casts(): array
    {
        return ['sale_price' => 'decimal:2', 'legal_age_required' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Ownership must survive soft deletion of catalog ancestors for audit history.
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereIn($query->qualifyColumn('category_id'),
            DB::table('categories')->join('super_categories', 'categories.super_category_id', '=', 'super_categories.id')
                ->where('super_categories.restaurant_id', app(RestaurantContext::class)->id())->select('categories.id')
        ));
        static::saving(function (MenuItem $item) {
            if ($item->exists) {
                abort_unless(static::withTrashed()->whereKey($item->getKey())->exists(), 403);
            }
            abort_unless(Category::whereKey($item->category_id)->lockForUpdate()->first(), 403);
        });
        static::deleting(fn (MenuItem $item) => abort_unless(static::withTrashed()->whereKey($item->getKey())->exists(), 403));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuItemIngredient::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(MenuAuditLog::class);
    }
}
