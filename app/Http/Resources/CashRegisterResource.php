<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'opened_by_employee_id' => $this->opened_by_employee_id,
            'opening_amount' => $this->opening_amount,
            'opened_at' => $this->opened_at,
            'closed_by_employee_id' => $this->closed_by_employee_id,
            'counted_cash' => $this->counted_cash,
            'expected_cash' => $this->expected_cash,
            'difference' => $this->difference,
            'closed_at' => $this->closed_at,
        ];
    }
}
