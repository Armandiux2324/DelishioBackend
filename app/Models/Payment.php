<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'method', 'amount'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::creating(fn (Payment $payment) => abort_unless(Order::whereKey($payment->order_id)->exists(), 403));
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
