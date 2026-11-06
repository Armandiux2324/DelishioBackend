<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_id' => $this->order_id, 'method' => $this->method,
            'amount' => $this->amount, 'surcharge_amount' => $this->surcharge_amount, 'created_at' => $this->created_at];
    }
}
