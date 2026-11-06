<?php

namespace App\Http\Resources;

use Brick\Math\BigDecimal;
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
            'dual_pricing_applied' => $this->dual_pricing_applied, 'created_at' => $this->created_at,
            'dual_pricing_adjustment' => (string) BigDecimal::of($this->total)->minus($this->subtotal)->plus($this->discount_total)->minus($this->tax_total),
            'currency' => $this->whenLoaded('advancePayment', fn () => $this->advancePayment?->currency),
            'sent_at' => $this->sent_at, 'closed_at' => $this->closed_at,
            'preparation_status' => $this->whenLoaded('items', fn () => $preparation),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id, 'menu_item_id' => $item->menu_item_id, 'name' => $item->menuItem?->name,
                'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'modifiers' => $item->modifiers,
                'kitchen_notes' => $item->kitchen_notes, 'status' => $item->status, 'cancelled' => $item->cancelled])),
            'advance_payment' => $this->whenLoaded('advancePayment', fn () => $this->advancePayment?->only([
                'payment_confirmed', 'paid_at', 'currency', 'refund_pending', 'refunded_at'])),
        ];
    }
}
