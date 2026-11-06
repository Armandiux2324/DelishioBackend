<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExternalPlatformSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'platform' => $this->platform, 'total_amount' => $this->total_amount,
            'commission_amount' => $this->commission_amount, 'net_amount' => $this->net_amount,
            'registered_by_employee_id' => $this->registered_by_employee_id, 'sale_date' => $this->sale_date,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id, 'external_sale_id' => $item->external_sale_id, 'menu_item_id' => $item->menu_item_id,
                'description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price,
            ])),
        ];
    }
}
