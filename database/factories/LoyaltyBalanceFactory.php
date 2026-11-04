<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LoyaltyBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyBalance> */
class LoyaltyBalanceFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'points_balance' => '0.00'];
    }
}
