<?php

namespace Database\Factories;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashMovement> */
class CashMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['cash_register_id' => CashRegister::factory(), 'type' => 'cash_out', 'amount' => '10.00', 'reason' => 'other',
            'registered_by_employee_id' => fn () => Employee::where('role', 'admin')->firstOrFail()->user_id];
    }
}
