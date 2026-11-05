<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxExemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'reason' => $this->reason,
            'requested_by_employee_id' => $this->requested_by_employee_id,
            'created_at' => $this->created_at,
        ];
    }
}
