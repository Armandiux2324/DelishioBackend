<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
class InventoryMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['inventory_item_id' => InventoryItem::factory(), 'order_item_id' => OrderItem::factory(), 'quantity' => '-0.125'];
    }
}
