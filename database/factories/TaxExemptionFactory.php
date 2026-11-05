<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\TaxExemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxExemption> */
class TaxExemptionFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'reason' => 'Exención documentada',
            'requested_by_employee_id' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->waiter_employee_id];
    }
}
