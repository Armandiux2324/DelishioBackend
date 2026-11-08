<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $preparation = 'pending';
        if ($this->relationLoaded('items') && $this->sent_at !== null) {
            $preparing = $this->items->where('cancelled', false)->whereIn('station', ['kitchen', 'bar']);
            $preparation = $preparing->isEmpty() || $preparing->every(fn ($item) => $item->status === 'ready') ? 'ready'
                : ($preparing->contains(fn ($item) => $item->status !== 'pending') ? 'in_preparation' : 'pending');
        }

        return ['id' => $this->id, 'restaurant_id' => $this->restaurant_id, 'source' => $this->source,
            'service_type' => $this->service_type, 'status' => $this->status, 'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total, 'discount_total' => $this->discount_total, 'total' => $this->total,
            'loyalty_redeemed_amount' => $this->loyalty_redeemed_amount, 'surcharge_total' => $this->surcharge_total, 'created_at' => $this->created_at,
            'currency' => $this->whenLoaded('advancePayment', fn () => $this->advancePayment?->currency),
            'sent_at' => $this->sent_at, 'closed_at' => $this->closed_at,
            'cancel_reason' => $this->when($this->status === 'cancelled', fn () => __($this->cancel_reason ?? 'El pedido fue cancelado.')),
            'preparation_status' => $this->whenLoaded('items', fn () => $preparation),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id, 'menu_item_id' => $item->menu_item_id, 'name' => $item->menuItem?->name,
                'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'modifiers' => $item->modifiers,
                'kitchen_notes' => $item->kitchen_notes, 'status' => $item->status, 'cancelled' => $item->cancelled])),
            'advance_payment' => $this->whenLoaded('advancePayment', fn () => $this->advancePayment?->only([
                'status', 'authorized_at', 'captured_at', 'currency', 'refund_attempts', 'refunded_at'])),
        ];
    }
}
