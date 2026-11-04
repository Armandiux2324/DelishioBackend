<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return ['waiter_employee_id' => fn () => Employee::where('role', 'waiter')->firstOrFail()->user_id,
            'source' => 'pos', 'service_type' => 'takeout', 'cash_register_id' => CashRegister::factory()];
    }
}
