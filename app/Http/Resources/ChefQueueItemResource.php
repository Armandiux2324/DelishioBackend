<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChefQueueItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_id' => $this->order_id, 'menu_item_id' => $this->menu_item_id,
            'quantity' => $this->quantity, 'modifiers' => $this->modifiers, 'kitchen_notes' => $this->kitchen_notes,
            'station' => $this->station, 'status' => $this->status, 'created_at' => $this->created_at,
            'menu_item' => $this->whenLoaded('menuItem', fn () => $this->menuItem?->only(['id', 'name'])),
            'order' => $this->whenLoaded('order', fn () => $this->order->only(['id', 'service_type', 'table_number', 'created_at', 'sent_at']))];
    }
}
