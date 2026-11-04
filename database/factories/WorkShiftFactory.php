<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkShift> */
class WorkShiftFactory extends Factory
{
    public function definition(): array
    {
        return ['employee_id' => Employee::factory(), 'clock_in' => now()];
    }
}
