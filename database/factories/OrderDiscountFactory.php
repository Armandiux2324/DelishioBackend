<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\WorkShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderDiscount> */
class OrderDiscountFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'kind' => 'discount', 'amount' => '1.00',
            'requested_by_employee_id' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->waiter_employee_id,
            'authorized_by_employee_id' => fn () => Employee::where('role', 'admin')->firstOrFail()->user_id,
            'work_shift_id' => fn (array $attributes) => WorkShift::factory()->create(['employee_id' => $attributes['requested_by_employee_id']])->id];
    }
}
