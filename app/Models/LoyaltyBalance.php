<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\LoyaltyBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'restaurant_id', 'points_balance'])]
class LoyaltyBalance extends Model
{
    /** @use HasFactory<LoyaltyBalanceFactory> */
    use BelongsToRestaurant, HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['points_balance' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (LoyaltyBalance $balance) {
            abort_if($balance->exists && $balance->isDirty('customer_id'), 403);
            abort_unless(Customer::whereKey($balance->customer_id)->whereHas('user', fn ($query) => $query->where('user_type', 'customer'))->exists(), 403);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }
}
