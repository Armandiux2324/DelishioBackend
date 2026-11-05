<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\LoyaltyTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'restaurant_id', 'order_id', 'type', 'points'])]
class LoyaltyTransaction extends Model
{
    /** @use HasFactory<LoyaltyTransactionFactory> */
    use BelongsToRestaurant, HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['points' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (LoyaltyTransaction $transaction) {
            abort_unless(Customer::whereKey($transaction->customer_id)->whereHas('user', fn ($query) => $query->where('user_type', 'customer'))->exists(), 403);
            if ($transaction->order_id !== null) {
                abort_unless(Order::whereKey($transaction->order_id)->where('customer_id', $transaction->customer_id)->exists(), 403);
            }
        });
        static::updating(fn () => abort(403));
        static::deleting(fn () => abort(403));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
