<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['user_type' => 'employee']),
            'role' => 'waiter',
            'station' => null,
            'hourly_rate' => '100.00',
            'pin' => '123456',
        ];
    }
}
