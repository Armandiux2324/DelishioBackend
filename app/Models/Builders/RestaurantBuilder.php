<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class RestaurantBuilder extends Builder
{
    public function update(array $values): int
    {
        foreach (array_keys($values) as $column) {
            if (str($column)->afterLast('.')->toString() === 'restaurant_id') {
                throw new LogicException('restaurant_id cannot be reassigned.');
            }
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException('Use model saves for restaurant-owned records.');
    }
}
