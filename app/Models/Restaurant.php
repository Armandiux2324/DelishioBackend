<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'description',
    'address',
    'phone',
    'logo_url',
    'schedule',
    'currency',
    'status',
    'loyalty_enabled',
    'loyalty_factor',
    'loyalty_redeem_value',
    'discount_limit_per_shift',
    'courtesy_percentage',
    'courtesy_daily_limit',
    'dual_pricing_enabled',
    'dual_pricing_percentage',
    'timezone',
    'overtime_weekly_hours',
    'overtime_multiplier',
    'created_by_user_id',
    'stripe_account_id',
])]
#[Hidden(['stripe_account_id'])]

class Restaurant extends Model
{
    use HasFactory, SoftDeletes;

    public const MAX_DUAL_PRICING_PERCENTAGE = 100;

    protected function casts(): array
    {
        return [
            'loyalty_enabled' => 'boolean',
            'dual_pricing_enabled' => 'boolean',
            'loyalty_factor' => 'decimal:4',
            'loyalty_redeem_value' => 'decimal:4',
            'discount_limit_per_shift' => 'decimal:2',
            'courtesy_percentage' => 'decimal:2',
            'courtesy_daily_limit' => 'integer',
            'dual_pricing_percentage' => 'decimal:2',
            'overtime_weekly_hours' => 'decimal:2',
            'overtime_multiplier' => 'decimal:2',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
