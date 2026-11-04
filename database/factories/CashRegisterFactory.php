<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashRegister> */
class CashRegisterFactory extends Factory
{
    public function definition(): array
    {
        return ['opened_by_employee_id' => fn () => Employee::where('role', 'admin')->firstOrFail()->user_id, 'opening_amount' => '100.00', 'opened_at' => now()];
    }
}
