<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'kind' => $this->kind,
            'percentage' => $this->percentage,
            'amount' => $this->amount,
            'requested_by_employee_id' => $this->requested_by_employee_id,
            'authorized_by_employee_id' => $this->authorized_by_employee_id,
            'work_shift_id' => $this->work_shift_id,
            'created_at' => $this->created_at,
        ];
    }
}
