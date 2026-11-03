<?php

namespace App\Models\Concerns;

use App\Models\Builders\RestaurantBuilder;
use App\Support\RestaurantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToRestaurant
{
    public static function bootBelongsToRestaurant(): void
    {
        static::addGlobalScope('restaurant', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('restaurant_id'), app(RestaurantContext::class)->id());
        });

        static::saving(function (Model $model) {
            $restaurantId = app(RestaurantContext::class)->id();
            abort_if($model->exists && (int) $model->getRawOriginal('restaurant_id') !== $restaurantId, 403);
            abort_if($model->restaurant_id !== null && (int) $model->restaurant_id !== $restaurantId, 403);
            $model->restaurant_id = $restaurantId;
        });

        static::deleting(function (Model $model) {
            abort_unless((int) $model->getRawOriginal('restaurant_id') === app(RestaurantContext::class)->id(), 403);
        });
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }
}
