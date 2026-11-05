<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_id' => $this->order_id, 'customer_id' => $this->customer_id,
            'type' => $this->type, 'points' => $this->points, 'created_at' => $this->created_at];
    }
}
