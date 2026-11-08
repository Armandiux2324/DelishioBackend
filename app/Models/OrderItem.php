<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'menu_item_id', 'quantity', 'unit_price', 'modifiers', 'kitchen_notes', 'station', 'status', 'cancelled', 'cancel_reason', 'cancelled_by_employee_id', 'cancel_authorized_by_employee_id', 'cancelled_at'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $attributes = ['status' => 'pending', 'cancelled' => false];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'cancelled' => 'boolean', 'created_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::saving(function (OrderItem $item) {
            $order = Order::whereKey($item->getRawOriginal('order_id') ?? $item->order_id)->first();
            abort_unless($order, 403);
            if ($item->exists) {
                if ($item->getRawOriginal('cancelled')) {
                    abort_if($item->isDirty(['cancelled', 'cancel_reason', 'cancelled_by_employee_id', 'cancel_authorized_by_employee_id', 'cancelled_at']), 403);
                }
                abort_if($item->isDirty(['order_id', 'menu_item_id', 'unit_price', 'station']), 403);
                if ($item->isDirty(['quantity', 'modifiers', 'kitchen_notes'])) {
                    abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));
                }
            } else {
                abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));
                abort_unless(MenuItem::whereKey($item->menu_item_id)->where('active', true)->exists(), 403);
            }
            if ($item->isDirty('cancelled_by_employee_id') && $item->cancelled_by_employee_id !== null) {
                abort_unless(Employee::whereKey($item->cancelled_by_employee_id)->exists(), 403);
            }
            if ($item->isDirty('cancel_authorized_by_employee_id') && $item->cancel_authorized_by_employee_id !== null) {
                abort_unless(Employee::active()->whereKey($item->cancel_authorized_by_employee_id)->where('role', 'admin')->exists(), 403);
            }
        });
        static::deleting(function (OrderItem $item) {
            $order = Order::whereKey($item->getRawOriginal('order_id'))->first();
            abort_unless($order, 403);
            abort_if($order->status !== 'open' || $order->sent_at !== null, 409, __('La orden ya no permite editar elementos libremente.'));
            abort_if(InventoryMovement::where('order_item_id', $item->id)->exists(), 409);
            abort_if($item->discounts()->exists() || $item->taxExemptions()->exists(), 409, __('El elemento conserva ajustes auditados y debe cancelarse.'));
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

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function taxExemptions(): HasMany
    {
        return $this->hasMany(TaxExemption::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }
}
