<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $balance = $this->loyaltyBalances->sole();

        return [
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'active' => $this->user->active,
            'phone' => $this->phone,
            'restaurant_id' => $balance->restaurant_id,
            'points_balance' => $balance->points_balance,
        ];
    }
}
