<?php

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'condition_type' => 'min_amount', 'condition_value' => '100.00',
            'discount_type' => 'percentage', 'discount_value' => '10.00', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'active' => true];
    }
}
