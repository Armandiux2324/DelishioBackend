<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cash_register_id' => $this->cash_register_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'related_employee_id' => $this->related_employee_id,
            'payroll_period_start' => $this->payroll_period_start,
            'payroll_period_end' => $this->payroll_period_end,
            'registered_by_employee_id' => $this->registered_by_employee_id,
            'created_at' => $this->created_at,
        ];
    }
}
