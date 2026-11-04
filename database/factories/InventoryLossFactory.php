<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryLoss;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLoss>
 */
class InventoryLossFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'quantity' => '1.000',
            'reason' => 'Merma de prueba',
            'registered_by_employee_id' => fn () => Employee::where('role', 'admin')->firstOrFail()->user_id,
        ];
    }
}
