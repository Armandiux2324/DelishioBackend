<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'status' => $this->status,
            'service_type' => $this->service_type,
            'table_number' => $this->table_number,
            'waiter_employee_id' => $this->waiter_employee_id,
            'cash_register_id' => $this->cash_register_id,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'loyalty_redeemed_amount' => $this->loyalty_redeemed_amount,
            'tax_total' => $this->tax_total,
            'delivery_fee' => $this->delivery_fee,
            'surcharge_total' => $this->surcharge_total,
            'tip_amount' => $this->tip_amount,
            'total' => $this->total,
            'advance_payment_status' => $this->whenLoaded('advancePayment', fn () => $this->advancePayment->status),
            'created_at' => $this->created_at,
            'sent_at' => $this->sent_at,
            'closed_at' => $this->closed_at,
        ];
    }
}
