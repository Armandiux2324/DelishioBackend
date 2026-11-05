<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'waiter_employee_id' => $this->waiter_employee_id,
            'customer_id' => $this->customer_id,
            'source' => $this->source,
            'service_type' => $this->service_type,
            'table_number' => $this->table_number,
            'delivery_address' => $this->delivery_address,
            'delivery_fee' => $this->delivery_fee,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_total' => $this->discount_total,
            'dual_pricing_applied' => $this->dual_pricing_applied,
            'tip_amount' => $this->tip_amount,
            'total' => $this->total,
            'cash_register_id' => $this->cash_register_id,
            'created_at' => $this->created_at,
            'sent_at' => $this->sent_at,
            'closed_at' => $this->closed_at,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'discounts' => OrderDiscountResource::collection($this->whenLoaded('discounts')),
            'tax_exemptions' => TaxExemptionResource::collection($this->whenLoaded('taxExemptions')),
        ];
    }
}
