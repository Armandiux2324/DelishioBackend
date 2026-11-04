<?php

namespace Database\Factories;

use App\Models\SuperCategory;
use App\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuperCategory>
 */
class SuperCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'tax_class_id' => TaxClass::factory(),
        ];
    }
}
