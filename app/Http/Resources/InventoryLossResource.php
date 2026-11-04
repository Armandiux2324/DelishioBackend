<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLossResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'quantity' => $this->quantity,
            'reason' => $this->reason,
            'registered_by_employee_id' => $this->registered_by_employee_id,
            'created_at' => $this->created_at,
        ];
    }
}
