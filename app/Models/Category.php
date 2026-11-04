<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['super_category_id', 'name', 'description'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, SoftDeletes;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('superCategory'));

        static::saving(function (Category $category) {
            if ($category->exists) {
                abort_unless(SuperCategory::whereKey($category->getRawOriginal('super_category_id'))->exists(), 403);
            }
            abort_unless(SuperCategory::whereKey($category->super_category_id)->lockForUpdate()->first(), 403);
        });

        static::deleting(function (Category $category) {
            abort_unless(SuperCategory::whereKey($category->getRawOriginal('super_category_id'))->exists(), 403);
            abort_if(MenuItem::where('category_id', $category->id)->exists(), 409, __('La categoría tiene elementos de menú sin eliminar.'));
            abort_if(PromotionCategory::where('category_id', $category->id)->whereHas('promotion', fn ($query) => $query->withoutTrashed())->exists(),
                409, __('La categoría forma parte de una promoción sin eliminar.'));
        });
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function superCategory(): BelongsTo
    {
        return $this->belongsTo(SuperCategory::class);
    }
}
