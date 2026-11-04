<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\SuperCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'super_category_id' => SuperCategory::factory(),
            'description' => null,
        ];
    }
}
