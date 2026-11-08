<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OrderItemReturn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrderItemReturn> */
class OrderItemReturnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recorded_by_employee_id' => fn () => Employee::where('role', 'admin')->firstOrFail()->user_id,
            'quantity' => 1,
            'amount' => '1.00',
            'tax_amount' => '0.00',
            'reason' => 'Devolución de prueba',
            'request_key' => (string) Str::uuid(),
            'returned_at' => now(),
        ];
    }
}
