<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'payment_confirmed', 'payment_reference', 'paid_at', 'stripe_account_id', 'currency', 'amount',
    'points_to_earn', 'request_key', 'request_hash', 'refund_pending', 'refund_reference', 'refunded_at'])]
class AdvanceOrderPayment extends Model
{
    public const UPDATED_AT = null;

    protected $attributes = ['payment_confirmed' => false, 'refund_pending' => false];

    protected function casts(): array
    {
        return ['payment_confirmed' => 'boolean', 'refund_pending' => 'boolean', 'amount' => 'decimal:2',
            'points_to_earn' => 'decimal:2', 'paid_at' => 'datetime', 'refunded_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::saving(function (AdvanceOrderPayment $payment) {
            abort_unless(Order::whereKey($payment->order_id)->where('source', 'customer_app')->exists(), 403);
            if ($payment->exists) {
                abort_if($payment->isDirty(['order_id', 'stripe_account_id', 'currency', 'amount', 'points_to_earn', 'request_key', 'request_hash']), 403);
                abort_if($payment->getRawOriginal('payment_reference') !== null && $payment->isDirty('payment_reference'), 403);
            }
        });
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
