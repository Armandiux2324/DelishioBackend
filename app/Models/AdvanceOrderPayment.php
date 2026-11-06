<?php

namespace App\Models;

use App\Models\Builders\RestaurantBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'payment_intent_id', 'status', 'refund_attempts', 'last_error', 'authorized_at', 'captured_at',
    'stripe_account_id', 'currency', 'amount', 'points_to_earn', 'request_key', 'request_hash',
    'refund_reference', 'refunded_at', 'cancellation_requested_at', 'cancellation_notified_at'])]
class AdvanceOrderPayment extends Model
{
    protected $attributes = ['status' => 'pending', 'refund_attempts' => 0];

    protected function casts(): array
    {
        return ['refund_attempts' => 'integer', 'amount' => 'decimal:2', 'points_to_earn' => 'decimal:2',
            'authorized_at' => 'datetime', 'captured_at' => 'datetime', 'refunded_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime', 'cancellation_requested_at' => 'datetime', 'cancellation_notified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('restaurant', fn (Builder $query) => $query->whereHas('order'));
        static::saving(function (AdvanceOrderPayment $payment) {
            abort_unless(Order::whereKey($payment->order_id)->where('source', 'customer_app')->exists(), 403);
            abort_unless(in_array($payment->status, ['pending', 'authorized', 'captured', 'voided', 'refund_pending', 'refunded', 'refund_failed'], true), 422);
            if ($payment->exists) {
                abort_if($payment->isDirty(['order_id', 'stripe_account_id', 'currency', 'amount', 'points_to_earn', 'request_key', 'request_hash']), 403);
                abort_if($payment->getRawOriginal('payment_intent_id') !== null && $payment->isDirty('payment_intent_id'), 403);
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
