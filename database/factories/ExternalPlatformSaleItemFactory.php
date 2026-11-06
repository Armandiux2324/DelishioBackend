<?php

namespace Database\Factories;

use App\Models\ExternalPlatformSale;
use App\Models\ExternalPlatformSaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalPlatformSaleItem> */
class ExternalPlatformSaleItemFactory extends Factory
{
    public function definition(): array
    {
        return ['external_sale_id' => ExternalPlatformSale::factory(), 'menu_item_id' => null,
            'description' => fake()->words(3, true), 'quantity' => 1, 'unit_price' => '100.00'];
    }
}
