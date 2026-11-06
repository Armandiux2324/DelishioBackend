<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ExternalPlatformSale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalPlatformSale> */
class ExternalPlatformSaleFactory extends Factory
{
    public function definition(): array
    {
        return ['platform' => 'doordash', 'total_amount' => '100.00', 'commission_amount' => '15.00',
            'registered_by_employee_id' => fn () => Employee::factory()->create(['role' => 'admin'])->user_id,
            'sale_date' => now()];
    }
}
