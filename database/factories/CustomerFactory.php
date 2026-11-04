<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->state(['user_type' => 'customer']), 'phone' => '5551234567'];
    }
}
