<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'menu_item_id', 'quantity', 'unit_price', 'modifiers', 'kitchen_notes', 'station', 'status', 'cancelled', 'cancel_reason', 'cancelled_by_employee_id', 'cancel_authorized_by_employee_id'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $attributes = ['status' => 'pending', 'cancelled' => false];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'cancelled' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::saving(function (OrderItem $item) {
            $order = Order::whereKey($item->getRawOriginal('order_id') ?? $item->order_id)->first();
            abort_unless($order, 403);
            if ($item->exists) {
                abort_if($item->isDirty(['order_id', 'menu_item_id', 'unit_price', 'station']), 403);
                if ($item->isDirty(['quantity', 'modifiers', 'kitchen_notes'])) {
                    abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));
                }
            } else {
                abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));
                abort_unless(MenuItem::whereKey($item->menu_item_id)->where('active', true)->exists(), 403);
            }
        });
        static::deleting(function (OrderItem $item) {
            $order = Order::whereKey($item->getRawOriginal('order_id'))->first();
            abort_unless($order, 403);
            abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));
            abort_if(InventoryMovement::where('order_item_id', $item->id)->exists(), 409);
        });
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }
}
