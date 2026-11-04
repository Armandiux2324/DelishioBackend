<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['promotion_id', 'category_id'])]
class PromotionCategory extends Pivot
{
    protected $table = 'promotion_categories';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('promotion'));
        static::saving(function (PromotionCategory $link) {
            abort_if($link->exists && $link->isDirty(['promotion_id', 'category_id']), 403);
            abort_unless(Promotion::whereKey($link->promotion_id)->lockForUpdate()->first(), 403);
            abort_unless(Category::whereKey($link->category_id)->lockForUpdate()->first(), 403);
        });
        static::deleting(fn (PromotionCategory $link) => abort_unless(
            Promotion::withTrashed()->whereKey($link->getRawOriginal('promotion_id'))->lockForUpdate()->first(), 403
        ));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }
}
