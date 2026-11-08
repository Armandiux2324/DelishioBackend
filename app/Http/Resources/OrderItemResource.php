<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'order_id' => $this->order_id, 'menu_item_id' => $this->menu_item_id,
            'quantity' => $this->quantity, 'unit_price' => $this->unit_price, 'modifiers' => $this->modifiers,
            'kitchen_notes' => $this->kitchen_notes, 'station' => $this->station, 'status' => $this->status,
            'cancelled' => $this->cancelled, 'cancel_reason' => $this->cancel_reason,
            'cancelled_by_employee_id' => $this->cancelled_by_employee_id, 'cancel_authorized_by_employee_id' => $this->cancel_authorized_by_employee_id,
            'created_at' => $this->created_at,
            'quantity_returned' => $this->whenHas('quantity_returned', fn () => (int) $this->quantity_returned),
            'menu_item' => $this->whenLoaded('menuItem', fn () => $this->menuItem?->only(['id', 'name', 'legal_age_required'])),
        ];
    }
}
