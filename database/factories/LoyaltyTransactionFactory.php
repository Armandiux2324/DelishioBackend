<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyTransaction> */
class LoyaltyTransactionFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'order_id' => null, 'type' => 'earn', 'points' => '10.00'];
    }
}
