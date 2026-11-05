<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\TaxExemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'order_item_id', 'reason', 'requested_by_employee_id'])]
class TaxExemption extends Model
{
    /** @use HasFactory<TaxExemptionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['order_item_id' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::creating(function (TaxExemption $exemption) {
            abort_unless(Order::whereKey($exemption->order_id)->where('waiter_employee_id', $exemption->requested_by_employee_id)->exists(), 403);
            abort_unless(Employee::active()->whereKey($exemption->requested_by_employee_id)->where('role', 'waiter')->exists(), 403);
            if ($exemption->order_item_id !== null) {
                abort_unless(OrderItem::whereKey($exemption->order_item_id)->where('order_id', $exemption->order_id)->where('cancelled', false)->exists(), 403);
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
