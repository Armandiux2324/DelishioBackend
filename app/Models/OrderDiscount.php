<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Brick\Math\BigDecimal;
use Database\Factories\OrderDiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['order_id', 'order_item_id', 'kind', 'percentage', 'amount', 'requested_by_employee_id', 'authorized_by_employee_id', 'work_shift_id'])]
class OrderDiscount extends Model
{
    /** @use HasFactory<OrderDiscountFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'amount' => 'decimal:2', 'order_item_id' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::creating(function (OrderDiscount $discount) {
            if ($discount->amount === null || BigDecimal::of($discount->amount)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['amount' => __('El descuento debe ser positivo y no superar el subtotal disponible.')]);
            }
            abort_unless(Order::whereKey($discount->order_id)->where('waiter_employee_id', $discount->requested_by_employee_id)->exists(), 403);
            abort_unless(Employee::active()->whereKey($discount->requested_by_employee_id)->where('role', 'waiter')->exists(), 403);
            abort_unless(Employee::active()->whereKey($discount->authorized_by_employee_id)->where('role', 'admin')->exists(), 403);
            abort_unless(WorkShift::whereKey($discount->work_shift_id)->where('employee_id', $discount->requested_by_employee_id)->exists(), 403);
            if ($discount->order_item_id !== null) {
                abort_unless(OrderItem::whereKey($discount->order_item_id)->where('order_id', $discount->order_id)->where('cancelled', false)->exists(), 403);
            }
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public function newEloquentBuilder($query): RestaurantBuilder
    {
        return new RestaurantBuilder($query);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
