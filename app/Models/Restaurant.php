<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    'created_by_user_id',
])]

class Restaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'loyalty_enabled' => 'boolean',
            'dual_pricing_enabled' => 'boolean',
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