<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\OrderItemReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_item_id', 'recorded_by_employee_id', 'quantity', 'amount', 'tax_amount', 'reason', 'request_key', 'returned_at'])]
class OrderItemReturn extends Model
{
    /** @use HasFactory<OrderItemReturnFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'returned_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('item'));
        static::creating(function (OrderItemReturn $return) {
            abort_unless(OrderItem::whereKey($return->order_item_id)->where('cancelled', false)
                ->whereHas('order', fn (Builder $order) => $order->completedSales())
                ->exists(), 403);
            abort_unless(Employee::active()->whereKey($return->recorded_by_employee_id)->where('role', 'admin')->exists(), 403);
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
